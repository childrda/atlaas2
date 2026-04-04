<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()
                ->withErrors(['email' => 'Invalid credentials.'])
                ->withInput($request->only('email')); // repopulates email, not password
        }

        $request->session()->regenerate();

        return $this->redirectByRole(Auth::user());
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    // Public so SocialiteController can call the same logic without duplicating it
    public function redirectByRole(User $user): RedirectResponse
    {
        // Explicit per-role checks — easy to split into separate portals later
        if ($user->hasRole('district_admin')) {
            return redirect()->route('teacher.dashboard');
            // TODO: redirect()->route('district.dashboard') when Phase 8+ admin portal is built
        }

        if ($user->hasRole('school_admin')) {
            return redirect()->route('teacher.dashboard');
            // TODO: redirect()->route('admin.dashboard') when admin portal is built
        }

        if ($user->hasRole('teacher')) {
            return redirect()->route('teacher.dashboard');
        }

        if ($user->hasRole('student')) {
            return redirect()->route('student.dashboard');
        }

        Auth::logout();

        return redirect()->route('login')
            ->withErrors(['email' => 'Your account has no role assigned. Contact your administrator.']);
    }
}
