<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Requests\CategoryRequest;
use App\Modules\Catalog\Http\Resources\CategoryResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Menu;
use App\Modules\Tenancy\Support\PublicIds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Category::query()->with('menu')->withCount('placements')->orderBy('sort_order')->orderBy('id');

        if ($request->filled('menu')) {
            $query->where('menu_id', PublicIds::one(Menu::class, (string) $request->query('menu'), 'menu')->id);
        }

        return CategoryResource::collection($query->get());
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $category = Category::query()->create($this->attributes($request));

        return (new CategoryResource($category->load('menu')))->response()->setStatusCode(201);
    }

    public function update(CategoryRequest $request, Category $category): CategoryResource
    {
        $category->update($this->attributes($request));

        return new CategoryResource($category->load('menu'));
    }

    public function destroy(Category $category): JsonResponse
    {
        // Remove só a categoria e as posições; os produtos continuam existindo.
        $category->delete();

        return new JsonResponse(null, 204);
    }

    /** @return array<string, mixed> */
    private function attributes(CategoryRequest $request): array
    {
        $data = $request->safe()->except('menu_id');

        if ($request->has('menu_id')) {
            $data['menu_id'] = PublicIds::one(Menu::class, (string) $request->input('menu_id'), 'menu_id')->id;
        }

        return $data;
    }
}
