<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MigrationController;
use App\Models\MigrationRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// API V1 Routes
Route::prefix('v1')->group(function () {
    // Public routes
    Route::post('/register', [AuthController::class, 'register'])->name('api.v1.register');
    Route::post('/login', [AuthController::class, 'login'])->name('api.v1.login');

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', [AuthController::class, 'user'])->name('api.v1.user');
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.v1.logout');
    });
});

// Legacy route (backward compatibility)
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Model binding for migration routes
Route::model('migration', MigrationRun::class);

// Migration API Routes (protected)
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('migrations')->group(function () {
        Route::post('/', [MigrationController::class, 'store']);
        Route::get('/{migration}/status', [MigrationController::class, 'status']);
        Route::get('/{migration}/logs', [LogController::class, 'index']);
        Route::post('/{migration}/pause', [MigrationController::class, 'pause']);
        Route::post('/{migration}/resume', [MigrationController::class, 'resume']);
        Route::post('/{migration}/cancel', [MigrationController::class, 'cancel']);
    });

    Route::post('/shopware/ping', [MigrationController::class, 'pingShopware']);
    Route::post('/woocommerce/ping', [MigrationController::class, 'pingWoocommerce']);
});
