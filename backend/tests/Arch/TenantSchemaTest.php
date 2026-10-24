<?php

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;

/*
| Cenário 12 — meta-teste que protege as próximas sprints: toda tabela com
| coluna tenant_id precisa de índice em tenant_id e de um model que use
| BelongsToTenant. Exceções exigem justificativa aqui e no ARCHITECTURE.md.
*/

const TENANT_SCOPE_EXCEPTIONS = [
    'roles' => 'papéis globais (tenant_id NULL) visíveis a todos os tenants; regra validada em TenantUser',
    'audit_logs' => 'tenant_id NULL para ações de plataforma; trilha somente-inserção',
];

/**
 * @return array<string, class-string<Model>>
 */
function modelsByTable(): array
{
    $map = [];
    $files = Finder::create()->files()->in(app_path('Modules'))->path('Models')->name('*.php');

    foreach ($files as $file) {
        $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
        $class = 'App\\Modules\\'.$relative;

        if (class_exists($class) && is_subclass_of($class, Model::class)) {
            $map[(new $class)->getTable()] = $class;
        }
    }

    return $map;
}

/**
 * @return list<string>
 */
function tablesWithTenantId(): array
{
    return collect(DB::select(
        "SELECT TABLE_NAME AS t FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'tenant_id'"
    ))->pluck('t')->sort()->values()->all();
}

it('12. toda tabela com tenant_id tem model com BelongsToTenant (ou exceção justificada)', function () {
    $models = modelsByTable();
    $tables = tablesWithTenantId();

    expect($tables)->toContain('branches', 'tenant_users', 'tenant_domains', 'subscriptions');

    foreach ($tables as $table) {
        if (array_key_exists($table, TENANT_SCOPE_EXCEPTIONS)) {
            continue;
        }

        expect(array_key_exists($table, $models))->toBeTrue("Tabela {$table} tem tenant_id mas nenhum model em app/Modules/*/Models.");
        expect(in_array(BelongsToTenant::class, class_uses_recursive($models[$table]), true))
            ->toBeTrue("Model {$models[$table]} precisa usar BelongsToTenant.");
    }
});

it('12. toda tabela com tenant_id tem índice iniciando por tenant_id', function () {
    foreach (tablesWithTenantId() as $table) {
        $indexed = DB::selectOne(
            "SELECT COUNT(*) AS c FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'tenant_id' AND SEQ_IN_INDEX = 1",
            [$table]
        );

        expect((int) $indexed->c)->toBeGreaterThan(0, "Tabela {$table} sem índice em tenant_id.");
    }
});

it('exceções ao BelongsToTenant ainda existem (lista não fica obsoleta)', function () {
    expect(tablesWithTenantId())->toContain(...array_keys(TENANT_SCOPE_EXCEPTIONS));
});
