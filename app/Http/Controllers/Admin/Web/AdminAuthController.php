<?php

namespace App\Http\Controllers\Admin\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Admin dashboard login (web session, phone + password — the same
 * credentials as the API login flow).
 */
class AdminAuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check() && Auth::user()->isAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'min:7', 'max:20'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('phone', trim($validated['phone']))->first();

        // Same failure for unknown phone and wrong password.
        if ($user === null || ! Hash::check($validated['password'], $user->password)) {
            return back()->withErrors(['phone' => 'Invalid phone number or password.'])->onlyInput('phone');
        }

        if (! $user->isActive()) {
            return back()->withErrors(['phone' => 'This account is suspended.'])->onlyInput('phone');
        }

        if (! $user->isAdmin()) {
            return back()->withErrors(['phone' => 'This account does not have administrator access.'])->onlyInput('phone');
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        if ($request->session() !== null) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('admin.login');
    }
}
