<?php

namespace App\Providers;

use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Payments\Gateway\MercadoPagoGateway;
use App\Modules\Payments\Gateway\PaymentGateway;
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

        $this->app->bind(PaymentGateway::class, MercadoPagoGateway::class);
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

        // Webhooks: o provedor manda rajadas e reenvios; acima disso é abuso.
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(120)->by('webhooks:'.$request->getHost()));

        // Ações que chamam o provedor (gerar Pix de novo, "já paguei", reembolso, credenciais).
        RateLimiter::for('payment-actions', fn (Request $request) => Limit::perMinute(12)->by('pay:'.$request->getHost().'|'.$request->ip()));

        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)->by('uploads:'.$request->user()?->getAuthIdentifier()));
    }
}
