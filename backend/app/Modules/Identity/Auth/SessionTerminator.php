<?php

namespace App\Modules\Identity\Auth;

use Illuminate\Support\Facades\DB;

/**
 * Encerra as sessões de um usuário (troca/redefinição de senha, remoção do
 * estabelecimento). `sessions` não tem tenant_id: é tabela de infraestrutura
 * do driver de sessão, por isso o Query Builder cru é permitido só aqui
 * (exceção registrada no teste de arquitetura).
 */
final class SessionTerminator
{
    public static function forUser(int $userId, ?string $exceptSessionId = null): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->when($exceptSessionId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->delete();
    }
}
