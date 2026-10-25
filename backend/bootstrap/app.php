<?php

use App\Http\Errors\ErrorRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Modules\Identity\Http\Middleware\EnsureMerchantSession;
use App\Modules\Identity\Http\Middleware\RequirePermission;
use App\Modules\Platform\Http\Middleware\EnsurePlatformHost;
use App\Modules\Platform\Http\Middleware\EnsurePlatformSession;
use App\Modules\Platform\Http\Middleware\RequireFeature;
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

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Modules/Platform/Console',
        __DIR__.'/../app/Modules/Payments/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->trustProxies(at: '*');

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
