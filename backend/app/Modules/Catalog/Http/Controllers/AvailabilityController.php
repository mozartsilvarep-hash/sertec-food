<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Models\BranchProductAvailability;
use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Esgotado" por unidade. O atendente só altera a(s) unidade(s) dele.
 */
class AvailabilityController
{
    public function index(Request $request, Branch $branch, TenantAccess $access): JsonResponse
    {
        $this->guardBranch($request, $branch, $access);

        $soldOut = BranchProductAvailability::query()
            ->where('branch_id', $branch->id)
            ->where('available', false)
            ->pluck('product_id');

        $ids = Product::query()->whereIn('id', $soldOut)->pluck('public_id');

        return new JsonResponse(['data' => ['branch_id' => $branch->public_id, 'sold_out_product_ids' => $ids->values()]]);
    }

    public function update(Request $request, Branch $branch, Product $product, TenantAccess $access): JsonResponse
    {
        $this->guardBranch($request, $branch, $access);
        $data = $request->validate(['available' => ['required', 'boolean']]);

        BranchProductAvailability::query()->updateOrCreate(
            ['branch_id' => $branch->id, 'product_id' => $product->id],
            ['available' => (bool) $data['available']],
        );

        return new JsonResponse(['data' => [
            'branch_id' => $branch->public_id,
            'product_id' => $product->public_id,
            'available' => (bool) $data['available'],
        ]]);
    }

    private function guardBranch(Request $request, Branch $branch, TenantAccess $access): void
    {
        /** @var User $user */
        $user = $request->user();

        if (! $access->for($user)->canAccessBranch($branch->id)) {
            throw new NotFoundHttpException;
        }
    }
}
