<?php

namespace App\Modules\Identity\Auth;

use App\Http\Errors\ApiException;
use App\Modules\Identity\Jobs\SendPasswordResetLink;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Audit\AuditLogger;
use Closure;
use Illuminate\Support\Facades\Password;

/**
 * "Esqueci a senha" dos painéis (restaurante e SerTec).
 *
 * - A resposta é sempre a mesma: não revela se o e-mail existe nem onde a
 *   pessoa trabalha. Só recebe o link quem pode entrar NAQUELE painel
 *   ($eligible): membro ativo do restaurante do host, ou equipe SerTec.
 * - O token é o do broker do Laravel (hash no banco, 60 min, 1 e-mail por
 *   minuto). O link aponta para o host em que o pedido foi feito.
 * - O envio vai para a fila só com o id do usuário; o token é criado no job
 *   (SendPasswordResetLink), então o link nunca fica gravado.
 * - Trocar a senha encerra todas as sessões abertas da pessoa.
 */
final class PasswordResets
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  Closure(User): bool  $eligible
     * @param  string  $resetUrl  página do painel que recebe ?token=&email=
     */
    public function request(string $email, Closure $eligible, string $resetUrl, string $panel): void
    {
        $email = mb_strtolower(trim($email));
        $user = User::query()->where('email', $email)->first();
        if ($user === null || ! $user->active || ! $eligible($user)) {
            $this->audit->record('auth.password_reset_ignored', metadata: ['email_hash' => hash('sha256', $email)]);

            return;
        }

        SendPasswordResetLink::dispatch($user->id, $resetUrl, $panel);
        $this->audit->record('auth.password_reset_requested', $user, actor: $user);
    }

    /**
     * @param  Closure(User): bool  $eligible
     */
    public function reset(string $email, string $token, string $password, Closure $eligible): void
    {
        $status = Password::reset(
            ['email' => mb_strtolower(trim($email)), 'token' => $token, 'password' => $password],
            function (User $user, string $password) use ($eligible) {
                if (! $user->active || ! $eligible($user)) {
                    throw self::invalid();
                }
                $user->forceFill(['password' => $password, 'remember_token' => null])->save();
                SessionTerminator::forUser($user->id);
                $this->audit->record('auth.password_reset', $user, actor: $user);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw self::invalid();
        }
    }

    private static function invalid(): ApiException
    {
        return new ApiException(422, 'reset_link_invalid', 'Este link expirou ou já foi usado. Peça um novo em "Esqueci minha senha".');
    }
}
