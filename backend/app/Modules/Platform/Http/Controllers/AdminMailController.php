<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Errors\ApiException;
use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Mail\TestMail;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * GET  admin/system/mail       — configuração de envio (sem senha).
 * POST admin/system/mail/test  — envia um e-mail de teste para o PRÓPRIO
 *      superadmin (sem destinatário livre: não vira ferramenta de spam).
 */
class AdminMailController
{
    public function show(): JsonResponse
    {
        $mailer = (string) config('mail.default');
        $smtp = (array) config('mail.mailers.smtp');

        return new JsonResponse(['data' => [
            'mailer' => $mailer,
            'configured' => $mailer === 'smtp' && ! empty($smtp['host']) && ! empty($smtp['username']),
            'host' => $mailer === 'smtp' ? ($smtp['host'] ?? null) : null,
            'port' => $mailer === 'smtp' ? ($smtp['port'] ?? null) : null,
            'from' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
        ]]);
    }

    public function test(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || $user->platform_role !== PlatformRole::Superadmin) {
            throw ApiException::forbidden();
        }

        try {
            Mail::to($user->email)->send(new TestMail(strtok($user->name, ' ') ?: $user->name));
        } catch (Throwable $e) {
            Log::warning('mail.test_failed', ['error' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 300)]);
            $audit->record('platform.mail_test_failed', metadata: ['error' => $e::class]);

            throw new ApiException(422, 'mail_failed', 'O servidor de e-mail recusou o envio: '.mb_substr($e->getMessage(), 0, 200));
        }
        $audit->record('platform.mail_test_sent', $user, actor: $user);

        return new JsonResponse(['message' => "E-mail de teste enviado para {$user->email}. Confira a caixa de entrada e o spam."]);
    }
}
