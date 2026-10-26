<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Auth\PasswordResets;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PasswordPolicy;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST merchant/auth/forgot-password | admin/auth/forgot-password  {email}
 * POST merchant/auth/reset-password  | admin/auth/reset-password   {email, token, password, password_confirmation}
 *
 * Restaurante: só membros ativos do restaurante do host recebem o link.
 * SerTec: só a equipe da plataforma (host admin).
 */
class PasswordResetController
{
    private const SENT = 'Se este e-mail tiver acesso ao painel, enviamos um link para criar uma senha nova. Confira também o spam.';

    public function merchantForgot(Request $request, PasswordResets $resets, TenantContext $context, TenantAccess $access): JsonResponse
    {
        $email = $this->email($request);
        $base = $request->getSchemeAndHttpHost();
        $resets->request(
            $email,
            fn (User $user) => $access->for($user)->isMember,
            $base.'/painel/redefinir-senha',
            'painel de '.$context->get()->name,
        );

        return new JsonResponse(['message' => self::SENT]);
    }

    public function merchantReset(Request $request, PasswordResets $resets, TenantAccess $access): JsonResponse
    {
        $data = $this->resetData($request);
        $resets->reset($data['email'], $data['token'], $data['password'], fn (User $user) => $access->for($user)->isMember);

        return new JsonResponse(['message' => 'Senha criada. Entre com a senha nova.']);
    }

    public function adminForgot(Request $request, PasswordResets $resets): JsonResponse
    {
        $email = $this->email($request);
        $base = $request->getSchemeAndHttpHost();
        $resets->request(
            $email,
            fn (User $user) => $user->isPlatformStaff(),
            $base.'/redefinir-senha',
            'Painel SerTec',
        );

        return new JsonResponse(['message' => self::SENT]);
    }

    public function adminReset(Request $request, PasswordResets $resets): JsonResponse
    {
        $data = $this->resetData($request);
        $resets->reset($data['email'], $data['token'], $data['password'], fn (User $user) => $user->isPlatformStaff());

        return new JsonResponse(['message' => 'Senha criada. Entre com a senha nova.']);
    }

    private function email(Request $request): string
    {
        return (string) $request->validate(['email' => ['required', 'string', 'email', 'max:191']], [], ['email' => 'e-mail'])['email'];
    }

    /** @return array{email: string, token: string, password: string} */
    private function resetData(Request $request): array
    {
        /** @var array{email: string, token: string, password: string} $data */
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:191'],
            'token' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'confirmed', ...PasswordPolicy::rules()],
        ], [], ['email' => 'e-mail', 'password' => 'nova senha']);

        return $data;
    }
}
