<?php

namespace App\Modules\Payments\Gateway;

use Carbon\CarbonImmutable;

/**
 * Pagamento como o provedor o vê. Só campos necessários à conciliação.
 */
final readonly class RemotePayment
{
    public function __construct(
        public string $id,
        public string $status,
        public ?string $statusDetail,
        public ?string $externalReference,
        public int $amountCents,
        public int $refundedCents,
        public ?string $currency,
        public ?string $collectorId,
        public ?bool $liveMode,
        public ?string $pixQrCode = null,
        public ?string $pixQrBase64 = null,
        public ?string $ticketUrl = null,
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}
