<?php

namespace App\Modules\Platform\Console;

use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreatePlatformAdminCommand extends Command
{
    protected $signature = 'platform:create-admin
        {email : E-mail}
        {name : Nome}
        {--role=superadmin : superadmin|support}';

    protected $description = 'Cria (ou promove) um usuário da equipe SerTec; senha aleatória exibida uma vez';

    public function handle(AuditLogger $audit): int
    {
        $role = PlatformRole::tryFrom((string) $this->option('role'));
        if ($role === null) {
            $this->error('Papel inválido. Use superadmin ou support.');

            return self::FAILURE;
        }

        $email = strtolower((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->first();
        $password = null;

        if ($user === null) {
            $password = Str::password(20, symbols: false);
            $user = User::query()->create([
                'name' => (string) $this->argument('name'),
                'email' => $email,
                'password' => $password,
            ]);
        }

        $user->forceFill(['platform_role' => $role])->save();
        $audit->record('platform.admin_granted', $user, ['role' => $role->value]);

        $this->info("Usuário {$email} agora é {$role->value}.");
        if ($password !== null) {
            $this->warn("Senha inicial (exibida uma única vez): {$password}");
        }

        return self::SUCCESS;
    }
}
