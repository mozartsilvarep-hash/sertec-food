<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Promoções › Vitrine: faixa de aviso (cardápio e site) e sites autorizados
 * a ler o feed de promoções (marketing.manage).
 */
class StorefrontFeedSettingsController
{
    public function show(Request $request, TenantContext $context): JsonResponse
    {
        return new JsonResponse(['data' => $this->present($context->get(), $request)]);
    }

    public function update(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = $context->get();
        $data = $request->validate([
            'announcement' => ['nullable', 'array'],
            'announcement.text' => ['nullable', 'string', 'max:160'],
            'announcement.link' => ['nullable', 'url:https', 'max:300'],
            'announcement.link_label' => ['nullable', 'string', 'max:40'],
            'announcement.starts_at' => ['nullable', 'date'],
            'announcement.ends_at' => ['nullable', 'date'],
            'feed_origins' => ['nullable', 'array', 'max:5'],
            'feed_origins.*' => ['string', 'max:200'],
        ], [], ['announcement.text' => 'texto da faixa', 'announcement.link' => 'link']);

        if (array_key_exists('feed_origins', $data)) {
            $origins = [];
            foreach ($data['feed_origins'] ?? [] as $i => $raw) {
                $o = rtrim(strtolower(trim($raw)), '/');
                if ($o === '') {
                    continue;
                }
                if (! preg_match('#^https://([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}(:\d{2,5})?$#', $o)) {
                    throw ValidationException::withMessages(["feed_origins.{$i}" => 'Use o endereço do site com https e sem caminho, ex.: https://www.seusite.com.br']);
                }
                $origins[] = $o;
            }
            $tenant->feed_origins_json = array_values(array_unique($origins));
        }

        if (array_key_exists('announcement', $data)) {
            $a = $data['announcement'] ?? [];
            $text = trim((string) ($a['text'] ?? ''));
            $toUtc = fn ($v) => empty($v) ? null : CarbonImmutable::parse($v, $tenant->timezone)->utc()->toIso8601String();
            $starts = $toUtc($a['starts_at'] ?? null);
            $ends = $toUtc($a['ends_at'] ?? null);
            if ($starts !== null && $ends !== null && $ends <= $starts) {
                throw ValidationException::withMessages(['announcement.ends_at' => 'O fim precisa ser depois do início.']);
            }
            $tenant->announcement_json = $text === '' ? null : [
                'text' => $text,
                'link' => $a['link'] ?? null,
                'link_label' => ! empty($a['link']) ? ($a['link_label'] ?? 'Saiba mais') : null,
                'starts_at' => $starts,
                'ends_at' => $ends,
            ];
        }

        $tenant->save();
        Cache::forget("storefront-meta:{$tenant->id}");

        return new JsonResponse(['data' => $this->present($tenant, $request)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Tenant $tenant, Request $request): array
    {
        $a = $tenant->announcement_json;
        $local = fn ($v) => empty($v) ? null : CarbonImmutable::parse($v)->setTimezone($tenant->timezone)->format('Y-m-d\TH:i');

        return [
            'announcement' => $a === null ? null : [
                'text' => $a['text'] ?? '',
                'link' => $a['link'] ?? null,
                'link_label' => $a['link_label'] ?? null,
                'starts_at' => $local($a['starts_at'] ?? null),
                'ends_at' => $local($a['ends_at'] ?? null),
            ],
            'announcement_active' => $tenant->activeAnnouncement() !== null,
            'feed_origins' => $tenant->feed_origins_json ?? [],
            'feed_url' => 'https://'.$request->getHost().'/api/v1/vitrine/promocoes.json',
        ];
    }
}
