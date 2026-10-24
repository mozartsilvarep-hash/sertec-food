<?php

use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Platform\Features\FeatureGate;
use App\Modules\Tenancy\TenantContext;
use Symfony\Component\Finder\Finder;

/*
| Cenário 13 — atalhos que contornam o isolamento só podem aparecer em
| arquivos explicitamente autorizados. Novas exceções exigem revisão.
*/

/**
 * @param  list<string>  $allowed  caminhos relativos a base_path()
 * @return list<string> arquivos que violam a regra
 */
function filesUsing(string $pattern, array $allowed): array
{
    $violations = [];
    $files = Finder::create()->files()->in([app_path(), database_path('seeders'), base_path('routes')])->name('*.php');

    foreach ($files as $file) {
        $relative = str_replace(base_path().'/', '', $file->getRealPath());

        if (preg_match($pattern, $file->getContents()) && ! in_array($relative, $allowed, true)) {
            $violations[] = $relative;
        }
    }

    return $violations;
}

it('13. withoutGlobalScope(s) só no resolvedor de host', function () {
    expect(filesUsing('/withoutGlobalScopes?\s*\(/', [
        'app/Modules/Tenancy/Http/Middleware/ResolveTenantFromHost.php',
    ]))->toBe([]);
});

it('13. runAsPlatform só no módulo Platform, seeders e no próprio TenantContext', function () {
    expect(filesUsing('/runAsPlatform\s*\(/', [
        'app/Modules/Tenancy/TenantContext.php',
        'app/Modules/Platform/Http/Middleware/EnsurePlatformSession.php',
        'app/Modules/Platform/Tenants/TenantProvisioner.php',
        'database/seeders/DemoSeeder.php',
    ]))->toBe([]);
});

it('13. Query Builder cru (DB::table / DB::select) não é usado no código de aplicação', function () {
    expect(filesUsing('/DB::(table|select|insert|update|delete|statement)\s*\(/', []))->toBe([]);
});

it('13. código de aplicação não lê tenant_id do request', function () {
    expect(filesUsing('/(input|query|header|get)\(\s*[\'"](tenant_id|tenant|x-tenant-id)[\'"]/i', []))->toBe([]);
});

it('controllers não recebem serviços "scoped" no construtor (instância é reaproveitada)', function () {
    $scoped = [
        TenantContext::class,
        TenantAccess::class,
        FeatureGate::class,
    ];
    $files = Finder::create()->files()->in(app_path('Modules'))->path('Http/Controllers')->name('*.php');
    $violations = [];

    foreach ($files as $file) {
        $class = 'App\\Modules\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
        $constructor = (new ReflectionClass($class))->getConstructor();

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && in_array($type->getName(), $scoped, true)) {
                $violations[] = $class.'::$'.$parameter->getName();
            }
        }
    }

    expect($violations)->toBe([]);
});
