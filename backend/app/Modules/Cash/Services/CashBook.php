<?php

namespace App\Modules\Cash\Services;

use App\Http\Errors\ApiException;
use App\Modules\Cash\Enums\CashCategory;
use App\Modules\Cash\Enums\CashMethod;
use App\Modules\Cash\Models\CashEntry;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Models\Refund;
use App\Modules\Tenancy\Models\Branch;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Livro-caixa por unidade.
 * - O caixa do dia é aberto com um valor em dinheiro e fechado com a
 *   contagem; a diferença (sobra/falta) fica registrada.
 * - Pedidos concluídos e estornos entram sozinhos (uma vez só, pela "source").
 * - Lançamentos nunca são apagados: estorno com motivo.
 * - Só a forma "dinheiro" mexe no valor esperado da gaveta.
 */
final class CashBook
{
    public function current(Branch $branch): ?CashSession
    {
        return CashSession::query()->where('branch_id', $branch->id)->whereNull('closed_at')->latest('id')->first();
    }

    public function open(Branch $branch, int $openingCents, User $user): CashSession
    {
        return DB::transaction(function () use ($branch, $openingCents, $user) {
            // Trava a unidade: dois "abrir caixa" ao mesmo tempo viram um só.
            Branch::query()->whereKey($branch->id)->lockForUpdate()->first();
            if ($this->current($branch) !== null) {
                throw new ApiException(409, 'cash_already_open', 'O caixa desta unidade já está aberto.');
            }

            return CashSession::query()->create([
                'branch_id' => $branch->id,
                'opened_by' => $user->id,
                'opened_at' => now(),
                'opening_amount' => Money::format($openingCents),
            ]);
        });
    }

