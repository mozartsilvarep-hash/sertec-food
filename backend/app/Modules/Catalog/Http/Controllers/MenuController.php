<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Requests\MenuRequest;
use App\Modules\Catalog\Http\Resources\MenuResource;
use App\Modules\Catalog\Models\Menu;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Support\PublicIds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MenuController
{
    public function index(): AnonymousResourceCollection
    {
        return MenuResource::collection(Menu::query()->with('branch')->orderBy('sort_order')->orderBy('id')->get());
    }

    public function store(MenuRequest $request): JsonResponse
    {
        $menu = Menu::query()->create($this->attributes($request));

        return (new MenuResource($menu->load('branch')))->response()->setStatusCode(201);
    }

    public function update(MenuRequest $request, Menu $menu): MenuResource
    {
        $menu->update($this->attributes($request));

        return new MenuResource($menu->load('branch'));
    }

    public function destroy(Menu $menu): JsonResponse
    {
        $menu->delete();

        return new JsonResponse(null, 204);
    }

    /** @return array<string, mixed> */
    private function attributes(MenuRequest $request): array
    {
        $data = $request->safe()->except('branch_id');

        if ($request->has('branch_id')) {
            $branchId = $request->input('branch_id');
            $data['branch_id'] = $branchId === null ? null : PublicIds::one(Branch::class, (string) $branchId, 'branch_id')->id;
        }

        return $data;
    }
}
