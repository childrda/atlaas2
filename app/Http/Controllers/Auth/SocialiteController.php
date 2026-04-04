<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SocialiteController extends Controller
{
    /** @var list<string> */
    private const ALLOWED_PROVIDERS = ['google'];

    public function redirect(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::ALLOWED_PROVIDERS, true), 404);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::ALLOWED_PROVIDERS, true), 404);

        // stateless() avoids session/state mismatch errors behind load balancers
        // or reverse proxies — safe to use now, required in production
        $socialUser = Socialite::driver($provider)->stateless()->user();

        $user = User::where('email', $socialUser->getEmail())
            ->where('is_active', true)
            ->first();

        if (! $user) {
            return redirect()->route('login')
                ->withErrors(['email' => 'No active account found for this email. Contact your district administrator.']);
        }

        $user->update([
            'external_id' => $socialUser->getId(),
            'avatar_url' => $socialUser->getAvatar(),
        ]);

        Auth::login($user);
        request()->session()->regenerate();

        // Reuse the same redirect logic as password login
        return app(LoginController::class)->redirectByRole($user);
    }
}
