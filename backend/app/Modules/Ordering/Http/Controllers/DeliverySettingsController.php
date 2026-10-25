<?php

namespace App\Modules\Ordering\Http\Controllers;

use App\Modules\Catalog\Http\Requests\ProductRequest;
use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Models\DeliveryZone;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Retirada/entrega e zonas por bairro de uma unidade.
 */
class DeliverySettingsController
{
    public function show(Request $request, Branch $branch, TenantAccess $access): JsonResponse
    {
        $this->guard($request, $branch, $access);

        return new JsonResponse(['data' => self::present($branch)]);
    }

    /**
     * Substitui configurações e zonas (formulário único no painel).
     */
    public function update(Request $request, Branch $branch, TenantAccess $access): JsonResponse
    {
        $this->guard($request, $branch, $access);

        $data = $request->validate([
            'pickup_enabled' => ['required', 'boolean'],
            'delivery_enabled' => ['required', 'boolean'],
            'pickup_eta_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'zones' => ['present', 'array', 'max:60'],
            'zones.*.id' => ['nullable', 'string', 'size:26'],
            'zones.*.name' => ['required', 'string', 'max:80'],
            'zones.*.neighborhoods' => ['required', 'array', 'min:1', 'max:80'],
            'zones.*.neighborhoods.*' => ['required', 'string', 'max:80'],
            'zones.*.fee' => ['required', 'string', ProductRequest::MONEY],
            'zones.*.minimum_order' => ['nullable', 'string', ProductRequest::MONEY],
            'zones.*.free_above' => ['nullable', 'string', ProductRequest::MONEY],
            'zones.*.eta_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'zones.*.active' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($branch, $data) {
            $branch->update([
                'pickup_enabled' => $data['pickup_enabled'],
                'delivery_enabled' => $data['delivery_enabled'],
                'pickup_eta_minutes' => $data['pickup_eta_minutes'],
            ]);

            $existing = DeliveryZone::query()->where('branch_id', $branch->id)->get()->keyBy('public_id');
            $keep = [];

            foreach ($data['zones'] as $position => $zoneData) {
                $zone = isset($zoneData['id']) ? $existing->get($zoneData['id']) : null;
                if (isset($zoneData['id']) && $zone === null) {
                    throw ValidationException::withMessages(["zones.{$position}.id" => 'Zona não pertence a esta unidade.']);
                }
                $zone ??= new DeliveryZone(['branch_id' => $branch->id]);
                $zone->fill([
                    'name' => $zoneData['name'],
                    'neighborhoods_json' => array_values(array_unique(array_map('trim', $zoneData['neighborhoods']))),
                    'fee' => $zoneData['fee'],
                    'minimum_order' => $zoneData['minimum_order'] ?? null,
                    'free_above' => $zoneData['free_above'] ?? null,
                    'eta_minutes' => $zoneData['eta_minutes'],
                    'active' => $zoneData['active'] ?? true,
                    'sort_order' => $position,
                ])->save();
                $keep[] = $zone->id;
            }

            DeliveryZone::query()->where('branch_id', $branch->id)->whereNotIn('id', $keep)->delete();
        });

        return new JsonResponse(['data' => self::present($branch->refresh())]);
    }

    /**
     * Usado também pelo /storefront (só zonas ativas).
     *
     * @return array<string, mixed>
     */
    public static function present(Branch $branch, bool $onlyActive = false): array
    {
        $zones = DeliveryZone::query()->where('branch_id', $branch->id)
            ->when($onlyActive, fn ($q) => $q->where('active', true))
            ->orderBy('sort_order')->orderBy('id')->get();

        return [
            'branch_id' => $branch->public_id,
            'pickup_enabled' => $branch->pickup_enabled,
            'delivery_enabled' => $branch->delivery_enabled,
            'pickup_eta_minutes' => $branch->pickup_eta_minutes,
            'zones' => $zones->map(fn (DeliveryZone $z) => [
                'id' => $z->public_id,
                'name' => $z->name,
                'neighborhoods' => $z->neighborhoods_json,
                'fee' => $z->fee,
                'minimum_order' => $z->minimum_order,
                'free_above' => $z->free_above,
                'eta_minutes' => $z->eta_minutes,
                'active' => $z->active,
            ])->values()->all(),
        ];
    }

    private function guard(Request $request, Branch $branch, TenantAccess $access): void
    {
        /** @var User $user */
        $user = $request->user();
        if (! $access->for($user)->canAccessBranch($branch->id)) {
            throw new NotFoundHttpException;
        }
    }
}
