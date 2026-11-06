<?php

namespace App\Modules\Catalog\Stock;

use App\Http\Errors\ApiException;
use App\Modules\Catalog\Models\BranchProductAvailability;
use App\Modules\Catalog\Models\Option;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductStock;
use App\Modules\Catalog\Models\StockMovement;
use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Models\Cart;
use App\Modules\Ordering\Models\Order;
use App\Modules\Platform\Features\Feature;
use App\Modules\Platform\Features\FeatureGate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Estoque simples (D97): saldo por produto e unidade.
 *
 * - Só controla o produto que tem linha em product_stocks naquela unidade.
 * - Baixa na criação do pedido (site ou PDV), dentro da mesma transação:
 *   sem saldo, o pedido não nasce (422 out_of_stock).
 * - Item de combo conta para o produto escolhido no espaço do combo.
 * - Cancelado/recusado: devolve o que saiu (uma vez só).
 * - Saldo zerado marca "Esgotado" sozinho; ao repor, volta a vender
 *   (só se quem esgotou foi o estoque, não o atendente).
 */
final class StockService
{
    /**
     * Quanto de cada produto o carrinho consome.
     *
     * @return array<int, int> product_id => quantidade
     */
    public static function demandForCart(Cart $cart): array
    {
        $cart->loadMissing('items');
        $demand = [];
        foreach ($cart->items as $item) {
            foreach (self::demandFor($item->product_id, $item->selections_json, $item->quantity) as $id => $qty) {
                $demand[$id] = ($demand[$id] ?? 0) + $qty;
            }
        }

        return $demand;
    }

    /**
     * Produto + itens de combo escolhidos (opções ligadas a produtos).
     *
     * @param  list<array<string, mixed>>  $selections  [{option: public_id, quantity}]
     * @return array<int, int>
     */
    public static function demandFor(int $productId, array $selections, int $quantity): array
    {
        $demand = [$productId => $quantity];
        $ids = array_values(array_filter(array_map(fn ($s) => (string) ($s['option'] ?? ''), $selections)));
        if ($ids === []) {
            return $demand;
        }
        $linked = Option::query()->whereIn('public_id', array_unique($ids))->whereNotNull('product_id')->pluck('product_id', 'public_id');
        foreach ($selections as $s) {
            $linkedId = $linked[(string) ($s['option'] ?? '')] ?? null;
            if ($linkedId !== null) {
                $demand[(int) $linkedId] = ($demand[(int) $linkedId] ?? 0) + max(1, (int) ($s['quantity'] ?? 1)) * $quantity;
            }
        }

        return $demand;
    }

    /**
     * Baixa (chamar dentro da transação do pedido ou do item da comanda).
     *
     * @param  array<int, int>  $demand
     */
    public function consume(int $branchId, array $demand, ?User $user = null, ?Order $order = null, ?int $tabItemId = null, ?string $reason = null): void
    {
        // Sem o módulo Estoque, nada é controlado (saldo antigo fica parado).
        if ($demand === [] || ! app(FeatureGate::class)->enabled(Feature::STOCK)) {
            return;
        }
        $stocks = ProductStock::query()->where('branch_id', $branchId)->whereIn('product_id', array_keys($demand))
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($stocks as $stock) {
            $qty = $demand[$stock->product_id];
            if ($stock->quantity < $qty) {
                $name = Product::query()->withTrashed()->whereKey($stock->product_id)->value('name');
                throw new ApiException(422, 'out_of_stock', $stock->quantity > 0
                    ? "Só restam {$stock->quantity} de {$name}."
                    : "{$name} esgotou.", ['product' => $name, 'available' => max(0, $stock->quantity)]);
            }
            $this->move($stock, StockMovementType::Sale, -$qty, $user, $reason, $order, $tabItemId);
        }
    }

    /** Item da comanda removido: devolve o que ele baixou (uma vez só). */
    public function restoreForTabItem(int $branchId, int $tabItemId, ?User $user = null): void
    {
        $this->restoreWhere($branchId, StockMovement::query()->where('tab_item_id', $tabItemId), $user, null, $tabItemId);
    }

    /** Devolve a baixa de um pedido cancelado/recusado (idempotente). */
    public function restoreFor(Order $order, ?User $user = null): void
    {
        $this->restoreWhere($order->branch_id, StockMovement::query()->where('order_id', $order->id), $user, $order, null);
    }

    /**
     * @param  Builder<StockMovement>  $query
     */
    private function restoreWhere(int $branchId, $query, ?User $user, ?Order $order, ?int $tabItemId): void
    {
        $moves = $query->get(['product_id', 'type', 'quantity']);
        if ($moves->isEmpty() || $moves->contains(fn (StockMovement $m) => $m->type === StockMovementType::Cancel)) {
            return;
        }
        $back = [];
        foreach ($moves as $m) {
            if ($m->type === StockMovementType::Sale) {
                $back[$m->product_id] = ($back[$m->product_id] ?? 0) - $m->quantity;
            }
        }
        $stocks = ProductStock::query()->where('branch_id', $branchId)->whereIn('product_id', array_keys($back))
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($stocks as $stock) {
            $this->move($stock, StockMovementType::Cancel, $back[$stock->product_id], $user, null, $order, $tabItemId);
        }
    }

    /**
     * Ajuste manual: entrada (+), perda (−) ou contagem (saldo absoluto).
     */
    public function adjust(ProductStock $stock, StockMovementType $type, int $quantity, ?User $user, ?string $reason): ProductStock
    {
        /** @var ProductStock $locked */
        $locked = ProductStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
        $delta = match ($type) {
            StockMovementType::Entry => $quantity,
            StockMovementType::Loss => -min($quantity, max(0, $locked->quantity)),
            StockMovementType::Count => $quantity - $locked->quantity,
            default => throw new \InvalidArgumentException('Ajuste inválido.'),
        };
        if ($type === StockMovementType::Loss && $quantity > $locked->quantity) {
            throw new ApiException(422, 'validation_failed', "A perda é maior que o saldo ({$locked->quantity}).", ['fields' => ['quantity' => ["Saldo atual: {$locked->quantity}."]]]);
        }
        $this->move($locked, $type, $delta, $user, $reason, null, null);

        return $locked;
    }

    private function move(ProductStock $stock, StockMovementType $type, int $delta, ?User $user, ?string $reason, ?Order $order, ?int $tabItemId): void
    {
        $stock->quantity += $delta;
        $wasPaused = $stock->auto_paused;
        if ($stock->quantity <= 0 && ! $wasPaused) {
            $availability = BranchProductAvailability::query()->firstOrNew(['branch_id' => $stock->branch_id, 'product_id' => $stock->product_id]);
            // Já estava esgotado à mão: não é o estoque que vai liberar depois.
            if ($availability->exists === false || $availability->available) {
                $availability->fill(['available' => false])->save();
                $stock->auto_paused = true;
            }
        } elseif ($stock->quantity > 0 && $wasPaused) {
            BranchProductAvailability::query()->where('branch_id', $stock->branch_id)->where('product_id', $stock->product_id)->update(['available' => true]);
            $stock->auto_paused = false;
        }
        $stock->save();

        StockMovement::query()->create([
            'branch_id' => $stock->branch_id,
            'product_id' => $stock->product_id,
            'type' => $type,
            'quantity' => $delta,
            'balance_after' => $stock->quantity,
            'order_id' => $order?->id,
            'tab_item_id' => $tabItemId,
            'user_id' => $user?->id,
            'reason' => $reason,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }
}
