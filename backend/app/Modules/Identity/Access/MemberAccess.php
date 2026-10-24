<?php

namespace App\Modules\Identity\Access;

/**
 * Permissões efetivas de um usuário no tenant atual.
 *
 * branchIds === null  → acesso a todas as unidades do tenant
 * branchIds === [...] → acesso restrito a essas unidades
 */
final class MemberAccess
{
    /**
     * @param  list<string>  $permissions
     * @param  list<int>|null  $branchIds
     * @param  list<string>  $roles
     */
    public function __construct(
        public readonly bool $isMember,
        public readonly array $permissions = [],
        public readonly ?array $branchIds = [],
        public readonly array $roles = [],
    ) {}

    public static function none(): self
    {
        return new self(false);
    }

    public function can(string $permission): bool
    {
        return $this->isMember && in_array($permission, $this->permissions, true);
    }

    public function canAccessBranch(int $branchId): bool
    {
        if (! $this->isMember) {
            return false;
        }

        return $this->branchIds === null || in_array($branchId, $this->branchIds, true);
    }

    public function hasAllBranches(): bool
    {
        return $this->isMember && $this->branchIds === null;
    }
}
