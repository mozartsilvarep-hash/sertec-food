<?php

namespace App\Modules\Platform\Accounts;

use App\Modules\Identity\Models\TenantUser;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\TenantContext;

/**
 * A conta (e-mail/senha) é global. Antes de um restaurante redefinir a senha
 * de alguém, verifica — fora do escopo do tenant — se a mesma conta tem
 * vínculo em outro estabelecimento ou é da equipe SerTec.
 */
final class AccountSharing
{
    public function __construct(private readonly TenantContext $context) {}

    public function isSharedOutside(User $user, int $tenantId): bool
    {
        if ($user->isPlatformStaff()) {
            return true;
        }

        return $this->context->runAsPlatform(
            fn () => TenantUser::query()->where('user_id', $user->id)->where('tenant_id', '!=', $tenantId)->exists(),
        );
    }
}
