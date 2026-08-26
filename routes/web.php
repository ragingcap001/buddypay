<?php

use App\Http\Controllers\Admin\Web\AdminAuthController;
use App\Http\Controllers\Admin\Web\AdminDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Admin dashboard (web session auth; phone + password, admin role).
Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/login', [AdminAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AdminAuthController::class, 'login'])->name('login.attempt');
    Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');

    Route::get('/', [AdminDashboardController::class, 'index'])
        ->middleware(['auth', 'admin'])
        ->name('dashboard');
});
