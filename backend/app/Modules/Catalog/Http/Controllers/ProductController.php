<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Requests\ProductRequest;
use App\Modules\Catalog\Http\Resources\ProductResource;
use App\Modules\Catalog\Images\ProductImageStore;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductWriter;
use App\Modules\Tenancy\Support\PublicIds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;

class ProductController
{
    /**
     * Paginação por cursor (catálogos grandes — API.md).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Product::query()->with(['variants', 'optionGroups', 'placements.category'])->orderBy('name')->orderBy('id');

        if ($request->filled('search')) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $request->query('search')).'%';
            $query->where('name', 'like', $term);
        }

        if ($request->filled('category')) {
            $category = PublicIds::one(Category::class, (string) $request->query('category'), 'category');
            $query->whereHas('placements', fn ($q) => $q->where('category_id', $category->id));
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', '50')));

        return ProductResource::collection($query->cursorPaginate($perPage)->withQueryString());
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($product->load(['variants', 'optionGroups', 'placements.category']));
    }

    public function store(ProductRequest $request, ProductWriter $writer): JsonResponse
    {
        /** @var array{name: string, variants: list<array{id?: string|null, name: string, price: string, active?: bool}>} $data */
        $data = $request->validated();

        return (new ProductResource($writer->save(null, $data)))->response()->setStatusCode(201);
    }

    public function update(ProductRequest $request, Product $product, ProductWriter $writer): ProductResource
    {
        /** @var array{name: string, variants: list<array{id?: string|null, name: string, price: string, active?: bool}>} $data */
        $data = $request->validated();

        return new ProductResource($writer->save($product, $data));
    }

    /**
     * Arquiva (soft delete): pedidos antigos mantêm snapshot do produto.
     */
    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return new JsonResponse(null, 204);
    }

    public function uploadImage(Request $request, Product $product, ProductImageStore $images): ProductResource
    {
        $request->validate(['image' => ['required', 'file']]);

        /** @var UploadedFile $file */
        $file = $request->file('image');
        $images->store($product, $file);

        return new ProductResource($product->load(['variants', 'optionGroups', 'placements.category']));
    }

    public function deleteImage(Product $product, ProductImageStore $images): JsonResponse
    {
        $images->remove($product);

        return new JsonResponse(null, 204);
    }
}
