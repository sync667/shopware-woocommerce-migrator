<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'API is running',
        'app' => config('app.name'),
        'version' => config('app.version'),
        'build' => config('app.build'),
        'environment' => config('app.env'),
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Ziggy route list for frontend
Route::get('/api/ziggy', function () {
    return response()->json(app('ziggy')->toArray());
})->name('ziggy');
