<?php

use App\Modules\Cash\Http\Controllers\CashController;
use App\Modules\Catalog\Http\Controllers\AvailabilityController;
use App\Modules\Catalog\Http\Controllers\CategoryController;
use App\Modules\Catalog\Http\Controllers\MenuController;
use App\Modules\Catalog\Http\Controllers\OptionGroupController;
use App\Modules\Catalog\Http\Controllers\ProductController;
use App\Modules\Catalog\Http\Controllers\PromotionController;
use App\Modules\Catalog\Http\Controllers\PublicCatalogController;
use App\Modules\Catalog\Http\Controllers\StorefrontFeedController;
use App\Modules\Catalog\Http\Controllers\StorefrontFeedSettingsController;
use App\Modules\Identity\Http\Controllers\AccountController;
use App\Modules\Identity\Http\Controllers\MerchantAuthController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Identity\Http\Controllers\StaffController;
use App\Modules\Identity\Permissions as P;
use App\Modules\Loyalty\Http\Controllers\LoyaltyController;
use App\Modules\Ordering\Http\Controllers\CartController;
use App\Modules\Ordering\Http\Controllers\CheckoutController;
use App\Modules\Ordering\Http\Controllers\CouponController;
use App\Modules\Ordering\Http\Controllers\CustomerLookupController;
use App\Modules\Ordering\Http\Controllers\DeliverySettingsController;
use App\Modules\Ordering\Http\Controllers\MerchantOrderController;
use App\Modules\Ordering\Http\Controllers\PrivacyController;
use App\Modules\Ordering\Http\Controllers\ReportController;
use App\Modules\Ordering\Http\Controllers\TrackingController;
use App\Modules\Payments\Http\Controllers\MercadoPagoWebhookController;
use App\Modules\Payments\Http\Controllers\PaymentSettingsController;
use App\Modules\Payments\Http\Controllers\RefundController;
use App\Modules\Platform\Features\Feature;
use App\Modules\Platform\Http\Controllers\AdminAuditController;
use App\Modules\Platform\Http\Controllers\AdminAuthController;
use App\Modules\Platform\Http\Controllers\AdminBillingController;
use App\Modules\Platform\Http\Controllers\AdminDomainController;
use App\Modules\Platform\Http\Controllers\AdminMailController;
use App\Modules\Platform\Http\Controllers\AdminPlanController;
use App\Modules\Platform\Http\Controllers\AdminTenantController;
use App\Modules\Platform\Http\Controllers\BillingWebhookController;
use App\Modules\Platform\Http\Controllers\HealthController;
use App\Modules\Platform\Http\Controllers\MerchantAuditController;
use App\Modules\Platform\Http\Controllers\MerchantBillingController;
use App\Modules\Platform\Http\Controllers\TlsAskController;
use App\Modules\Platform\Http\Middleware\AuditMutations;
use App\Modules\Tenancy\Http\Controllers\BranchController;
use App\Modules\Tenancy\Http\Controllers\BranchHoursController;
use App\Modules\Tenancy\Http\Controllers\SettingsController;
use App\Modules\Tenancy\Http\Controllers\StorefrontController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — prefixo /api/v1 (bootstrap/app.php)
|--------------------------------------------------------------------------
| O tenant vem SEMPRE do Host (middleware "tenant"). Nenhuma rota aceita
| tenant_id do cliente como autoridade.
*/

// Público (storefront) — host de tenant, sem sessão.
Route::middleware(['tenant', SubstituteBindings::class, 'throttle:public'])->group(function () {
    Route::get('storefront', StorefrontController::class)->name('storefront.show');
    Route::get('menus', [PublicCatalogController::class, 'menus'])->name('public.menus');
    Route::get('products/{product}', [PublicCatalogController::class, 'product'])->name('public.products.show');

    // Carrinho anônimo (token no caminho) e acompanhamento.
    Route::post('carts', [CartController::class, 'store'])->name('carts.store');
    Route::get('carts/{token}', [CartController::class, 'show'])->name('carts.show');
    Route::post('carts/{token}/items', [CartController::class, 'addItem'])->name('carts.items.store');
    Route::patch('carts/{token}/items/{item}', [CartController::class, 'updateItem'])->name('carts.items.update');
    Route::delete('carts/{token}/items/{item}', [CartController::class, 'removeItem'])->name('carts.items.destroy');
    Route::get('orders/track/{token}', TrackingController::class)->name('orders.track');
    Route::post('carts/{token}/coupon', [CartController::class, 'coupon'])->name('carts.coupon');
    // Feed público de promoções (contrato v1, lido pelo site do restaurante).
    Route::get('vitrine/promocoes.json', StorefrontFeedController::class)->name('vitrine.promocoes');
});

