<?php

namespace App\Modules\Payments\Gateway;

final readonly class RemoteRefund
{
    public function __construct(
        public string $id,
        public string $status,
        public int $amountCents,
    ) {}
}
