<?php

namespace App\Providers;

use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Platform\Features\FeatureGate;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // "scoped": nova instância por requisição e por job do worker.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(TenantAccess::class);
        $this->app->scoped(FeatureGate::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Por e-mail + IP + host: limita força bruta sem bloquear o
        // restaurante inteiro por causa de um IP compartilhado.
        RateLimiter::for('login', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('login:'.$request->getHost().'|'.$email.'|'.$request->ip()),
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
            ];
        });

        // Cardápio público: generoso para clientes reais, barra raspagem em massa.
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(240)->by('public:'.$request->ip()));

        // Checkout: evita abuso/criação em massa de pedidos falsos.
        RateLimiter::for('checkout', fn (Request $request) => [
            Limit::perMinute(10)->by('checkout:'.$request->getHost().'|'.$request->ip()),
        ]);

        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)->by('uploads:'.$request->user()?->getAuthIdentifier()));
    }
}
