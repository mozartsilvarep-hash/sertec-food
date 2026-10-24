<?php

namespace App\Modules\Tenancy\Exceptions;

use LogicException;

/**
 * Tentativa de gravar/mover um registro para um tenant diferente do contexto.
 */
class CrossTenantWrite extends LogicException
{
    public function __construct(string $message = 'Cross-tenant write blocked.')
    {
        parent::__construct($message);
    }
}