    public function close(CashSession $session, int $countedCents, ?string $notes, User $user): CashSession
    {
        return DB::transaction(function () use ($session, $countedCents, $notes, $user) {
            /** @var CashSession $locked */
            $locked = CashSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new ApiException(409, 'cash_already_closed', 'Este caixa já foi fechado.');
            }
            $locked->forceFill([
                'closed_by' => $user->id,
                'closed_at' => now(),
                'expected_amount' => Money::format($this->expectedCents($locked)),
                'counted_amount' => Money::format($countedCents),
                'notes' => $notes,
            ])->save();

            return $locked;
        });
    }

    /** Dinheiro que deveria estar na gaveta: abertura + entradas − saídas em dinheiro. */
    public function expectedCents(CashSession $session): int
    {
        $rows = CashEntry::query()->valid()->where('cash_session_id', $session->id)->where('method', CashMethod::Cash)
            ->selectRaw('type, SUM(amount) AS s')->groupBy('type')->toBase()->pluck('s', 'type');

        return Money::toCents((string) $session->opening_amount)
            + self::cents($rows[CashEntry::IN] ?? 0)
            - self::cents($rows[CashEntry::OUT] ?? 0);
    }

    /**
     * @param  list<array{name: string, quantity: int, unit_price: string, total: string}>|null  $items
     */
    public function record(
        Branch $branch,
        string $type,
        CashCategory $category,
        CashMethod $method,
        int $amountCents,
        ?string $description,
        ?User $user,
        ?array $items = null,
        ?Order $order = null,
        ?string $source = null,
        ?CarbonImmutable $occurredAt = null,
    ): CashEntry {
        return CashEntry::query()->create([
            'branch_id' => $branch->id,
            'cash_session_id' => $this->current($branch)?->id,
            'type' => $type,
            'category' => $category,
            'method' => $method,
            'amount' => Money::format($amountCents),
            'description' => $description,
            'items_json' => $items,
            'order_id' => $order?->id,
            'source' => $source,
            'occurred_at' => $occurredAt ?? now(),
            'created_by' => $user?->id,
        ]);
    }

    /** Pedido concluído entra no caixa (uma única vez). */
    public function recordOrder(Order $order): void
    {
        // Venda do PDV já entrou no caixa na hora do pagamento (uma linha por forma).
        if ($order->isPos()) {
            return;
        }
        $source = 'order:'.$order->id;
        if (CashEntry::query()->where('source', $source)->exists()) {
            return;
        }
        $order->loadMissing('branch');
        try {
            $this->record(
                $order->branch, CashEntry::IN, CashCategory::Order, CashMethod::fromOrder($order->payment_method),
                Money::toCents((string) $order->total), 'Pedido nº '.$order->order_number, null,
                order: $order, source: $source, occurredAt: CarbonImmutable::instance($order->completed_at ?? now()),
            );
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'cash_entries_tenant_id_source_unique')) {
                throw $e;
            }
        }
    }

    /** Reembolso de pedido que já estava no caixa vira saída "Estorno de pedido". */
    public function recordRefund(Order $order, Refund $refund): void
    {
        $sale = CashEntry::query()->where('source', 'order:'.$order->id)->first();
        $source = 'refund:'.$refund->id;
        if ($sale === null || CashEntry::query()->where('source', $source)->exists()) {
            return;
        }
        $this->record(
            $sale->branch, CashEntry::OUT, CashCategory::Refund, $sale->method, Money::toCents((string) $refund->amount),
            'Estorno do pedido nº '.$order->order_number, null, order: $order, source: $source,
        );
    }

    /** Venda do PDV: cada forma de pagamento vira uma entrada no caixa aberto. */
    public function recordPosSale(Order $order, User $user): void
    {
        $order->loadMissing('branch', 'posPayments');
        foreach ($order->posPayments as $i => $payment) {
            $this->record(
                $order->branch, CashEntry::IN, CashCategory::CounterSale, $payment->method, Money::toCents((string) $payment->amount),
                'Venda nº '.$order->order_number, $user, order: $order, source: "pos:{$order->id}:{$i}",
            );
        }
    }

    /** Venda do PDV cancelada: devolve cada forma recebida (saída), uma vez só. */
    public function recordPosCancel(Order $order, ?User $user): void
    {
        $order->loadMissing('branch', 'posPayments');
        foreach ($order->posPayments as $i => $payment) {
            $source = "pos-cancel:{$order->id}:{$i}";
            if (CashEntry::query()->where('source', $source)->exists()) {
                continue;
            }
            $this->record(
                $order->branch, CashEntry::OUT, CashCategory::Refund, $payment->method, Money::toCents((string) $payment->amount),
                'Cancelamento da venda nº '.$order->order_number, $user, order: $order, source: $source,
            );
        }
    }

    /**
     * Venda de balcão. Itens do cardápio usam o preço do tamanho (servidor);
     * itens avulsos usam o valor digitado pelo próprio caixa.
     *
     * @param  list<array{product_id?: string|null, variant_id?: string|null, description?: string|null, unit_price?: string|null, quantity: int}>  $lines
     */
    public function sale(Branch $branch, array $lines, int $discountCents, CashMethod $method, User $user, ?string $note): CashEntry
    {
        $items = [];
        $total = 0;
        foreach ($lines as $i => $line) {
            $qty = (int) $line['quantity'];
            if (! empty($line['product_id'])) {
                $product = Product::query()->where('public_id', $line['product_id'])->where('active', true)->with('variants')->first();
                $variant = $product?->variants->first(fn ($v) => $v->active && (empty($line['variant_id']) || $v->public_id === $line['variant_id']));
                if ($product === null || $variant === null) {
                    throw new ApiException(422, 'sale_item_invalid', "Item {$i}: produto indisponível.", ['item' => $i]);
                }
                $unit = Money::toCents((string) $variant->price);
                $name = $product->name.($product->variants->count() > 1 ? ' ('.$variant->name.')' : '');
            } else {
                $unit = self::cents(str_replace(',', '.', (string) $line['unit_price']));
                $name = trim((string) $line['description']);
            }
            $lineTotal = $unit * $qty;
            $total += $lineTotal;
            $items[] = ['name' => $name, 'quantity' => $qty, 'unit_price' => Money::format($unit), 'total' => Money::format($lineTotal)];
        }
        if ($total <= 0) {
            throw new ApiException(422, 'sale_empty', 'A venda precisa de ao menos um item com valor.');
        }
        if ($discountCents < 0 || $discountCents > $total) {
            throw new ApiException(422, 'sale_discount_invalid', 'Desconto maior que o total da venda.');
        }
        if ($discountCents > 0) {
            $items[] = ['name' => 'Desconto', 'quantity' => 1, 'unit_price' => Money::format(-$discountCents), 'total' => Money::format(-$discountCents)];
        }

        return $this->record($branch, CashEntry::IN, CashCategory::CounterSale, $method, $total - $discountCents, $note, $user, $items);
    }

    public function void(CashEntry $entry, string $reason, User $user): CashEntry
    {
        if ($entry->voided_at !== null) {
            throw new ApiException(409, 'cash_entry_voided', 'Este lançamento já foi estornado.');
        }
        $session = $entry->cash_session_id ? CashSession::query()->find($entry->cash_session_id) : null;
        if ($session !== null && ! $session->isOpen()) {
            throw new ApiException(409, 'cash_session_closed', 'O caixa deste lançamento já foi fechado. Lance um ajuste no caixa atual.');
        }
        $entry->forceFill(['voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => $reason])->save();

        return $entry;
    }

    public static function cents(mixed $sum): int
    {
        return Money::toCents(number_format((float) $sum, 2, '.', ''));
    }
}
