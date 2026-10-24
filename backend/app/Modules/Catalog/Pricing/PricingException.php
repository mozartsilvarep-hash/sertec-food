<?php

namespace App\Modules\Catalog\Pricing;

use App\Http\Errors\ApiException;

/**
 * Configuração de produto inválida. Vira 422 invalid_selection com o motivo
 * em details.reason (estável, para o frontend traduzir).
 */
final class PricingException extends ApiException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(public readonly string $reason, string $message, array $context = [])
    {
        parent::__construct(422, 'invalid_selection', $message, ['reason' => $reason, ...$context]);
    }
}
