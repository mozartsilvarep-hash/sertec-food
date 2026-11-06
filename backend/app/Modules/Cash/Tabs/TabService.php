<?php

namespace App\Modules\Cash\Tabs;

use App\Http\Errors\ApiException;
use App\Modules\Cash\Models\Tab;
use App\Modules\Cash\Models\TabItem;
use App\Modules\Catalog\Stock\StockService;
use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Models\Cart;
use App\Modules\Ordering\Services\CartService;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Tenancy\Models\Branch;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Mesas e comandas (D98).
 *
 * - Lançar: itens do cardápio passam pela calculadora do site (preço do
 *   servidor) e baixam o estoque na hora; avulso usa o valor do caixa.
 * - Remover: antes de ir para a cozinha some; depois fica riscado com o
 *   motivo (auditoria) e devolve o estoque.
 * - Cozinha: cada envio é um "lote" impresso com os itens novos.
 * - Fechar: PosSaleService::sell com a comanda (vira pedido + caixa).
 */
final class TabService
{
    public function __construct(
        private readonly CartService $carts,
        private readonly StockService $stock,
        private readonly AuditLogger $audit,
    ) {}

    public function open(Branch $branch, User $user, string $label, int $people, ?string $customerName): Tab
    {
        $label = trim(preg_replace('/\s+/', ' ', $label) ?? '');
        try {
            $tab = Tab::query()->create([
                'branch_id' => $branch->id, 'label' => $label, 'open_key' => Tab::keyFor($label), 'people' => max(1, $people),
                'customer_name' => $customerName !== null && trim($customerName) !== '' ? trim($customerName) : null,
                'status' => Tab::OPEN, 'opened_by' => $user->id, 'opened_at' => CarbonImmutable::now(),
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new ApiException(409, 'tab_already_open', "{$label} já está aberta.");
            }
            throw $e;
        }
        $this->audit->record('tab.opened', $tab, ['label' => $tab->label]);

        return $tab;
    }

    /**
     * @param  list<array<string, mixed>>  $lines  mesmo formato dos itens do PDV
     * @return list<TabItem>
     */
    public function add(Tab $tab, User $user, array $lines): array
    {
        return DB::transaction(function () use ($tab, $user, $lines) {
            $locked = $this->lockOpen($tab);
            $branch = Branch::query()->findOrFail($locked->branch_id);
            $created = [];

            $menu = array_values(array_filter($lines, fn (array $l) => ! empty($l['product_id'])));
            if ($menu !== []) {
                [$cart] = $this->carts->create($branch);
                foreach ($menu as $i => $line) {
                    try {
                        /** @var list<array<string, mixed>> $options */
                        $options = $line['options'] ?? [];
                        $this->carts->add($cart, (string) $line['product_id'], (string) $line['variant_id'], $options, (int) $line['quantity'], $line['notes'] ?? null);
                    } catch (ApiException $e) {
                        throw new ApiException(422, 'sale_item_invalid', 'Item '.($i + 1).': '.$e->getMessage(), ['item' => $i]);
                    }
                }
                /** @var Cart $cart */
                $cart = Cart::query()->with(['branch', 'items.product', 'items.variant'])->whereKey($cart->id)->firstOrFail();
                $view = $this->carts->view($cart);
                if (! $view['valid']) {
                    throw new ApiException(422, 'sale_item_invalid', 'Algum item ficou indisponível. Revise.');
                }
                foreach ($cart->items as $item) {
                    $quote = $view['quotes'][$item->id];
                    $detail = implode(', ', array_filter([
                        $quote->variantName,
                        ...array_map(fn (array $l) => ($l['quantity'] > 1 ? $l['quantity'].'× ' : '').$l['option_name'], $quote->lines),
                    ]));
                    $row = TabItem::query()->create([
                        'tab_id' => $locked->id, 'product_id' => $item->product_id, 'variant_id' => $item->product_variant_id,
                        'options_json' => ['selections' => $item->selections_json, 'lines' => $quote->lines, 'variant_name' => $quote->variantName],
                        'name' => mb_substr($quote->productName, 0, 160), 'detail' => $detail !== '' ? mb_substr($detail, 0, 300) : null,
                        'unit_price' => Money::format($quote->unitPriceCents), 'quantity' => $item->quantity, 'notes' => $item->notes,
                        'added_by' => $user->id,
                    ]);
                    $this->stock->consume($locked->branch_id, StockService::demandFor($item->product_id, $item->selections_json, $item->quantity), $user, null, $row->id, $locked->label);
                    $created[] = $row;
                }
                $cart->forceFill(['converted_at' => CarbonImmutable::now()])->save();
            }

            foreach ($lines as $line) {
                if (! empty($line['product_id'])) {
                    continue;
                }
                $unit = Money::toCents(str_replace(',', '.', (string) $line['unit_price']));
                if ($unit <= 0) {
                    throw new ApiException(422, 'sale_item_invalid', 'Item avulso sem valor.');
                }
                $created[] = TabItem::query()->create([
                    'tab_id' => $locked->id, 'name' => mb_substr(trim((string) $line['description']), 0, 160), 'detail' => 'Avulso',
                    'unit_price' => Money::format($unit), 'quantity' => (int) $line['quantity'], 'notes' => $line['notes'] ?? null, 'added_by' => $user->id,
                ]);
            }
            $locked->touch();
            $this->audit->record('tab.items_added', $locked, ['count' => count($created)]);

            return $created;
        });
    }

    public function void(Tab $tab, TabItem $item, User $user, ?string $reason): void
    {
        DB::transaction(function () use ($tab, $item, $user, $reason) {
            $locked = $this->lockOpen($tab);
            /** @var TabItem $row */
            $row = TabItem::query()->where('tab_id', $locked->id)->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($row->voided_at !== null) {
                return;
            }
            $this->stock->restoreForTabItem($locked->branch_id, $row->id, $user);
            if ($row->sent_at === null) {
                // Ainda não foi para a cozinha: some da conta.
                $this->audit->record('tab.item_removed', $locked, ['item' => $row->name, 'quantity' => $row->quantity]);
                $row->delete();

                return;
            }
            if ($reason === null || trim($reason) === '') {
                throw new ApiException(422, 'validation_failed', 'Diga o motivo: o item já foi para a cozinha.', ['fields' => ['reason' => ['Informe o motivo.']]]);
            }
            $row->forceFill(['voided_at' => CarbonImmutable::now(), 'voided_by' => $user->id, 'void_reason' => trim($reason)])->save();
            $this->audit->record('tab.item_voided', $locked, ['item' => $row->name, 'quantity' => $row->quantity, 'reason' => trim($reason)]);
        });
    }

    /**
     * Itens novos vão para a cozinha num lote (para imprimir).
     *
     * @return array{batch: int|null, items: list<TabItem>}
     */
    public function send(Tab $tab, User $user): array
    {
        return DB::transaction(function () use ($tab, $user) {
            $locked = $this->lockOpen($tab);
            $pending = TabItem::query()->where('tab_id', $locked->id)->whereNull('sent_at')->whereNull('voided_at')->orderBy('id')->get();
            if ($pending->isEmpty()) {
                return ['batch' => null, 'items' => []];
            }
            $batch = $locked->kitchen_batches + 1;
            $now = CarbonImmutable::now();
            TabItem::query()->whereIn('id', $pending->modelKeys())->update(['sent_at' => $now, 'kitchen_batch' => $batch]);
            $locked->forceFill(['kitchen_batches' => $batch])->save();
            $this->audit->record('tab.sent_to_kitchen', $locked, ['batch' => $batch, 'count' => $pending->count(), 'by' => $user->id]);

            return ['batch' => $batch, 'items' => $pending->all()];
        });
    }

    public function cancel(Tab $tab, User $user, string $reason): void
    {
        DB::transaction(function () use ($tab, $user, $reason) {
            $locked = $this->lockOpen($tab);
            foreach (TabItem::query()->where('tab_id', $locked->id)->whereNull('voided_at')->get() as $row) {
                $this->stock->restoreForTabItem($locked->branch_id, $row->id, $user);
                $row->forceFill(['voided_at' => CarbonImmutable::now(), 'voided_by' => $user->id, 'void_reason' => 'Conta cancelada'])->save();
            }
            $locked->forceFill(['status' => Tab::CANCELLED, 'open_key' => null, 'closed_by' => $user->id, 'closed_at' => CarbonImmutable::now(), 'notes' => mb_substr(trim($reason), 0, 300)])->save();
            $this->audit->record('tab.cancelled', $locked, ['label' => $locked->label, 'reason' => trim($reason)]);
        });
    }

    public function lockOpen(Tab $tab): Tab
    {
        /** @var Tab $locked */
        $locked = Tab::query()->whereKey($tab->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isOpen()) {
            throw new ApiException(409, 'tab_not_open', 'Esta conta já foi fechada.');
        }

        return $locked;
    }

    /** Soma dos itens ativos (referência; o fechamento recalcula descontos e taxa). */
    public static function subtotalCents(Tab $tab): int
    {
        return (int) $tab->items->whereNull('voided_at')->sum(fn (TabItem $i) => Money::toCents((string) $i->unit_price) * $i->quantity);
    }
}
