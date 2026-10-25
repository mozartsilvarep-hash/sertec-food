<?php

namespace App\Modules\Payments\Gateway;

use App\Http\Errors\ApiException;

/**
 * Falha ao falar com o provedor. A mensagem é sempre nossa: nunca repassa
 * corpo de resposta, token ou cabeçalhos (SECURITY.md).
 */
final class GatewayException extends ApiException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?int $httpStatus = null,
        string $message = 'Não foi possível falar com o Mercado Pago agora. Tente novamente em instantes.',
        int $status = 502,
        string $code = 'payment_provider_unavailable',
    ) {
        parent::__construct($status, $code, $message);
    }

    public static function invalidCredentials(): self
    {
        return new self('unauthorized', 401, 'O Mercado Pago recusou estas credenciais. Confira o Access Token.', 422, 'invalid_provider_credentials');
    }
}
