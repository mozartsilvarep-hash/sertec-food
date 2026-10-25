<?php

namespace App\Modules\Payments\Enums;

enum RefundStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public static function fromMercadoPago(string $status): self
    {
        return match ($status) {
            'approved' => self::Approved,
            'rejected', 'cancelled' => self::Rejected,
            default => self::Pending,
        };
    }
}
