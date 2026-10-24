<?php

use App\Modules\Catalog\Http\Controllers\AvailabilityController;
use App\Modules\Catalog\Http\Controllers\CategoryController;
use App\Modules\Catalog\Http\Controllers\MenuController;
use App\Modules\Catalog\Http\Controllers\OptionGroupController;
use App\Modules\Catalog\Http\Controllers\ProductController;
use App\Modules\Catalog\Http\Controllers\PublicCatalogController;
use App\Modules\Identity\Http\Controllers\MerchantAuthController;
use App\Modules\Identity\Permissions as P;
use App\Modules\Platform\Http\Controllers\AdminAuthController;
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
});

// Painel do restaurante — host de tenant, sessão vinculada ao tenant.
Route::prefix('merchant')->middleware(['tenant', 'spa'])->name('merchant.')->group(function () {
    Route::post('auth/login', [MerchantAuthController::class, 'login'])
        ->middleware('throttle:login')->name('auth.login');

    Route::middleware(['auth:sanctum', 'merchant', SubstituteBindings::class])->group(function () {
        Route::post('auth/logout', [MerchantAuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [MerchantAuthController::class, 'me'])->name('me');

        Route::middleware('permission:'.P::BRANCHES_VIEW)->group(function () {
            Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
            Route::get('branches/{branch}', [BranchController::class, 'show'])->name('branches.show');
            Route::get('branches/{branch}/hours', [BranchHoursController::class, 'show'])->name('branches.hours.show');
        });
        Route::put('branches/{branch}/hours', [BranchHoursController::class, 'update'])
            ->middleware('permission:'.P::BRANCHES_MANAGE)->name('branches.hours.update');

        Route::middleware('permission:'.P::SETTINGS_VIEW)->get('settings', [SettingsController::class, 'show'])->name('settings.show');
        Route::middleware('permission:'.P::SETTINGS_UPDATE)->patch('settings', [SettingsController::class, 'update'])->name('settings.update');

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
    });
});

// Painel SerTec — somente no host admin.
Route::prefix('admin')->middleware(['platform.host', 'spa'])->name('admin.')->group(function () {
    Route::post('auth/login', [AdminAuthController::class, 'login'])
        ->middleware('throttle:login')->name('auth.login');

    Route::middleware(['auth:sanctum', 'platform', SubstituteBindings::class])->group(function () {
        Route::post('auth/logout', [AdminAuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AdminAuthController::class, 'me'])->name('me');
    });
});
