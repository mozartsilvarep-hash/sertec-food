<?php

namespace App\Modules\Ordering\Http\Resources;

use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderStatusHistory;
use App\Modules\Ordering\Support\Phone;
use App\Modules\Payments\Support\PaymentPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Visão do restaurante: dados completos para preparar e entregar.
 *
 * @mixin Order
 */
class MerchantOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->order_number,
            'branch' => ['id' => $this->branch->public_id, 'name' => $this->branch->name],
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'next_statuses' => array_map(
                fn (OrderStatus $s) => ['status' => $s->value, 'label' => $s->label()],
                $this->status->nextFor($this->fulfillment_type),
            ),
            'payment_status' => $this->payment_status->value,
            'payment_method' => $this->payment_method->value,
            'payment_method_label' => $this->payment_method->label(),
            'change_for' => $this->change_for,
            'online_payment' => $this->whenLoaded('payments', fn () => PaymentPresenter::merchant($this->resource)),
            'fulfillment_type' => $this->fulfillment_type->value,
            'customer' => ['name' => $this->customer_name, 'phone' => Phone::format($this->customer_phone)],
            'address' => $this->address_snapshot_json,
            'delivery_zone' => $this->delivery_zone_name,
            'notes' => $this->notes,
            'subtotal' => $this->subtotal,
            'delivery_fee' => $this->delivery_fee,
            'total' => $this->total,
            'eta_minutes' => $this->eta_minutes,
            'placed_at' => $this->placed_at->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'cancellation_note' => $this->cancellation_note,
            'items' => $this->whenLoaded('items', fn () => OrderItemsPresenter::items($this->resource)),
            'totals' => $this->whenLoaded('totals', fn () => OrderItemsPresenter::totals($this->resource)),
            'history' => $this->whenLoaded('history', fn () => $this->history->map(fn (OrderStatusHistory $h) => [
                'from' => $h->old_status?->value,
                'to' => $h->new_status->value,
                'label' => $h->new_status->label(),
                'note' => $h->note,
                'at' => $h->created_at->toIso8601String(),
            ])->values()),
        ];
    }
}
