<?php

namespace App\Modules\Identity;

final class SessionKeys
{
    /** 'merchant' (painel do restaurante) ou 'platform' (painel SerTec). */
    public const SCOPE = 'sertec.auth_scope';

    /** Tenant ao qual a sessão de merchant foi vinculada no login. */
    public const TENANT_ID = 'sertec.tenant_id';

    public const SCOPE_MERCHANT = 'merchant';

    public const SCOPE_PLATFORM = 'platform';
}
