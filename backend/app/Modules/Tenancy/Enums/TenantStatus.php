<?php

namespace App\Modules\Tenancy\Enums;

enum TenantStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function isOperational(): bool
    {
        return $this === self::Active;
    }
}