// Gerar de novo / consultar a cobrança online do pedido (chama o provedor).
Route::middleware(['tenant', 'throttle:payment-actions'])
    ->post('orders/track/{token}/payment', [TrackingController::class, 'payment'])->name('orders.track.payment');

// Webhook do Mercado Pago no host do restaurante (sem sessão/CSRF; assinatura própria).
Route::middleware(['tenant', 'throttle:webhooks'])
    ->post('webhooks/mercadopago', MercadoPagoWebhookController::class)->name('webhooks.mercadopago');

// Checkout: limite próprio, mais restrito.
Route::middleware(['tenant', 'throttle:checkout'])->post('checkout', CheckoutController::class)->name('checkout');

// Cliente pelo celular (checkout e "Acompanhar pedido"): limite próprio, apertado.
Route::middleware(['tenant', 'throttle:phone-lookup'])->group(function () {
    Route::post('customers/lookup', [CustomerLookupController::class, 'customer'])->name('customers.lookup');
    Route::post('orders/lookup', [CustomerLookupController::class, 'orders'])->name('orders.lookup');
});

// Painel do restaurante — host de tenant, sessão vinculada ao tenant.
Route::prefix('merchant')->middleware(['tenant', 'spa'])->name('merchant.')->group(function () {
    Route::post('auth/login', [MerchantAuthController::class, 'login'])
        ->middleware('throttle:login')->name('auth.login');
    Route::post('auth/forgot-password', [PasswordResetController::class, 'merchantForgot'])
        ->middleware('throttle:password-reset')->name('auth.forgot');
    Route::post('auth/reset-password', [PasswordResetController::class, 'merchantReset'])
        ->middleware('throttle:password-reset')->name('auth.reset');

    Route::middleware(['auth:sanctum', 'merchant', SubstituteBindings::class, AuditMutations::class])->group(function () {
        Route::post('auth/logout', [MerchantAuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [MerchantAuthController::class, 'me'])->name('me');

        Route::middleware('permission:'.P::BRANCHES_VIEW)->group(function () {
            Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
            Route::get('branches/{branch}', [BranchController::class, 'show'])->name('branches.show');
            Route::get('branches/{branch}/hours', [BranchHoursController::class, 'show'])->name('branches.hours.show');
        });
        Route::middleware('permission:'.P::BRANCHES_MANAGE)->group(function () {
            Route::patch('branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
            Route::post('branches', [BranchController::class, 'store'])
                ->middleware('feature:'.Feature::MULTI_BRANCH)->name('branches.store');
        });

        // Minha conta e equipe
        Route::post('me/password', [AccountController::class, 'password'])
            ->middleware('throttle:login')->name('me.password');
        Route::middleware('permission:'.P::STAFF_MANAGE)->group(function () {
            Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
            Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
            Route::patch('staff/{user}', [StaffController::class, 'update'])->name('staff.update');
            Route::delete('staff/{user}', [StaffController::class, 'destroy'])->name('staff.destroy');
            Route::post('staff/{user}/password', [StaffController::class, 'resetPassword'])
                ->middleware('throttle:payment-actions')->name('staff.password');
        });

        Route::put('branches/{branch}/hours', [BranchHoursController::class, 'update'])
            ->middleware('permission:'.P::BRANCHES_MANAGE)->name('branches.hours.update');

        Route::middleware('permission:'.P::SETTINGS_VIEW)->get('settings', [SettingsController::class, 'show'])->name('settings.show');
        Route::middleware('permission:'.P::SETTINGS_UPDATE)->patch('settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::middleware('permission:'.P::SETTINGS_UPDATE)->post('settings/{kind}', [SettingsController::class, 'uploadImage'])->whereIn('kind', ['logo', 'cover'])->name('settings.image.upload');
        Route::middleware('permission:'.P::SETTINGS_UPDATE)->delete('settings/{kind}', [SettingsController::class, 'deleteImage'])->whereIn('kind', ['logo', 'cover'])->name('settings.image.delete');

        // Catálogo — leitura
        Route::middleware('permission:'.P::CATALOG_VIEW)->group(function () {
            Route::get('menus', [MenuController::class, 'index'])->name('menus.index');
            Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
            Route::get('products', [ProductController::class, 'index'])->name('products.index');
            Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
            Route::get('option-groups', [OptionGroupController::class, 'index'])->name('option-groups.index');
            Route::get('option-groups/{optionGroup}', [OptionGroupController::class, 'show'])->name('option-groups.show');
            Route::get('branches/{branch}/availability', [AvailabilityController::class, 'index'])->name('availability.index');
        });

        // Catálogo — escrita
        Route::middleware('permission:'.P::CATALOG_MANAGE)->group(function () {
            Route::post('menus', [MenuController::class, 'store'])->name('menus.store');
            Route::patch('menus/{menu}', [MenuController::class, 'update'])->name('menus.update');
            Route::delete('menus/{menu}', [MenuController::class, 'destroy'])->name('menus.destroy');

            Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
            Route::patch('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
            Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

            Route::post('products', [ProductController::class, 'store'])->name('products.store');
            Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
            Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
            Route::post('products/{product}/image', [ProductController::class, 'uploadImage'])
                ->middleware('throttle:uploads')->name('products.image.store');
            Route::delete('products/{product}/image', [ProductController::class, 'deleteImage'])->name('products.image.destroy');

            Route::post('option-groups', [OptionGroupController::class, 'store'])->name('option-groups.store');
            Route::put('option-groups/{optionGroup}', [OptionGroupController::class, 'update'])->name('option-groups.update');
            Route::delete('option-groups/{optionGroup}', [OptionGroupController::class, 'destroy'])->name('option-groups.destroy');
        });

        Route::put('branches/{branch}/availability/{product}', [AvailabilityController::class, 'update'])
            ->middleware('permission:'.P::CATALOG_AVAILABILITY)->name('availability.update');

        // Entrega e retirada
        Route::get('branches/{branch}/delivery', [DeliverySettingsController::class, 'show'])
            ->middleware('permission:'.P::BRANCHES_VIEW)->name('delivery.show');
        Route::put('branches/{branch}/delivery', [DeliverySettingsController::class, 'update'])
            ->middleware('permission:'.P::BRANCHES_MANAGE)->name('delivery.update');

        // Pedidos
        Route::middleware('permission:'.P::ORDERS_VIEW)->group(function () {
            Route::get('orders/pulse', [MerchantOrderController::class, 'pulse'])
                ->middleware('throttle:pulse')->name('orders.pulse');
            Route::get('orders', [MerchantOrderController::class, 'index'])->name('orders.index');
            Route::get('orders/{order}', [MerchantOrderController::class, 'show'])->name('orders.show');
        });
        Route::patch('orders/{order}/status', [MerchantOrderController::class, 'updateStatus'])
            ->middleware('permission:'.P::ORDERS_MANAGE)->name('orders.status');

        // Promoções, cupons e vitrine
        Route::middleware('permission:'.P::MARKETING_MANAGE)->group(function () {
            Route::get('promotions', [PromotionController::class, 'index'])->name('promotions.index');
            Route::post('promotions', [PromotionController::class, 'store'])->name('promotions.store');
            Route::patch('promotions/{promotion}', [PromotionController::class, 'update'])->name('promotions.update');
            Route::delete('promotions/{promotion}', [PromotionController::class, 'destroy'])->name('promotions.destroy');
            Route::get('storefront-feed', [StorefrontFeedSettingsController::class, 'show'])->name('storefront-feed.show');
            Route::put('storefront-feed', [StorefrontFeedSettingsController::class, 'update'])->name('storefront-feed.update');
            Route::middleware('feature:'.Feature::COUPONS)->group(function () {
                Route::get('coupons', [CouponController::class, 'index'])->name('coupons.index');
                Route::post('coupons', [CouponController::class, 'store'])->name('coupons.store');
                Route::patch('coupons/{coupon}', [CouponController::class, 'update'])->name('coupons.update');
                Route::delete('coupons/{coupon}', [CouponController::class, 'destroy'])->name('coupons.destroy');
            });
        });

        // LGPD: atendimento a titulares
        Route::middleware('permission:'.P::SETTINGS_UPDATE)->group(function () {
            Route::get('privacy/customer', [PrivacyController::class, 'show'])->name('privacy.show');
            Route::post('privacy/customer/erase', [PrivacyController::class, 'erase'])->name('privacy.erase');
        });

        // Relatórios e auditoria
        Route::middleware('permission:'.P::REPORTS_VIEW)->group(function () {
            Route::get('reports/summary', [ReportController::class, 'summary'])->name('reports.summary');
            Route::get('reports/orders.csv', [ReportController::class, 'csv'])->middleware('throttle:exports')->name('reports.csv');
        });
        Route::get('audit', [MerchantAuditController::class, 'index'])
            ->middleware('permission:'.P::AUDIT_VIEW)->name('audit.index');

        // Pagamentos online
        Route::post('orders/{order}/refunds', [RefundController::class, 'store'])
            ->middleware(['permission:'.P::PAYMENTS_REFUND, 'throttle:payment-actions'])->name('orders.refunds.store');
        // Cartão fidelidade — recurso por plano (brinde: liberado em todos).
        Route::middleware(['feature:'.Feature::LOYALTY, 'permission:'.P::MARKETING_MANAGE])->prefix('loyalty')->name('loyalty.')->group(function () {
            Route::get('/', [LoyaltyController::class, 'show'])->name('show');
            Route::put('/', [LoyaltyController::class, 'update'])->name('update');
            Route::get('card', [LoyaltyController::class, 'card'])->name('card');
            Route::post('card/adjust', [LoyaltyController::class, 'adjust'])->name('card.adjust');
        });

        // Caixa (mini PDV) — recurso por plano (brinde: liberado em todos).
        Route::middleware(['feature:'.Feature::CASHBOOK])->prefix('cash')->name('cash.')->group(function () {
            Route::middleware('permission:'.P::CASH_OPERATE)->group(function () {
                Route::get('session', [CashController::class, 'current'])->name('session');
                Route::post('session', [CashController::class, 'open'])->name('open');
                Route::post('session/{session}/close', [CashController::class, 'close'])->name('close');
                Route::post('entries', [CashController::class, 'entry'])->name('entries.store');
                Route::post('sales', [CashController::class, 'sale'])->name('sales.store');
                Route::get('entries', [CashController::class, 'entries'])->name('entries.index');
            });
            Route::middleware('permission:'.P::CASH_MANAGE)->group(function () {
                Route::post('entries/{entry}/void', [CashController::class, 'void'])->name('entries.void');
                Route::get('summary', [CashController::class, 'summary'])->name('summary');
                Route::get('sessions', [CashController::class, 'sessions'])->name('sessions');
                Route::get('entries.csv', [CashController::class, 'csv'])->name('entries.csv');
            });
        });

        Route::middleware('permission:'.P::BILLING_MANAGE)->prefix('billing')->name('billing.')->group(function () {
            Route::get('/', [MerchantBillingController::class, 'show'])->name('show');
            Route::post('invoices/{invoice}/pix', [MerchantBillingController::class, 'pix'])
                ->middleware('throttle:payment-actions')->name('invoices.pix');
            Route::get('invoices/{invoice}', [MerchantBillingController::class, 'status'])->name('invoices.show');
        });

        Route::middleware('permission:'.P::PAYMENTS_MANAGE)->group(function () {
            Route::get('payments/mercadopago', [PaymentSettingsController::class, 'show'])->name('payments.mercadopago.show');
            Route::put('payments/mercadopago', [PaymentSettingsController::class, 'update'])
                ->middleware('throttle:payment-actions')->name('payments.mercadopago.update');
            Route::delete('payments/mercadopago', [PaymentSettingsController::class, 'destroy'])->name('payments.mercadopago.destroy');
        });
    });
});

// Painel SerTec — somente no host admin.
Route::prefix('admin')->middleware(['platform.host', 'spa'])->name('admin.')->group(function () {
    Route::post('auth/login', [AdminAuthController::class, 'login'])
        ->middleware('throttle:login')->name('auth.login');
    Route::post('auth/forgot-password', [PasswordResetController::class, 'adminForgot'])
        ->middleware('throttle:password-reset')->name('auth.forgot');
    Route::post('auth/reset-password', [PasswordResetController::class, 'adminReset'])
        ->middleware('throttle:password-reset')->name('auth.reset');

    Route::middleware(['auth:sanctum', 'platform', SubstituteBindings::class])->group(function () {
        Route::post('auth/logout', [AdminAuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AdminAuthController::class, 'me'])->name('me');

        Route::get('tenants', [AdminTenantController::class, 'index'])->name('tenants.index');
        Route::post('tenants', [AdminTenantController::class, 'store'])->name('tenants.store');
        Route::get('tenants/{tenant}', [AdminTenantController::class, 'show'])->name('tenants.show');
        Route::patch('tenants/{tenant}', [AdminTenantController::class, 'update'])->name('tenants.update');
        Route::post('tenants/{tenant}/owner-password', [AdminTenantController::class, 'resetOwnerPassword'])->name('tenants.owner-password');
        Route::post('tenants/{tenant}/domains', [AdminDomainController::class, 'store'])->name('tenants.domains.store');
        Route::post('tenants/{tenant}/domains/{domain}/token', [AdminDomainController::class, 'regenerate'])->name('tenants.domains.token');
        Route::post('tenants/{tenant}/domains/{domain}/verify', [AdminDomainController::class, 'verify'])->name('tenants.domains.verify');
        Route::post('tenants/{tenant}/domains/{domain}/primary', [AdminDomainController::class, 'primary'])->name('tenants.domains.primary');
        Route::delete('tenants/{tenant}/domains/{domain}', [AdminDomainController::class, 'destroy'])->name('tenants.domains.destroy');
        Route::get('plans', [AdminTenantController::class, 'plans'])->name('plans.index');
        // Cobrança SerTec (Sprint 13).
        Route::get('billing/plans', [AdminPlanController::class, 'index'])->name('billing.plans.index');
        Route::post('billing/plans', [AdminPlanController::class, 'store'])->name('billing.plans.store');
        Route::patch('billing/plans/{plan}', [AdminPlanController::class, 'update'])->name('billing.plans.update');
        Route::get('billing/settings', [AdminBillingController::class, 'settings'])->name('billing.settings');
        Route::put('billing/settings', [AdminBillingController::class, 'saveSettings'])
            ->middleware('throttle:payment-actions')->name('billing.settings.update');
        Route::delete('billing/settings', [AdminBillingController::class, 'deleteSettings'])->name('billing.settings.destroy');
        Route::get('billing/invoices', [AdminBillingController::class, 'invoices'])->name('billing.invoices');
        Route::post('billing/invoices/{invoice}/pay', [AdminBillingController::class, 'payInvoice'])->name('billing.invoices.pay');
        Route::post('billing/invoices/{invoice}/cancel', [AdminBillingController::class, 'cancelInvoice'])->name('billing.invoices.cancel');
        Route::get('tenants/{tenant}/billing', [AdminBillingController::class, 'tenant'])->name('tenants.billing');
        Route::put('tenants/{tenant}/billing', [AdminBillingController::class, 'updateTenant'])->name('tenants.billing.update');
        Route::post('tenants/{tenant}/billing/invoices', [AdminBillingController::class, 'createInvoice'])->name('tenants.billing.invoices.store');
        Route::get('audit', [AdminAuditController::class, 'index'])->name('audit.index');
        Route::get('system/mail', [AdminMailController::class, 'show'])->name('system.mail');
        Route::post('system/mail/test', [AdminMailController::class, 'test'])
            ->middleware('throttle:payment-actions')->name('system.mail.test');
    });
});

// Webhook da conta Mercado Pago da SerTec (mensalidades) — host admin.
Route::middleware(['platform.host', 'throttle:webhooks'])
    ->post('billing/webhooks/mercadopago', BillingWebhookController::class)->name('billing.webhook');

// Perguntado pelo Caddy antes de emitir certificado (on-demand TLS). A borda
// bloqueia /api/v1/internal/* vindo de fora; só a rede interna alcança.
Route::get('internal/tls-ask', TlsAskController::class)->middleware('throttle:60,1')->name('internal.tls-ask');
Route::get('internal/health', HealthController::class)->middleware('throttle:60,1')->name('internal.health');
