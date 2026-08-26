<?php

namespace App\Http\Controllers\Admin\Web;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Admin dashboard shell — the page fetches /api/v1/admin/* (same-origin,
 * session auth) and renders config forms, provider health and push tools.
 */
class AdminDashboardController extends Controller
{
    public function index(): View
    {
        return view('admin.dashboard');
    }
}
