<?php

namespace App\Modules\Payments\Gateway;

final readonly class AccountInfo
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $siteId,
    ) {}
}
