<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Errors\ApiException;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Promotion;
use App\Modules\Catalog\Services\CatalogReader;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Support\PublicIds;
use App\Modules\Tenancy\TenantContext;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Promoções do cardápio (marketing.manage). O desconto é percentual; o
 * painel pode informar o preço promocional desejado e o servidor converte em
 * percentual sobre o "a partir de" do produto.
 */
class PromotionController
{
    public function index(TenantContext $context): JsonResponse
    {
        $promotions = Promotion::query()->with('product')->orderByDesc('id')->get();

        return new JsonResponse(['data' => $promotions->map(fn (Promotion $p) => self::present($p, $context->get()->timezone))->values()]);
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $promotion = new Promotion;
        $this->fill($promotion, $request, $context->get()->timezone, creating: true);
        $promotion->save();
        Cache::forget("storefront-meta:{$context->id()}");

        return new JsonResponse(['data' => self::present($promotion->load('product'), $context->get()->timezone)], 201);
    }

    public function update(Request $request, Promotion $promotion, TenantContext $context): JsonResponse
    {
        $this->fill($promotion, $request, $context->get()->timezone, creating: false);
        $promotion->save();

        return new JsonResponse(['data' => self::present($promotion->load('product'), $context->get()->timezone)]);
    }

    public function destroy(Promotion $promotion): JsonResponse
    {
        $promotion->delete();

        return new JsonResponse(null, 204);
    }

    private function fill(Promotion $promotion, Request $request, string $timezone, bool $creating): void
    {
        $data = $request->validate([
            'product_id' => [$creating ? 'required' : 'sometimes', 'string', 'size:26'],
            'percent' => ['nullable', 'string', 'regex:/^\d{1,2}([.,]\d{1,2})?$/'],
            'promo_price' => ['nullable', 'string', 'regex:/^\d{1,6}([.,]\d{1,2})?$/'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'featured' => ['sometimes', 'boolean'],
            'badge' => ['nullable', 'string', 'max:18'],
            'active' => ['sometimes', 'boolean'],
        ], [], ['percent' => 'desconto', 'promo_price' => 'preço promocional', 'ends_at' => 'fim']);

        if (isset($data['product_id'])) {
            $promotion->product_id = PublicIds::one(Product::class, $data['product_id'], 'product_id')->id;
        }
        /** @var Product $product */
        $product = Product::query()->with(['variants', 'optionGroups.options.variantPrices'])->findOrFail($promotion->product_id);

        if (! empty($data['promo_price'])) {
            $from = $this->priceFrom($product);
            $target = Money::toCents(str_replace(',', '.', $data['promo_price']));
            if ($from === null || $target <= 0 || $target >= $from) {
                throw ValidationException::withMessages(['promo_price' => 'O preço promocional precisa ser menor que o preço atual ('.($from === null ? '—' : 'R$ '.str_replace('.', ',', Money::format($from))).').']);
            }
            $promotion->percent_bp = (int) round((1 - $target / $from) * 10000);
        } elseif (! empty($data['percent'])) {
            $promotion->percent_bp = (int) round((float) str_replace(',', '.', $data['percent']) * 100);
        } elseif ($creating) {
            throw ValidationException::withMessages(['percent' => 'Informe o desconto ou o preço promocional.']);
        }
        if ($promotion->percent_bp < 1 || $promotion->percent_bp > 9000) {
            throw ValidationException::withMessages(['percent' => 'O desconto vai de 0,01% a 90%.']);
        }

        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $promotion->{$field} = $data[$field] === null ? null : Carbon::parse($data[$field], $timezone)->utc();
            }
        }
        if ($promotion->starts_at !== null && $promotion->ends_at !== null && $promotion->ends_at->lte($promotion->starts_at)) {
            throw ValidationException::withMessages(['ends_at' => 'O fim precisa ser depois do início.']);
        }
        foreach (['featured', 'active'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $promotion->{$flag} = (bool) $data[$flag];
            }
        }
        if (array_key_exists('badge', $data)) {
            $promotion->badge = $data['badge'] === null ? null : trim($data['badge']);
        }
        $promotion->active ??= true;
        $promotion->featured ??= false;

        if ($promotion->active && $this->overlaps($promotion)) {
            throw new ApiException(409, 'promotion_overlap', 'Este produto já tem outra promoção ativa nesse período.');
        }
    }

    private function overlaps(Promotion $p): bool
    {
        // [s1, e1) e [s2, e2) se cruzam se e1 > s2 e s1 < e2 (nulo = sem limite).
        $query = Promotion::query()
            ->where('product_id', $p->product_id)->where('active', true)
            ->when($p->exists, fn ($q) => $q->whereKeyNot($p->id));
        if ($p->starts_at !== null) {
            $query->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $p->starts_at));
        }
        if ($p->ends_at !== null) {
            $query->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<', $p->ends_at));
        }

        return $query->exists();
    }

    private function priceFrom(Product $product): ?int
    {
        $branch = Branch::query()->orderBy('id')->first();
        if ($branch === null) {
            return null;
        }
        $summary = app(CatalogReader::class)->product($product, $branch);

        return $summary['price_from'] === null ? null : Money::toCents((string) $summary['price_from']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Promotion $p, string $timezone): array
    {
        $now = now();
        $state = ! $p->active ? 'paused'
            : (($p->starts_at !== null && $p->starts_at->gt($now)) ? 'scheduled'
            : (($p->ends_at !== null && $p->ends_at->lte($now)) ? 'ended' : 'running'));

        return [
            'id' => $p->public_id,
            'product' => ['id' => $p->product->public_id, 'name' => $p->product->name],
            'percent' => rtrim(rtrim(number_format($p->percent_bp / 100, 2, '.', ''), '0'), '.'),
            'label' => $p->label(),
            'badge' => $p->badge,
            'featured' => $p->featured,
            'active' => $p->active,
            'state' => $state,
            'starts_at' => $p->starts_at?->setTimezone($timezone)->format('Y-m-d\TH:i'),
            'ends_at' => $p->ends_at?->setTimezone($timezone)->format('Y-m-d\TH:i'),
        ];
    }
}
