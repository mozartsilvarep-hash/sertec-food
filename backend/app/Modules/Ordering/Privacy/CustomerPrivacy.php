<?php

namespace App\Modules\Ordering\Privacy;

use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Customer;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderItem;
use App\Modules\Ordering\Services\OrderingException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LGPD no estabelecimento atual (sempre dentro do contexto do tenant):
 *  - relatório do que existe sobre um celular (direito de acesso);
 *  - anonimização a pedido do titular (direito de eliminação);
 *  - anonimização automática após o prazo de retenção.
 *
 * Anonimizar = apagar nome, telefone, e-mail, endereço e observações; o
 * pedido mantém número, data, itens e valores (obrigações contábeis).
 */
final class CustomerPrivacy
{
    public const ANONYMOUS = 'Cliente (dados removidos)';

    /**
     * @return array{customer: array<string, mixed>|null, orders: list<array<string, mixed>>}
     */
    public function report(string $phone): array
    {
        $customer = Customer::query()->where('phone', $phone)->first();
        $orders = Order::query()->where('customer_phone', $phone)->whereNull('anonymized_at')->orderByDesc('placed_at')->limit(200)->get();

        return [
            'customer' => $customer === null ? null : [
                'name' => $customer->name, 'phone' => $customer->phone, 'email' => $customer->email,
                'created_at' => $customer->created_at?->toIso8601String(), 'last_order_at' => $customer->last_order_at?->toIso8601String(),
            ],
            'orders' => $orders->map(fn (Order $o) => [
                'number' => $o->order_number, 'placed_at' => $o->placed_at->toIso8601String(), 'status' => $o->status->value,
                'name' => $o->customer_name, 'address' => $o->address_snapshot_json, 'total' => $o->total,
            ])->values()->all(),
        ];
    }

    /** Titular pediu exclusão. Recusa se houver pedido em andamento. */
    public function anonymizeCustomer(string $phone): int
    {
        $active = Order::query()->where('customer_phone', $phone)
            ->whereIn('status', [...OrderStatus::active(), OrderStatus::AwaitingPayment])->exists();
        if ($active) {
            throw OrderingException::make('order_in_progress', 'Há pedido em andamento para este telefone. Conclua ou cancele antes de remover os dados.', null, 409);
        }

        return DB::transaction(function () use ($phone) {
            $count = $this->scrub(Order::query()->where('customer_phone', $phone)->whereNull('anonymized_at'));
            Customer::query()->where('phone', $phone)->delete();

            return $count;
        });
    }

    /** Retenção vencida: pedidos finalizados antes de $before. */
    public function anonymizeOlderThan(Carbon $before): int
    {
        return DB::transaction(function () use ($before) {
            $count = $this->scrub(Order::query()->whereNull('anonymized_at')->where('placed_at', '<', $before)
                ->whereIn('status', [OrderStatus::Completed, OrderStatus::Rejected, OrderStatus::Cancelled]));

            // Cliente sem pedido recente deixa de existir.
            Customer::query()->where(fn ($q) => $q->whereNull('last_order_at')->orWhere('last_order_at', '<', $before))
                ->whereDoesntHave('orders', fn ($q) => $q->whereNull('anonymized_at'))
                ->delete();

            return $count;
        });
    }

    /**
     * @param  Builder<Order>  $query
     */
    private function scrub($query): int
    {
        $count = 0;
        $query->select(['id', 'tenant_id', 'branch_id', 'address_snapshot_json'])->chunkById(200, function ($orders) use (&$count) {
            foreach ($orders as $order) {
                $address = is_array($order->address_snapshot_json) ? $order->address_snapshot_json : null;
                // Bairro fica (sem identificar a pessoa) para relatórios de entrega.
                $order->forceFill([
                    'customer_id' => null,
                    'customer_name' => self::ANONYMOUS,
                    'customer_phone' => '',
                    'address_snapshot_json' => $address === null ? null : array_filter(['neighborhood' => $address['neighborhood'] ?? null]),
                    'notes' => null,
                    'anonymized_at' => Carbon::now(),
                ])->save();
                OrderItem::query()->where('order_id', $order->id)->whereNotNull('notes')->update(['notes' => null]);
                $count++;
            }
        });

        return $count;
    }
}
