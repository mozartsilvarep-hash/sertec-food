<?php

namespace App\Http\Errors;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Erro de API com código estável para o frontend. Renderizado por
 * ErrorRenderer no formato {code, message, details, request_id}.
 */
class ApiException extends HttpException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(
        int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?array $details = null,
    ) {
        parent::__construct($status, $message);
    }

    public static function tenantUnavailable(): self
    {
        return new self(403, 'tenant_unavailable', 'Este estabelecimento está indisponível no momento.');
    }

    public static function invalidCredentials(): self
    {
        return new self(422, 'invalid_credentials', 'E-mail ou senha inválidos.');
    }

    public static function forbidden(): self
    {
        return new self(403, 'forbidden', 'Você não tem permissão para esta ação.');
    }

    public static function featureUnavailable(string $feature): self
    {
        return new self(403, 'feature_unavailable', 'Recurso não incluído no plano atual.', ['feature' => $feature]);
    }
}
