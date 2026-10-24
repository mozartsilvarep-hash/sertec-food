<?php

use App\Modules\Tenancy\Exceptions\CrossTenantWrite;
use App\Modules\Tenancy\Exceptions\MissingTenantContext;
use App\Modules\Tenancy\Models\Branch;

/*
| Isolamento na camada de dados (cenários 1–6 e 9 do plano da Sprint 0).
*/

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->branchA = makeBranch($this->a, ['name' => 'A1']);
    $this->branchB = makeBranch($this->b, ['name' => 'B1']);
});

it('1. falha fechado: consultar model de tenant sem contexto lança exceção', function () {
    expect(fn () => Branch::query()->get())->toThrow(MissingTenantContext::class);
    expect(fn () => Branch::query()->count())->toThrow(MissingTenantContext::class);
    expect(fn () => Branch::query()->find($this->branchA->id))->toThrow(MissingTenantContext::class);
});

it('2. com contexto A, lista apenas registros de A', function () {
    $names = inTenant($this->a, fn () => Branch::query()->pluck('name')->all());

    expect($names)->toBe(['A1']);
});

it('3. com contexto A, find() de um id de B retorna null', function () {
    $found = inTenant($this->a, fn () => Branch::query()->find($this->branchB->id));
    $byUlid = inTenant($this->a, fn () => Branch::query()->where('public_id', $this->branchB->public_id)->first());

    expect($found)->toBeNull()->and($byUlid)->toBeNull();
});

it('4. create() preenche tenant_id pelo contexto e bloqueia tenant_id divergente', function () {
    $created = inTenant($this->a, fn () => Branch::query()->create(['name' => 'Nova A']));
    expect($created->tenant_id)->toBe($this->a->id);

    expect(fn () => inTenant($this->a, fn () => Branch::query()->create([
        'name' => 'Intrusa', 'tenant_id' => $this->b->id,
    ])))->toThrow(CrossTenantWrite::class);

    expect(asPlatform(fn () => Branch::query()->where('name', 'Intrusa')->exists()))->toBeFalse();
});

it('5. tenant_id de um registro existente é imutável', function () {
    expect(fn () => inTenant($this->a, function () {
        $branch = Branch::query()->findOrFail($this->branchA->id);
        $branch->tenant_id = $this->b->id;
        $branch->save();
    }))->toThrow(CrossTenantWrite::class);

    // Nem mesmo em modo plataforma.
    expect(fn () => asPlatform(function () {
        $branch = Branch::query()->findOrFail($this->branchA->id);
        $branch->tenant_id = $this->b->id;
        $branch->save();
    }))->toThrow(CrossTenantWrite::class);
});

it('6. update/delete em massa só afetam o tenant do contexto', function () {
    $updated = inTenant($this->a, fn () => Branch::query()->update(['active' => false]));
    $deleted = inTenant($this->a, fn () => Branch::query()->where('name', 'like', '%')->delete());

    expect($updated)->toBe(1)->and($deleted)->toBe(1);

    $b = asPlatform(fn () => Branch::query()->findOrFail($this->branchB->id));
    expect($b->active)->toBeTrue();
});

it('9. run() restaura o contexto anterior, inclusive após exceção', function () {
    inTenant($this->a, function () {
        inTenant($this->b, fn () => expect(ctx()->id())->toBe($this->b->id));
        expect(ctx()->id())->toBe($this->a->id);

        try {
            inTenant($this->b, fn () => throw new RuntimeException('falha'));
        } catch (RuntimeException) {
        }

        expect(ctx()->id())->toBe($this->a->id);
        expect(Branch::query()->pluck('name')->all())->toBe(['A1']);
    });

    expect(ctx()->has())->toBeFalse();
});

it('modo plataforma exige tenant_id explícito ao criar', function () {
    expect(fn () => asPlatform(fn () => Branch::query()->create(['name' => 'Sem dono'])))
        ->toThrow(MissingTenantContext::class);
});
