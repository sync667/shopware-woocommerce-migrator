<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MigrationController;
use Illuminate\Support\Facades\Route;

// Public routes (with session middleware)
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/auth/validate', [AuthController::class, 'validateToken'])->name('auth.validate');
Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

// Protected routes
Route::middleware(\App\Http\Middleware\ValidateAccessToken::class)->group(function () {
    // Web pages
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/settings', [DashboardController::class, 'settings'])->name('settings');
    Route::get('/migrations/{migration}', [MigrationController::class, 'show'])->name('migrations.show');
    Route::get('/migrations/{migration}/logs', [LogController::class, 'show'])->name('migrations.logs');

    // API endpoints (need session for auth middleware)
    Route::model('migration', \App\Models\MigrationRun::class);

    Route::prefix('api')->group(function () {
        Route::prefix('migrations')->group(function () {
            Route::post('/', [\App\Http\Controllers\MigrationController::class, 'store']);
            Route::get('/{migration}/status', [\App\Http\Controllers\MigrationController::class, 'status']);
            Route::get('/{migration}/logs', [\App\Http\Controllers\LogController::class, 'index']);
            Route::post('/{migration}/pause', [\App\Http\Controllers\MigrationController::class, 'pause']);
            Route::post('/{migration}/resume', [\App\Http\Controllers\MigrationController::class, 'resume']);
            Route::post('/{migration}/cancel', [\App\Http\Controllers\MigrationController::class, 'cancel']);
        });

        Route::post('/shopware/ping', [\App\Http\Controllers\MigrationController::class, 'pingShopware']);
        Route::post('/woocommerce/ping', [\App\Http\Controllers\MigrationController::class, 'pingWoocommerce']);
        Route::post('/test-connections', [\App\Http\Controllers\MigrationController::class, 'testConnections']);
        Route::post('/cms-pages/list', [\App\Http\Controllers\MigrationController::class, 'listCmsPages']);
        Route::post('/shopware/languages', [\App\Http\Controllers\ShopwareConfigController::class, 'getLanguages']);
        Route::post('/shopware/live-version', [\App\Http\Controllers\ShopwareConfigController::class, 'getLiveVersionId']);
    });
});
