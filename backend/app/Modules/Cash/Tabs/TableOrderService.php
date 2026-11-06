<?php

namespace App\Modules\Cash\Tabs;

use App\Http\Errors\ApiException;
use App\Modules\Cash\Models\DiningTable;
use App\Modules\Cash\Models\Tab;
use App\Modules\Cash\Models\TabRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Models\Cart;
use App\Modules\Ordering\Services\CartService;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Features\Feature;
use App\Modules\Platform\Features\FeatureGate;
use App\Modules\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pedido pelo QR Code da mesa (D103). O cliente monta o carrinho no
 * cardápio e envia; nada vai para a cozinha sem o garçom confirmar.
 * Também "Chamar garçom" e "Pedir a conta".
 *
 * Proteções: QR aleatório por mesa, no máximo MAX_PENDING pedidos em aberto
 * por mesa, chamados repetidos reaproveitam o mesmo, e pedido não
 * confirmado expira em TabRequest::TTL_MINUTES.
 */
final class TableOrderService
{
    public const MAX_PENDING = 5;

    public function __construct(
        private readonly CartService $carts,
        private readonly TabService $tabs,
        private readonly AuditLogger $audit,
    ) {}

    /** Mesa pelo código do QR (só com Cardápio digital + Mesas contratados). */
    public function table(string $token): DiningTable
    {
        $gate = app(FeatureGate::class);
        if (! $gate->enabled(Feature::ONLINE_ORDERING) || ! $gate->enabled(Feature::TABS)) {
            throw ApiException::featureUnavailable(Feature::TABS);
        }
        $table = preg_match('/^[a-z0-9]{24}$/', $token) ? DiningTable::query()->where('qr_token', $token)->where('active', true)->first() : null;

        return $table ?? throw new ApiException(404, 'table_not_found', 'Mesa não encontrada. Peça ajuda ao garçom.');
    }

