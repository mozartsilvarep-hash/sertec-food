<?php

namespace App\Modules\Ordering\Http\Resources;

use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderStatusHistory;
use App\Modules\Ordering\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Visão pública do pedido (quem tem o link). Sem dados sensíveis:
 * nada de telefone completo, rua/número, e-mail ou IDs internos (PRODUCT.md).
 *
 * @mixin Order
 */
class TrackedOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $address = $this->address_snapshot_json;

        return [
            'id' => $this->public_id,
            'number' => $this->order_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'payment_status' => $this->payment_status->value,
            'payment_method' => $this->payment_method->value,
            'payment_method_label' => $this->payment_method->label(),
            'fulfillment_type' => $this->fulfillment_type->value,
            'placed_at' => $this->placed_at->toIso8601String(),
            'eta_minutes' => $this->eta_minutes,
            'customer_first_name' => strtok($this->customer_name, ' ') ?: null,
            'customer_phone_masked' => Phone::mask($this->customer_phone),
            'neighborhood' => is_array($address) ? ($address['neighborhood'] ?? null) : null,
            'branch' => ['name' => $this->branch->name, 'phone' => $this->branch->phone],
            'cancellation_reason' => $this->cancellation_reason,
            'items' => OrderItemsPresenter::items($this->resource),
            'totals' => OrderItemsPresenter::totals($this->resource),
            'timeline' => $this->history->map(fn (OrderStatusHistory $h) => [
                'status' => $h->new_status->value,
                'label' => $h->new_status->label(),
                'at' => $h->created_at->toIso8601String(),
            ])->values(),
            'final' => $this->status->isFinal(),
            'steps' => array_map(fn (OrderStatus $s) => ['status' => $s->value, 'label' => $s->label()], array_values(array_filter(
                [OrderStatus::PendingAcceptance, OrderStatus::Accepted, OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::OutForDelivery, OrderStatus::Completed],
                fn (OrderStatus $s) => $s !== OrderStatus::OutForDelivery || $this->fulfillment_type->value === 'delivery',
            ))),
        ];
    }
}
