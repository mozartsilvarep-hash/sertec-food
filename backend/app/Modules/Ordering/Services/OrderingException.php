<?php

namespace App\Modules\Ordering\Services;

use App\Http\Errors\ApiException;

/**
 * Erros de negócio do carrinho/checkout com códigos estáveis para o frontend.
 */
final class OrderingException extends ApiException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public static function make(string $code, string $message, ?array $details = null, int $status = 422): self
    {
        return new self($status, $code, $message, $details);
    }
}
