<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CatalogReader;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /api/v1/menus?branch=  e  GET /api/v1/products/{id}?branch=
 * Sem login. Unidade padrão: a primeira unidade ativa do estabelecimento.
 */
class PublicCatalogController
{
    public function menus(Request $request, CatalogReader $reader): JsonResponse
    {
        $branch = self::branch($request);

        return new JsonResponse(['data' => [
            'branch_id' => $branch->public_id,
            'menus' => $reader->menus($branch),
        ]]);
    }

    public function product(Request $request, Product $product, CatalogReader $reader): JsonResponse
    {
        if (! $product->active) {
            throw new NotFoundHttpException;
        }

        return new JsonResponse(['data' => $reader->product($product, self::branch($request))]);
    }

    public static function branch(Request $request): Branch
    {
        $query = Branch::query()->where('active', true);

        $branch = $request->filled('branch')
            ? $query->where('public_id', (string) $request->query('branch'))->first()
            : $query->orderBy('id')->first();

        return $branch ?? throw new NotFoundHttpException;
    }
}
