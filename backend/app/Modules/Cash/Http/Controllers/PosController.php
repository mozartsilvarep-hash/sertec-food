<?php

namespace App\Modules\Cash\Http\Controllers;

use App\Http\Errors\ApiException;
use App\Modules\Cash\Enums\CashMethod;
use App\Modules\Cash\Pos\PosSaleService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CatalogReader;
use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Services\LoyaltyService;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Customer;
use App\Modules\Ordering\Models\OrderItem;
use App\Modules\Ordering\Support\Phone;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Support\PublicIds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PDV: catálogo para venda rápida, cliente pelo celular e a venda em si.
 * Serviços com contexto de tenant entram por parâmetro de método.
 */
class PosController
{
    private const MONEY = 'regex:/^\d{1,7}([.,]\d{1,2})?$/';

    /** GET merchant/pos/catalog?branch= — cardápio da unidade + códigos, favoritos e mais vendidos. */
    public function catalog(Request $request, TenantAccess $access, CatalogReader $reader): JsonResponse
    {
        $branch = $this->branch($request, $access);
        $meta = Product::query()->where('active', true)->with(['variants', 'optionGroups'])->get()->keyBy('public_id');
        $top = OrderItem::query()->whereNotNull('product_id')
            ->whereHas('order', fn ($q) => $q->where('branch_id', $branch->id)->where('status', OrderStatus::Completed)->where('placed_at', '>=', now()->subDays(30)))
            ->selectRaw('product_id, SUM(quantity) AS q')->groupBy('product_id')->orderByDesc('q')->limit(12)->toBase()->pluck('product_id');
        $idToPublic = $meta->mapWithKeys(fn (Product $p) => [$p->id => $p->public_id]);

        return new JsonResponse(['data' => [
            'branch_id' => $branch->public_id,
            'menus' => $reader->menus($branch),
            'products' => $meta->map(fn (Product $p) => [
                'code' => $p->code,
                'favorite' => $p->pos_favorite,
                // Um tamanho e nada para escolher: entra com um toque, sem abrir a ficha.
                'quick_variant' => $p->optionGroups->isEmpty() && $p->kind !== Product::KIND_COMBO && ($only = $p->variants->where('active', true))->count() === 1
                    ? $only->first()?->public_id : null,
            ])->all(),
            'top' => $top->map(fn ($id) => $idToPublic[$id] ?? null)->filter()->values(),
            'methods' => array_map(fn (CashMethod $m) => ['method' => $m->value, 'label' => $m->label()], CashMethod::posCases()),
        ]]);
    }

    /** GET merchant/pos/customer?phone= — nome do último pedido e cartão fidelidade. */
    public function customer(Request $request, LoyaltyService $loyalty): JsonResponse
    {
        $phone = Phone::normalize((string) $request->query('phone', ''));
        if ($phone === null) {
            throw new ApiException(422, 'validation_failed', 'Informe um celular com DDD.');
        }
        $customer = Customer::query()->where('phone', $phone)->first();

        return new JsonResponse(['data' => [
            'phone' => Phone::format($phone),
            'name' => $customer?->name,
            'loyalty' => $loyalty->status($phone),
        ]]);
    }

    /** POST merchant/pos/sales — Idempotency-Key obrigatório (clique duplo não vende duas vezes). */
    public function sale(Request $request, TenantAccess $access, PosSaleService $pos): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (! preg_match('/^[A-Za-z0-9-]{16,80}$/', $key)) {
            throw new ApiException(422, 'idempotency_key_required', 'Cabeçalho Idempotency-Key ausente ou inválido.');
        }
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:80'],
            'items.*.product_id' => ['nullable', 'string', 'size:26'],
            'items.*.variant_id' => ['required_with:items.*.product_id', 'nullable', 'string', 'size:26'],
            'items.*.options' => ['nullable', 'array', 'max:60'],
            'items.*.options.*.option' => ['required', 'string', 'size:26'],
            'items.*.options.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.description' => ['required_without:items.*.product_id', 'nullable', 'string', 'max:80'],
            'items.*.unit_price' => ['required_without:items.*.product_id', 'nullable', 'string', self::MONEY],
            'items.*.quantity' => ['required', 'integer', 'between:1,999'],
            'items.*.notes' => ['nullable', 'string', 'max:140'],
            'customer.name' => ['nullable', 'string', 'max:120'],
            'customer.phone' => ['nullable', 'string', 'max:25'],
            'use_loyalty' => ['sometimes', 'boolean'],
            'discount.type' => ['nullable', Rule::in(['amount', 'percent'])],
            'discount.value' => ['nullable', 'string', self::MONEY],
            'service_percent' => ['nullable', 'numeric', 'min:0', 'max:'.PosSaleService::MAX_SERVICE_PERCENT],
            'payments' => ['present', 'array', 'max:6'],
            'payments.*.method' => ['required', Rule::in(array_map(fn (CashMethod $m) => $m->value, CashMethod::posCases()))],
            'payments.*.amount' => ['required', 'string', self::MONEY],
            'cash_received' => ['nullable', 'string', self::MONEY],
            'send_to_kitchen' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:300'],
        ], [], [
            'items' => 'itens', 'items.*.description' => 'descrição', 'items.*.unit_price' => 'valor', 'payments' => 'pagamento',
            'payments.*.amount' => 'valor', 'cash_received' => 'dinheiro recebido', 'service_percent' => 'taxa de serviço', 'discount.value' => 'desconto',
        ]);
        $user = $request->user();
        if (! $user instanceof User) {
            throw ApiException::forbidden();
        }
        $result = $pos->sell($this->branch($request, $access), $user, $key, $data);

        return new JsonResponse($result['body'], $result['status']);
    }

    private function branch(Request $request, TenantAccess $tenantAccess): Branch
    {
        $user = $request->user();
        $access = $tenantAccess->for($user instanceof User ? $user : throw ApiException::forbidden());
        $id = (string) $request->input('branch', $request->query('branch', ''));
        if ($id === '') {
            $branches = Branch::query()->where('active', true)
                ->when($access->branchIds !== null, fn ($q) => $q->whereIn('id', $access->branchIds ?? []))->limit(2)->get();
            if ($branches->count() !== 1) {
                throw new ApiException(422, 'branch_required', 'Escolha a unidade.');
            }

            return $branches->first();
        }
        $branch = PublicIds::one(Branch::class, $id, 'branch');
        if (! $access->canAccessBranch($branch->id)) {
            throw ApiException::forbidden();
        }

        return $branch;
    }
}
