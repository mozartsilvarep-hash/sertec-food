<?php

namespace App\Modules\Ordering\Services;

use App\Modules\Ordering\Enums\FulfillmentType;
use App\Modules\Ordering\Models\DeliveryZone;
use App\Modules\Tenancy\Models\Branch;
use App\Support\Money;

/**
 * Taxa e prazo de entrega/retirada para um subtotal. Entrega por bairro;
 * raio/polígono dependem de geocodificação (decisão pendente).
 */
final class DeliveryQuote
{
    /**
     * @param  array<string, mixed>|null  $address
     * @return array{fee_cents: int, eta_minutes: int, zone: DeliveryZone|null}
     */
    public function quote(Branch $branch, FulfillmentType $type, ?string $zoneId, ?array $address, int $subtotalCents): array
    {
        if ($type === FulfillmentType::Pickup) {
            if (! $branch->pickup_enabled) {
                throw OrderingException::make('pickup_unavailable', 'Esta unidade não está aceitando retirada.');
            }

            return ['fee_cents' => 0, 'eta_minutes' => $branch->pickup_eta_minutes, 'zone' => null];
        }

        if (! $branch->delivery_enabled) {
            throw OrderingException::make('delivery_unavailable', 'Esta unidade não está fazendo entregas.');
        }

        $zone = $zoneId === null ? null : DeliveryZone::query()
            ->where('public_id', $zoneId)
            ->where('branch_id', $branch->id)
            ->where('active', true)
            ->first();

        if ($zone === null) {
            throw OrderingException::make('zone_invalid', 'Escolha um bairro atendido.');
        }

        $neighborhood = is_string($address['neighborhood'] ?? null) ? $address['neighborhood'] : '';
        if (! $zone->covers($neighborhood)) {
            throw OrderingException::make('neighborhood_not_served', 'Não entregamos neste bairro.', ['zone' => $zone->public_id]);
        }

        if ($zone->minimum_order !== null && $subtotalCents < Money::toCents($zone->minimum_order)) {
            throw OrderingException::make('minimum_order_not_met', 'O pedido mínimo para este bairro é R$ '.number_format((float) $zone->minimum_order, 2, ',', '.').'.', [
                'minimum_order' => $zone->minimum_order,
            ]);
        }

        $free = $zone->free_above !== null && $subtotalCents >= Money::toCents($zone->free_above);

        return [
            'fee_cents' => $free ? 0 : Money::toCents($zone->fee),
            'eta_minutes' => $zone->eta_minutes,
            'zone' => $zone,
        ];
    }
}
