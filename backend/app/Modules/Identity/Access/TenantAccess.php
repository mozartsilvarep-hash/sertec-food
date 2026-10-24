<?php

namespace App\Modules\Identity\Access;

use App\Modules\Identity\Models\TenantUser;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\TenantContext;

/**
 * Resolve o MemberAccess do usuário no tenant do contexto. Registrado como
 * "scoped": o cache vive apenas durante a requisição/job.
 */
final class TenantAccess
{
    /** @var array<string, MemberAccess> */
    private array $cache = [];

    public function __construct(private readonly TenantContext $context) {}

    public function for(User $user): MemberAccess
    {
        $key = $this->context->id().':'.$user->getKey();

        return $this->cache[$key] ??= $this->load($user);
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    private function load(User $user): MemberAccess
    {
        if (! $user->active) {
            return MemberAccess::none();
        }

        // TenantUser é escopado pelo TenantScope: só vínculos do tenant atual.
        $memberships = TenantUser::query()
            ->with('role.permissions')
            ->where('user_id', $user->getKey())
            ->where('active', true)
            ->get();

        if ($memberships->isEmpty()) {
            return MemberAccess::none();
        }

        $allBranches = $memberships->contains(fn (TenantUser $m) => $m->branch_id === null);

        /** @var list<int> $branchIds */
        $branchIds = $memberships->pluck('branch_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        /** @var list<string> $permissions */
        $permissions = $memberships
            ->flatMap(fn (TenantUser $m) => $m->role->permissions->pluck('key'))
            ->unique()->sort()->values()->all();

        /** @var list<string> $roles */
        $roles = $memberships->map(fn (TenantUser $m) => $m->role->key)->unique()->values()->all();

        return new MemberAccess(
            isMember: true,
            permissions: $permissions,
            branchIds: $allBranches ? null : $branchIds,
            roles: $roles,
        );
    }
}