    /**
     * @return array{request: TabRequest, token: string}
     */
    public function request(DiningTable $table, string $type, ?string $cartToken, ?string $name, ?string $notes): array
    {
        $this->expireOld();

        return DB::transaction(function () use ($table, $type, $cartToken, $name, $notes) {
            DiningTable::query()->whereKey($table->id)->lockForUpdate()->first();
            $pending = TabRequest::query()->where('dining_table_id', $table->id)->where('status', TabRequest::PENDING);
            if ($type !== TabRequest::ORDER && ($same = (clone $pending)->where('type', $type)->first()) !== null) {
                // Chamado repetido: o mesmo de antes (o garçom já foi avisado).
                return ['request' => $same, 'token' => ''];
            }
            if ((clone $pending)->count() >= self::MAX_PENDING) {
                throw new ApiException(429, 'too_many_requests', 'Já há pedidos aguardando o garçom nesta mesa. Aguarde um instante.');
            }

            $lines = null;
            $summary = $type === TabRequest::WAITER ? 'Chamou o garçom' : ($type === TabRequest::BILL ? 'Pediu a conta' : null);
            if ($type === TabRequest::ORDER) {
                [$lines, $summary] = $this->linesFromCart($table, (string) $cartToken);
            }
            $token = Str::random(40);
            $request = TabRequest::query()->create([
                'branch_id' => $table->branch_id, 'dining_table_id' => $table->id, 'type' => $type,
                'lines_json' => $lines, 'summary' => $summary !== null ? mb_substr($summary, 0, 500) : null,
                'customer_name' => $name !== null && trim($name) !== '' ? mb_substr(trim($name), 0, 60) : null,
                'notes' => $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 200) : null,
                'status' => TabRequest::PENDING, 'view_token_hash' => hash('sha256', $token),
            ]);

            return ['request' => $request, 'token' => $token];
        });
    }

    public function find(string $id, string $token): TabRequest
    {
        $this->expireOld();
        $request = TabRequest::query()->where('public_id', $id)->first();
        if ($request === null || $token === '' || ! hash_equals($request->view_token_hash, hash('sha256', $token))) {
            throw new ApiException(404, 'not_found', 'Pedido não encontrado.');
        }

        return $request;
    }

    /** Garçom confirma: pedido vira item da conta (abre a conta se precisar). */
    public function accept(TabRequest $request, User $user, bool $sendToKitchen): TabRequest
    {
        $this->expireOld();

        return DB::transaction(function () use ($request, $user, $sendToKitchen) {
            $locked = $this->lockPending($request);
            $table = DiningTable::query()->findOrFail($locked->dining_table_id);
            $tab = Tab::query()->where('branch_id', $table->branch_id)->where('open_key', Tab::keyFor($table->label))->where('status', Tab::OPEN)->first();

            if ($locked->type === TabRequest::ORDER) {
                if ($tab === null) {
                    $tab = $this->tabs->open(Branch::query()->findOrFail($table->branch_id), $user, $table->label, 1, $locked->customer_name);
                }
                /** @var list<array<string, mixed>> $lines */
                $lines = $locked->lines_json ?? [];
                if ($locked->notes !== null && $lines !== []) {
                    $lines[0]['notes'] = mb_substr(trim(($lines[0]['notes'] ?? '').' '.$locked->notes), 0, 140);
                }
                $this->tabs->add($tab, $user, $lines);
                if ($sendToKitchen) {
                    $this->tabs->send($tab, $user);
                }
            } elseif ($locked->type === TabRequest::BILL && $tab !== null) {
                $tab->forceFill(['bill_requested_at' => CarbonImmutable::now()])->save();
            }

            $locked->forceFill(['status' => TabRequest::ACCEPTED, 'tab_id' => $tab?->id, 'decided_by' => $user->id, 'decided_at' => CarbonImmutable::now()])->save();
            $this->audit->record('tab_request.accepted', $tab, ['table' => $table->label, 'type' => $locked->type, 'kitchen' => $sendToKitchen]);

            return $locked;
        });
    }

    public function reject(TabRequest $request, User $user, ?string $reason): TabRequest
    {
        return DB::transaction(function () use ($request, $user, $reason) {
            $locked = $this->lockPending($request);
            $locked->forceFill([
                'status' => TabRequest::REJECTED, 'decided_by' => $user->id, 'decided_at' => CarbonImmutable::now(),
                'reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 140) : null,
            ])->save();
            $this->audit->record('tab_request.rejected', null, ['request' => $locked->public_id, 'reason' => $locked->reason]);

            return $locked;
        });
    }

    /** Pedidos não confirmados a tempo deixam de valer. */
    public function expireOld(): void
    {
        TabRequest::query()->where('status', TabRequest::PENDING)
            ->where('created_at', '<', CarbonImmutable::now()->subMinutes(TabRequest::TTL_MINUTES))
            ->update(['status' => TabRequest::EXPIRED, 'updated_at' => CarbonImmutable::now()]);
    }

    private function lockPending(TabRequest $request): TabRequest
    {
        /** @var TabRequest $locked */
        $locked = TabRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== TabRequest::PENDING) {
            throw new ApiException(409, 'request_not_pending', $locked->status === TabRequest::EXPIRED ? 'Este pedido expirou.' : 'Este pedido já foi atendido.');
        }

        return $locked;
    }

    /**
     * Itens do carrinho do cliente → linhas da comanda (o preço oficial é
     * calculado de novo quando o garçom confirma).
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    private function linesFromCart(DiningTable $table, string $cartToken): array
    {
        $cart = $this->carts->find($cartToken);
        if ($cart->branch_id !== $table->branch_id) {
            throw new ApiException(422, 'cart_branch_mismatch', 'O carrinho é de outra unidade. Escaneie o QR da mesa de novo.');
        }
        if ($cart->converted_at !== null) {
            throw new ApiException(409, 'cart_converted', 'Este carrinho já foi enviado.');
        }
        /** @var Cart $cart */
        $cart = Cart::query()->with(['branch', 'items.product', 'items.variant'])->whereKey($cart->id)->firstOrFail();
        $view = $this->carts->view($cart);
        if ($cart->items->isEmpty()) {
            throw new ApiException(422, 'cart_empty', 'O carrinho está vazio.');
        }
        if (! $view['valid']) {
            throw new ApiException(422, 'cart_invalid', 'Algum item ficou indisponível. Revise o carrinho.');
        }
        $lines = [];
        $summary = [];
        foreach ($view['items'] as $i => $row) {
            $item = $cart->items[$i];
            $lines[] = [
                'product_id' => $item->product?->public_id, 'variant_id' => $item->variant?->public_id,
                'options' => array_map(fn (array $s) => ['option' => (string) ($s['option'] ?? ''), 'quantity' => max(1, (int) ($s['quantity'] ?? 1))], $item->selections_json),
                'quantity' => $item->quantity, 'notes' => $item->notes,
            ];
            $opts = implode(', ', array_filter([$row['variant_name'], ...array_map(fn (array $o) => $o['option_name'], $row['options'])]));
            $summary[] = $item->quantity.'× '.$row['product_name'].($opts !== '' ? " ({$opts})" : '');
        }
        $cart->forceFill(['converted_at' => CarbonImmutable::now()])->save();

        return [$lines, implode('; ', $summary)];
    }
}
