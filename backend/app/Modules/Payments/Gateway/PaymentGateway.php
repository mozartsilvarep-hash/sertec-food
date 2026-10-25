<?php

namespace App\Modules\Payments\Gateway;

use App\Modules\Payments\Models\IntegrationConnection;
use Carbon\CarbonImmutable;

/**
 * Porta de saída para o provedor de pagamento. A implementação recebe a
 * conexão do restaurante e nunca guarda o token em propriedade de longa vida.
 */
interface PaymentGateway
{
    /** Valida o token e devolve a conta dona dele. */
    public function account(string $accessToken): AccountInfo;

    /**
     * @param  array{amount_cents: int, description: string, reference: string, payer_email: string, payer_name: string, expires_at: CarbonImmutable, notification_url: string|null}  $data
     */
    public function createPix(IntegrationConnection $connection, array $data, string $idempotencyKey): RemotePayment;

    /**
     * Preferência do Checkout Pro (cartão). Devolve [preference_id, url].
     *
     * @param  array{amount_cents: int, title: string, reference: string, payer_name: string, payer_email: string|null, expires_at: CarbonImmutable, notification_url: string|null, back_url: string|null}  $data
     * @return array{preference_id: string, url: string}
     */
    public function createCheckout(IntegrationConnection $connection, array $data, string $idempotencyKey): array;

    public function getPayment(IntegrationConnection $connection, string $paymentId): RemotePayment;

    /**
     * Pagamentos com esta external_reference (o Checkout Pro pode ter várias tentativas).
     *
     * @return list<RemotePayment>
     */
    public function searchByReference(IntegrationConnection $connection, string $reference): array;

    /** Reembolso total (null) ou parcial em centavos. */
    public function refund(IntegrationConnection $connection, string $paymentId, ?int $amountCents, string $idempotencyKey): RemoteRefund;
}
