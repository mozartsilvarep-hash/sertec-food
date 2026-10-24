<?php

use App\Modules\Tenancy\Exceptions\MissingTenantContext;
use App\Modules\Tenancy\Jobs\TenantJob;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Support\Facades\Bus;

/*
| Cenário 10: jobs carregam tenant_id explicitamente.
*/

class RecordBranchNamesJob extends TenantJob
{
    /** @var array{tenant:int, names:list<string>}|null */
    public static ?array $seen = null;

    public function handle(): void
    {
        self::$seen = [
            'tenant' => ctx()->id(),
            'names' => Branch::query()->orderBy('name')->pluck('name')->all(),
        ];
    }
}

beforeEach(function () {
    RecordBranchNamesJob::$seen = null;
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    makeBranch($this->a, ['name' => 'A1']);
    makeBranch($this->b, ['name' => 'B1']);
});

it('10. job criado em A roda com contexto A mesmo se o worker estiver em B', function () {
    $job = inTenant($this->a, fn () => new RecordBranchNamesJob);

    // Simula o worker logo após processar um job do tenant B.
    inTenant($this->b, fn () => Bus::dispatchSync($job));

    expect(RecordBranchNamesJob::$seen)->toBe(['tenant' => $this->a->id, 'names' => ['A1']]);
    expect(ctx()->has())->toBeFalse();
});

it('10. job serializado e desserializado preserva o tenant', function () {
    $job = inTenant($this->a, fn () => new RecordBranchNamesJob);
    $restored = unserialize(serialize($job));

    Bus::dispatchSync($restored);

    expect(RecordBranchNamesJob::$seen['tenant'])->toBe($this->a->id);
});

it('10. não é possível criar job de negócio sem contexto de tenant', function () {
    expect(fn () => new RecordBranchNamesJob)->toThrow(MissingTenantContext::class);
});
