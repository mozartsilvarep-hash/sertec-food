<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Catalog\Images\ProductImageStore;
use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Http\Middleware\ResolveTenantFromHost;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\BrandImageStore;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * HTML do cardápio (SPA) com título, descrição e Open Graph do restaurante
 * já no servidor: o link compartilhado no WhatsApp/Instagram mostra o nome,
 * a descrição e uma foto. Em produção o Caddy entrega aqui toda navegação do
 * cardápio que não é arquivo estático; em desenvolvimento quem serve é o Vite.
 */
class StorefrontShellController
{
    public function __invoke(Request $request, TenantContext $context): Response
    {
        $path = (string) config('tenancy.storefront_index');
        $html = is_file($path) ? (string) file_get_contents($path) : null;
        if ($html === null || $html === '') {
            return new Response('Cardápio indisponível.', 503, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $tenant = ResolveTenantFromHost::lookup($request->getHost());
        if ($tenant !== null && $tenant->status->isOperational()) {
            $meta = Cache::remember("storefront-meta:{$tenant->id}", 300, fn () => $context->run($tenant, fn () => $this->meta($tenant, $request)));
            $html = self::inject($html, $meta);
        } else {
            $html = self::inject($html, ['title' => 'Cardápio indisponível', 'description' => '', 'image' => null, 'url' => null, 'color' => null, 'robots' => 'noindex']);
        }

        return new Response($html, $tenant === null ? 404 : 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * @param  array{title: string, description: string, image: string|null, icon?: string|null, url: string|null, color: string|null, robots: string}  $meta
     */
    public static function inject(string $html, array $meta): string
    {
        $e = fn (?string $v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tags = [
            '<meta name="robots" content="'.$e($meta['robots']).'" />',
            '<meta property="og:type" content="website" />',
            '<meta property="og:title" content="'.$e($meta['title']).'" />',
            '<meta property="og:description" content="'.$e($meta['description']).'" />',
            '<meta property="og:locale" content="pt_BR" />',
            '<meta name="twitter:card" content="'.($meta['image'] ? 'summary_large_image' : 'summary').'" />',
        ];
        if ($meta['url']) {
            $tags[] = '<meta property="og:url" content="'.$e($meta['url']).'" />';
            $tags[] = '<link rel="canonical" href="'.$e($meta['url']).'" />';
        }
        if ($meta['image']) {
            $tags[] = '<meta property="og:image" content="'.$e($meta['image']).'" />';
        }
        if (! empty($meta['icon'])) {
            $tags[] = '<link rel="icon" href="'.$e($meta['icon']).'" type="image/webp" />';
            $tags[] = '<link rel="apple-touch-icon" href="'.$e($meta['icon']).'" />';
        }
        if ($meta['color']) {
            $tags[] = '<meta name="theme-color" content="'.$e($meta['color']).'" />';
        }

        $html = (string) preg_replace('#<title>.*?</title>#s', '<title>'.$e($meta['title']).'</title>', $html, 1);
        $html = (string) preg_replace('#<meta name="description" content="[^"]*"\s*/?>#', '<meta name="description" content="'.$e($meta['description']).'" />', $html, 1);

        return str_replace('</head>', '    '.implode("\n    ", $tags)."\n  </head>", $html);
    }

    /**
     * @return array{title: string, description: string, image: string|null, icon: string|null, url: string, color: string|null, robots: string}
     */
    private function meta(Tenant $tenant, Request $request): array
    {
        $base = $request->getSchemeAndHttpHost();
        // Prévia do link: capa da loja; sem capa, a primeira foto de produto; sem nada, a logo.
        $image = $tenant->cover_key !== null ? BrandImageStore::url($tenant->cover_key)
            : (($key = Product::query()->whereNotNull('image_key')->where('active', true)->orderBy('id')->value('image_key')) !== null
                ? ProductImageStore::url((string) $key) : BrandImageStore::url($tenant->logo_key));

        return [
            'title' => $tenant->name.' · Cardápio e pedidos',
            'description' => mb_substr($tenant->description ?: "Peça online no {$tenant->name}: cardápio completo, entrega e retirada.", 0, 200),
            'image' => $image !== null ? $base.$image : null,
            'icon' => $tenant->logo_key !== null ? $base.BrandImageStore::url($tenant->logo_key) : null,
            'url' => $base.'/',
            'color' => $tenant->brand_color,
            'robots' => 'index,follow',
        ];
    }
}
