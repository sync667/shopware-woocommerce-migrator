<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MigrationController;
use App\Http\Controllers\ShopwareConfigController;
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

// All API routes have been moved to routes/web.php to support session-based authentication
// This is because we use sessions for auth, not stateless API tokens
