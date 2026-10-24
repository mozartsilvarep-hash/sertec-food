<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Http\Resources\BranchResource;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Leitura de unidades no painel do restaurante (Sprint 0: alvo dos testes
 * de isolamento HTTP; CRUD completo entra em sprint futura).
 *
 * TenantAccess é "scoped": recebido por injeção no método, nunca no construtor.
 */
class BranchController
{
    public function index(Request $request, TenantAccess $tenantAccess): AnonymousResourceCollection
    {
        $access = $tenantAccess->for($this->user($request));

        $branches = Branch::query()
            ->when($access->branchIds !== null, fn ($q) => $q->whereIn('id', $access->branchIds ?? []))
            ->orderBy('name')
            ->get();

        return BranchResource::collection($branches);
    }

    public function show(Request $request, Branch $branch, TenantAccess $tenantAccess): BranchResource
    {
        // Unidade fora do escopo do funcionário: 404 (não revela existência).
        if (! $tenantAccess->for($this->user($request))->canAccessBranch($branch->id)) {
            throw new NotFoundHttpException;
        }

        return new BranchResource($branch);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
