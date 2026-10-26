<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Errors\ApiException;
use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Support\Phone;
use App\Modules\Tenancy\Http\Resources\BranchResource;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Unidades no painel do restaurante: leitura (com escopo do funcionário),
 * edição de dados de contato e criação (plano com várias unidades).
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

    /** POST /merchant/branches (branches.manage + plano com várias unidades). */
    public function store(Request $request): JsonResponse
    {
        $branch = Branch::query()->create($this->validated($request, creating: true));

        return (new BranchResource($branch))->response()->setStatusCode(201);
    }

    /** PATCH /merchant/branches/{branch} — nome, telefone, endereço, ativa. */
    public function update(Request $request, Branch $branch, TenantAccess $tenantAccess): BranchResource
    {
        if (! $tenantAccess->for($this->user($request))->canAccessBranch($branch->id)) {
            throw new NotFoundHttpException;
        }

        $data = $this->validated($request, creating: false);
        if (($data['active'] ?? true) === false && $branch->active
            && Branch::query()->where('active', true)->whereKeyNot($branch->id)->doesntExist()) {
            throw new ApiException(409, 'last_branch', 'É preciso ter ao menos uma unidade ativa.');
        }

        $branch->fill($data)->save();

        return new BranchResource($branch);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:80'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:25'],
            'address' => ['sometimes', 'nullable', 'array'],
            'address.street' => ['nullable', 'string', 'max:150'],
            'address.number' => ['nullable', 'string', 'max:20'],
            'address.neighborhood' => ['nullable', 'string', 'max:80'],
            'address.city' => ['nullable', 'string', 'max:80'],
            'address.state' => ['nullable', 'string', 'size:2'],
            'address.zip' => ['nullable', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'active' => ['sometimes', 'boolean'],
        ], [], ['phone' => 'telefone', 'address.zip' => 'CEP']);

        $out = array_intersect_key($data, array_flip(['name', 'active']));
        if (array_key_exists('phone', $data)) {
            $digits = $data['phone'] === null || $data['phone'] === '' ? null : Phone::normalize($data['phone']);
            if ($data['phone'] !== null && $data['phone'] !== '' && $digits === null) {
                throw ValidationException::withMessages(['phone' => 'Informe o telefone com DDD.']);
            }
            $out['phone'] = $digits === null ? null : Phone::format($digits);
        }
        if (array_key_exists('address', $data)) {
            $out['address_json'] = $data['address'] === null ? null : array_filter(
                array_intersect_key($data['address'], array_flip(['street', 'number', 'neighborhood', 'city', 'state', 'zip'])),
                fn ($v) => $v !== null && $v !== '',
            );
        }

        return $out;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
