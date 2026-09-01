<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// The /admin dashboard is served by the Filament panel
// (App\Providers\Filament\AdminPanelProvider), which registers its own
// routes — including login — on the `admin` guard. The previous Blade
// dashboard was retired in favour of it; the /api/v1/admin/* JSON API
// (session + EnsureAdmin) is unchanged and still available to clients.
