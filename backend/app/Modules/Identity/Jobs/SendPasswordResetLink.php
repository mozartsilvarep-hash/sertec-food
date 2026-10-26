<?php

namespace App\Modules\Identity\Jobs;

use App\Modules\Identity\Mail\PasswordResetMail;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * Gera o token DENTRO do job e envia o link. O que vai para a fila é só o id
 * do usuário e o endereço do painel: o link nunca fica gravado na fila, em
 * failed_jobs ou em log. Responder antes do envio também evita que o tempo de
 * resposta revele se o e-mail existe.
 */
class SendPasswordResetLink implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $userId,
        /** Ex.: https://loja.exemplo.com.br/painel/redefinir-senha */
        public readonly string $resetUrl,
        public readonly string $panel,
    ) {}

    public function handle(): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null || ! $user->active) {
            return;
        }

        /** @var PasswordBroker $broker */
        $broker = Password::broker();
        if ($broker->getRepository()->recentlyCreatedToken($user)) {
            return; // no máximo um e-mail por minuto
        }

        $token = $broker->createToken($user);
        $url = $this->resetUrl.'?'.http_build_query(['token' => $token, 'email' => $user->email]);

        try {
            Mail::to($user->email)->send(new PasswordResetMail(
                name: strtok($user->name, ' ') ?: $user->name,
                url: $url,
                panel: $this->panel,
                minutes: (int) config('auth.passwords.users.expire', 60),
            ));
        } catch (Throwable $e) {
            Log::error('mail.password_reset_failed', ['user' => $user->id, 'error' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }
}
