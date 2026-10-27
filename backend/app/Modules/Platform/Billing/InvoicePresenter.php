<?php

namespace App\Modules\Platform\Billing;

use App\Modules\Platform\Models\Invoice;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;

final class InvoicePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function invoice(Invoice $i, bool $withPix = false): array
    {
        return [
            'id' => $i->public_id,
            'plan_name' => $i->plan_name,
            'months' => $i->months,
            'amount' => $i->amount,
            'period_start' => $i->period_start->toDateString(),
            'period_end' => $i->period_end->toDateString(),
            'due_date' => $i->due_date->toDateString(),
            'status' => $i->status,
            'overdue' => $i->isOverdue(),
            'paid_at' => $i->paid_at?->toIso8601String(),
            'paid_via' => $i->paid_via,
            'note' => $i->note,
            'pix' => $withPix && $i->status === Invoice::OPEN && $i->hasValidPix() ? [
                'code' => $i->pix_code,
                'qr_base64' => $i->pix_qr_base64,
                'expires_at' => $i->pix_expires_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function subscription(?Subscription $s): ?array
    {
        if ($s === null) {
            return null;
        }

        return [
            'plan' => ['slug' => $s->plan->slug, 'name' => $s->plan->name],
            'status' => $s->status,
            'billing_months' => $s->billing_months,
            'period_label' => Plan::PERIODS[$s->billing_months] ?? "{$s->billing_months} meses",
            'custom_price' => $s->price,
            'price' => $s->effectivePrice(),
            'paid_until' => $s->paid_until?->toDateString(),
        ];
    }
}
