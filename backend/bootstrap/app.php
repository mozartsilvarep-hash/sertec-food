<?php

use App\Http\Errors\ErrorRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Modules\Identity\Http\Middleware\EnsureMerchantSession;
use App\Modules\Identity\Http\Middleware\RequirePermission;
use App\Modules\Platform\Http\Middleware\EnsurePlatformHost;
use App\Modules\Platform\Http\Middleware\EnsurePlatformSession;
use App\Modules\Platform\Http\Middleware\RequireFeature;
use App\Modules\Tenancy\Http\Controllers\StorefrontShellController;
use App\Modules\Tenancy\Http\Middleware\ResolveTenantFromHost;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Cardápio com meta tags do restaurante (produção, via Caddy).
            Route::get('/__storefront', StorefrontShellController::class)
                ->middleware('throttle:public')->name('storefront.shell');
        },
    )
    ->withCommands([
        __DIR__.'/../app/Modules/Platform/Console',
        __DIR__.'/../app/Modules/Payments/Console',
        __DIR__.'/../app/Modules/Ordering/Console',
        __DIR__.'/../app/Modules/Platform/Billing/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        // Em produção o Caddy fala FastCGI direto com o PHP: REMOTE_ADDR já é o
        // IP real e nenhum proxy precisa ser confiável (confiar em "*" deixaria
        // o cliente forjar X-Forwarded-For e driblar limites por IP). Se houver
        // CDN/balanceador na frente, liste os IPs dele em TRUSTED_PROXIES.
        $proxies = (string) env('TRUSTED_PROXIES', '');
        if ($proxies !== '') {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        // Sessão por cookie host-only (SESSION_DOMAIN vazio): o cookie do
        // tenant A nunca é enviado ao host do tenant B. SPA e API ficam na
        // mesma origem (Nginx), logo não há CORS nem cookie de terceiros.
        $middleware->group('spa', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            PreventRequestForgery::class,
        ]);

        $middleware->alias([
            'tenant' => ResolveTenantFromHost::class,
            'merchant' => EnsureMerchantSession::class,
            'permission' => RequirePermission::class,
            'feature' => RequireFeature::class,
            'platform.host' => EnsurePlatformHost::class,
            'platform' => EnsurePlatformSession::class,
        ]);

        // O tenant precisa estar resolvido antes da sessão, da autenticação
        // e do route model binding (que depende do TenantScope).
        $middleware->prependToPriorityList(before: EncryptCookies::class, prepend: ResolveTenantFromHost::class);
        $middleware->prependToPriorityList(before: EncryptCookies::class, prepend: EnsurePlatformHost::class);

        // Bindings são posicionados explicitamente em routes/api.php (depois
        // do tenant, da sessão e da autenticação).
        $middleware->api(remove: [SubstituteBindings::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                return ErrorRenderer::render($e, $request);
            }

            return null;
        });
    })->create();
