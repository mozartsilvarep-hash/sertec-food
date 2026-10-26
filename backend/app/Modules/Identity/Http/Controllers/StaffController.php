<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Errors\ApiException;
use App\Modules\Identity\Auth\SessionTerminator;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Accounts\AccountSharing;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Support\PublicIds;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Equipe do estabelecimento (staff.manage — proprietário).
 *
 * Regras de segurança:
 *  - proprietários são definidos pela SerTec, não por aqui;
 *  - ninguém altera o próprio vínculo;
 *  - a conta (e-mail/senha) é global: só é criada ou redefinida aqui se for
 *    EXCLUSIVA deste estabelecimento — um restaurante nunca troca a senha de
 *    alguém que também trabalha em outro.
 */
class StaffController
{
    private const ASSIGNABLE = [Role::MANAGER, Role::ATTENDANT, Role::KITCHEN];

    public function index(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        $memberships = TenantUser::query()->with(['user', 'role', 'branch'])->orderBy('id')->get();

        return new JsonResponse(['data' => $memberships->groupBy('user_id')->map(
            fn (Collection $group) => $this->present($group->first(), $me),
        )->values()]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $this->validated($request, creating: true);
        $email = Str::lower(trim($data['email']));

        $user = User::query()->where('email', $email)->first();
        if ($user?->isPlatformStaff()) {
            throw new ApiException(409, 'email_unavailable', 'Este e-mail não pode ser usado.');
        }
        if ($user !== null && TenantUser::query()->where('user_id', $user->id)->exists()) {
            throw new ApiException(409, 'already_member', 'Esta pessoa já faz parte da equipe.');
        }

        $password = null;
        $membership = DB::transaction(function () use ($user, $email, $data, &$password) {
            if ($user === null) {
                $password = Str::password(12, symbols: false);
                $user = User::query()->create(['name' => $data['name'], 'email' => $email, 'password' => $password]);
            }

            return TenantUser::query()->create([
                'user_id' => $user->id,
                'role_id' => $this->roleId($data['role']),
                'branch_id' => $this->branchId($data['branch_id'] ?? null),
                'active' => true,
            ]);
        });

        $audit->record('staff.added', $membership->user, ['role' => $data['role'], 'new_account' => $password !== null]);

        /** @var User $me */
        $me = $request->user();

        return new JsonResponse(['data' => [
            ...$this->present($membership->load(['user', 'role', 'branch']), $me),
            // Mostrada UMA vez. null = a pessoa já tinha conta e usa a senha dela.
            'temporary_password' => $password,
        ]], 201);
    }

    public function update(Request $request, string $user, AuditLogger $audit): JsonResponse
    {
        $membership = $this->editable($request, $user);
        $data = $this->validated($request, creating: false);

        $changes = [];
        if (isset($data['role'])) {
            $membership->role_id = $this->roleId($data['role']);
            $changes['role'] = $data['role'];
        }
        if (array_key_exists('branch_id', $data)) {
            $membership->branch_id = $this->branchId($data['branch_id']);
            $changes['branch'] = $data['branch_id'];
        }
        if (isset($data['active'])) {
            $membership->active = (bool) $data['active'];
            $changes['active'] = (bool) $data['active'];
        }
        $membership->save();

        if (($changes['active'] ?? true) === false) {
            SessionTerminator::forUser($membership->user_id);
        }
        $audit->record('staff.updated', $membership->user, $changes);

        /** @var User $me */
        $me = $request->user();

        return new JsonResponse(['data' => $this->present($membership->load(['user', 'role', 'branch']), $me)]);
    }

    public function destroy(Request $request, string $user, AuditLogger $audit): JsonResponse
    {
        $membership = $this->editable($request, $user);
        $target = $membership->user;
        TenantUser::query()->where('user_id', $membership->user_id)->delete();
        $audit->record('staff.removed', $target);

        return new JsonResponse(null, 204);
    }

    public function resetPassword(Request $request, string $user, AuditLogger $audit, AccountSharing $sharing): JsonResponse
    {
        $membership = $this->editable($request, $user);
        $target = $membership->user;

        if ($sharing->isSharedOutside($target, $membership->tenant_id)) {
            throw new ApiException(409, 'shared_account', 'Esta conta também é usada em outro estabelecimento. A própria pessoa deve trocar a senha, ou peça ajuda ao suporte SerTec.');
        }

        $password = Str::password(12, symbols: false);
        $target->forceFill(['password' => $password, 'remember_token' => null])->save();
        SessionTerminator::forUser($target->id);
        $audit->record('staff.password_reset', $target);

        return new JsonResponse(['data' => ['email' => $target->email, 'temporary_password' => $password]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'prohibited', 'string', 'min:2', 'max:120'],
            'email' => [$creating ? 'required' : 'prohibited', 'email', 'max:191'],
            'role' => [$creating ? 'required' : 'sometimes', Rule::in(self::ASSIGNABLE)],
            'branch_id' => ['nullable', 'string', 'size:26'],
            'active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
        ], [], ['role' => 'função', 'branch_id' => 'unidade']);
    }

    private function editable(Request $request, string $userPublicId): TenantUser
    {
        /** @var User $me */
        $me = $request->user();
        $target = User::query()->where('public_id', $userPublicId)->first();
        $memberships = $target === null ? collect() : TenantUser::query()->with(['role', 'user'])->where('user_id', $target->id)->orderBy('id')->get();

        if ($memberships->isEmpty()) {
            throw new ApiException(404, 'not_found', 'Pessoa não encontrada na equipe.');
        }
        if ($target?->id === $me->id) {
            throw new ApiException(409, 'self_change', 'Você não pode alterar o próprio acesso.');
        }
        if ($memberships->contains(fn (TenantUser $m) => $m->role->key === Role::OWNER)) {
            throw new ApiException(409, 'owner_protected', 'Proprietários são alterados pelo suporte SerTec.');
        }

        // Um vínculo por pessoa: consolida duplicatas antigas.
        $first = $memberships->shift();
        $memberships->each(fn (TenantUser $m) => $m->delete());

        return $first;
    }

    private function roleId(string $key): int
    {
        return (int) Role::query()->whereNull('tenant_id')->where('key', $key)->value('id');
    }

    private function branchId(?string $publicId): ?int
    {
        return $publicId === null ? null : PublicIds::one(Branch::class, $publicId, 'branch_id')->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TenantUser $m, User $me): array
    {
        return [
            'id' => $m->user->public_id,
            'name' => $m->user->name,
            'email' => $m->user->email,
            'role' => $m->role->key,
            'role_name' => $m->role->name,
            'branch' => $m->branch === null ? null : ['id' => $m->branch->public_id, 'name' => $m->branch->name],
            'active' => $m->active && $m->user->active,
            'last_login_at' => $m->user->last_login_at?->toIso8601String(),
            'is_self' => $m->user_id === $me->id,
            'is_owner' => $m->role->key === Role::OWNER,
        ];
    }
}
