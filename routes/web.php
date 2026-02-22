<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MigrationController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/settings', [DashboardController::class, 'settings'])->name('settings');
Route::get('/migrations/{migration}', [MigrationController::class, 'show'])->name('migrations.show');
Route::get('/migrations/{migration}/logs', [LogController::class, 'show'])->name('migrations.logs');
