<?php

namespace App\Modules\Ordering\Http\Resources;

use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderItem;
use App\Modules\Ordering\Models\OrderItemOption;
use App\Modules\Ordering\Models\OrderTotal;

final class OrderItemsPresenter
{
    /** @return list<array<string, mixed>> */
    public static function items(Order $order): array
    {
        return $order->items->map(fn (OrderItem $i) => [
            'name' => $i->product_name_snapshot,
            'variant' => $i->variant_name_snapshot,
            'quantity' => $i->quantity,
            'unit_price' => $i->unit_price,
            'line_total' => $i->line_total,
            'notes' => $i->notes,
            'options' => $i->options->map(fn (OrderItemOption $o) => [
                'group' => $o->group_name_snapshot,
                'name' => $o->option_name_snapshot,
                'quantity' => $o->quantity,
            ])->values()->all(),
        ])->values()->all();
    }

    /** @return list<array{code: string, label: string, amount: string}> */
    public static function totals(Order $order): array
    {
        return $order->totals->map(fn (OrderTotal $t) => [
            'code' => $t->code,
            'label' => $t->label,
            'amount' => $t->amount,
        ])->values()->all();
    }
}
