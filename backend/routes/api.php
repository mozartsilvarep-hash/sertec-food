<?php

use App\Modules\Identity\Http\Controllers\MerchantAuthController;
use App\Modules\Identity\Permissions;
use App\Modules\Platform\Http\Controllers\AdminAuthController;
use App\Modules\Tenancy\Http\Controllers\BranchController;
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
Route::middleware(['tenant', SubstituteBindings::class])->group(function () {
    Route::get('storefront', StorefrontController::class)->name('storefront.show');
});

// Painel do restaurante — host de tenant, sessão vinculada ao tenant.
Route::prefix('merchant')->middleware(['tenant', 'spa'])->name('merchant.')->group(function () {
    Route::post('auth/login', [MerchantAuthController::class, 'login'])
        ->middleware('throttle:login')->name('auth.login');

    Route::middleware(['auth:sanctum', 'merchant', SubstituteBindings::class])->group(function () {
        Route::post('auth/logout', [MerchantAuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [MerchantAuthController::class, 'me'])->name('me');

        Route::get('branches', [BranchController::class, 'index'])
            ->middleware('permission:'.Permissions::BRANCHES_VIEW)->name('branches.index');
        Route::get('branches/{branch}', [BranchController::class, 'show'])
            ->middleware('permission:'.Permissions::BRANCHES_VIEW)->name('branches.show');
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
