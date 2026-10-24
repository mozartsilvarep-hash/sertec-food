<?php

namespace App\Modules\Tenancy\Exceptions;

use LogicException;

/**
 * Erro de programação: código de negócio tentou acessar dados de tenant sem
 * TenantContext definido. Nunca é exibido ao cliente (vira 500 genérico).
 */
class MissingTenantContext extends LogicException
{
    public function __construct(string $message = 'Tenant context is not set.')
    {
        parent::__construct($message);
    }
}
