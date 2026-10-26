<?php

namespace App\Modules\Ordering\Services;

use App\Modules\Loyalty\Services\LoyaltyService;
use App\Modules\Ordering\Enums\FulfillmentType;
use App\Modules\Ordering\Models\Customer;
use App\Modules\Ordering\Models\Order;
use Carbon\CarbonImmutable;

/**
 * Cliente reconhecido pelo celular, dentro do restaurante atual (TenantScope).
 *
 * Qualquer pessoa pode digitar o celular de outra. Por isso a resposta
 * PÚBLICA nunca traz o endereço completo: só primeiro nome, rua e bairro,
 * com o número escondido. O endereço completo só sai do servidor de volta
 * para o próprio restaurante, quando o cliente escolhe "usar este endereço"
 * no checkout (o acompanhamento público também não mostra rua nem número).
 * Pedidos anonimizados (LGPD) nunca entram.
 */
final class CustomerDirectory
{
    public function __construct(private readonly LoyaltyService $loyalty) {}

    /** Últimas 24 h em "acompanhar pelo celular". */
    public const TRACK_HOURS = 24;

    /**
     * @return array{known: bool, first_name: string|null, saved_address: array{street: string, number: string, neighborhood: string}|null, loyalty: array{stamps: int, required: int, rewards: int, reward_label: string}|null}
     */
    public function publicLookup(string $phone): array
    {
        $customer = Customer::query()->where('phone', $phone)->first();
        $loyalty = $this->loyalty->status($phone);
        if ($customer === null) {
            return ['known' => false, 'first_name' => null, 'saved_address' => null, 'loyalty' => $loyalty];
        }

        $address = $this->savedAddress($phone);

        return [
            'known' => true,
            'first_name' => (strtok(trim($customer->name), ' ') ?: null),
            'saved_address' => $address === null ? null : [
                'street' => $address['street'],
                'number' => '•••',
                'neighborhood' => $address['neighborhood'],
            ],
            'loyalty' => $loyalty,
        ];
    }

    /**
     * Endereço do último pedido com entrega (completo; uso interno do checkout).
     *
     * @return array{street: string, number: string, complement: string|null, neighborhood: string, reference: string|null}|null
     */
    public function savedAddress(string $phone): ?array
    {
        $order = Order::query()
            ->where('customer_phone', $phone)
            ->where('fulfillment_type', FulfillmentType::Delivery)
            ->whereNull('anonymized_at')
            ->whereNotNull('address_snapshot_json')
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->first(['id', 'address_snapshot_json']);

        $a = $order?->address_snapshot_json;
        if (! is_array($a) || empty($a['street']) || empty($a['number']) || empty($a['neighborhood'])) {
            return null;
        }

        return [
            'street' => (string) $a['street'],
            'number' => (string) $a['number'],
            'complement' => isset($a['complement']) && $a['complement'] !== '' ? (string) $a['complement'] : null,
            'neighborhood' => (string) $a['neighborhood'],
            'reference' => isset($a['reference']) && $a['reference'] !== '' ? (string) $a['reference'] : null,
        ];
    }

    /**
     * Pedidos recentes do celular: só número, horário, situação e total.
     *
     * @return list<array{number: int, placed_at: string, status: string, status_label: string, fulfillment_type: string, total: string, branch: string}>
     */
    public function recentOrders(string $phone): array
    {
        return Order::query()
            ->with('branch:id,tenant_id,name')
            ->where('customer_phone', $phone)
            ->whereNull('anonymized_at')
            ->where('placed_at', '>=', CarbonImmutable::now()->subHours(self::TRACK_HOURS))
            ->orderByDesc('placed_at')
            ->limit(10)
            ->get()
            ->map(fn (Order $o) => [
                'number' => $o->order_number,
                'placed_at' => $o->placed_at->toIso8601String(),
                'status' => $o->status->value,
                'status_label' => $o->status->label(),
                'fulfillment_type' => $o->fulfillment_type->value,
                'total' => number_format((float) $o->total, 2, '.', ''),
                'branch' => $o->branch->name,
            ])
            ->values()
            ->all();
    }
}
