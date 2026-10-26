<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Images\ProductImageStore;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Promotion;
use App\Modules\Catalog\Services\CatalogReader;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\TenantContext;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * GET /api/v1/vitrine/promocoes.json — feed público de promoções e da faixa
 * de aviso, no "contrato v1" lido pelo site institucional do restaurante
 * (ex.: yriscakes.com.br). Sem dados de clientes nem cookies.
 *
 * CORS: Access-Control-Allow-Origin só para os sites que o restaurante
 * cadastrou em Promoções › Vitrine (correspondência exata da origem).
 */
class StorefrontFeedController
{
    public const MAX_ITEMS = 20;

    public function __invoke(Request $request, TenantContext $context, CatalogReader $reader): JsonResponse
    {
        $tenant = $context->get();
        $base = $this->baseUrl($tenant, $request);
        $tz = $tenant->timezone;
        $iso = fn (?Carbon $d) => $d?->copy()->setTimezone($tz)->toIso8601String();
        $branch = Branch::query()->where('active', true)->orderBy('id')->first();

        $promotions = Promotion::query()->running()->with('product')->get()
            ->filter(fn (Promotion $p) => $p->product->active && ! $p->product->trashed())
            ->sortBy([
                fn (Promotion $a, Promotion $b) => (int) $b->featured <=> (int) $a->featured,
                fn (Promotion $a, Promotion $b) => ($a->ends_at?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->ends_at?->getTimestamp() ?? PHP_INT_MAX),
            ])
            ->take(self::MAX_ITEMS);

        $items = [];
        foreach ($promotions as $promotion) {
            /** @var Product $product */
            $product = $promotion->product;
            $summary = $branch === null ? null : $reader->product($product, $branch);
            if ($summary === null || $summary['price_from'] === null) {
                continue;
            }
            $from = Money::toCents((string) $summary['price_from']);
            $image = ProductImageStore::url($product->image_key);

            $items[] = array_filter([
                'id' => $product->public_id,
                'titulo' => mb_substr($product->name, 0, 90),
                'descricao' => $product->description ? mb_substr($product->description, 0, 220) : null,
                'imagem' => $image ? $base.$image : null,
                'imagemAlt' => $image ? mb_substr($product->name, 0, 120) : null,
                'precoDe' => round($from / 100, 2),
                'precoPor' => round(Promotion::apply($from, $promotion->percent_bp) / 100, 2),
                'selo' => mb_substr($promotion->label(), 0, 18),
                'url' => $base.'/?produto='.$product->public_id,
                'inicio' => $iso($promotion->starts_at),
                'fim' => $iso($promotion->ends_at),
                'destaque' => $promotion->featured,
            ], fn ($v) => $v !== null);
        }

        $announcement = $tenant->activeAnnouncement();
        $body = array_filter([
            'versao' => 1,
            'atualizadoEm' => Carbon::now()->setTimezone($tz)->toIso8601String(),
            'loja' => ['aberta' => true],
            'faixa' => $announcement === null ? null : array_filter([
                'texto' => mb_substr($announcement['text'], 0, 160),
                'link' => $announcement['link'] ?? null,
                'rotuloLink' => $announcement['link_label'] ?? null,
                'inicio' => $announcement['starts_at'] ? Carbon::parse($announcement['starts_at'])->setTimezone($tz)->toIso8601String() : null,
                'fim' => $announcement['ends_at'] ? Carbon::parse($announcement['ends_at'])->setTimezone($tz)->toIso8601String() : null,
            ], fn ($v) => $v !== null && $v !== ''),
            'promocoes' => $items,
        ], fn ($v) => $v !== null);

        $response = new JsonResponse($body, 200, [
            'Cache-Control' => 'public, max-age=60',
            'Vary' => 'Origin',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');

        $origin = (string) $request->headers->get('Origin', '');
        if ($origin !== '' && in_array($origin, $tenant->feed_origins_json ?? [], true)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }

        return $response;
    }

    /** Endereço público do cardápio: domínio principal (HTTPS) ou o host atual em desenvolvimento. */
    private function baseUrl(Tenant $tenant, Request $request): string
    {
        $primary = TenantDomain::query()->where('is_primary', true)->whereNotNull('verified_at')->value('hostname');

        return $request->isSecure() && is_string($primary) ? 'https://'.$primary : $request->getSchemeAndHttpHost();
    }
}
