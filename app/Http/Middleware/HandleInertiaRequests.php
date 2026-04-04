<?php

namespace App\Http\Middleware;

use App\Models\SafetyAlert;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        $ttsEnabled = (bool) config('services.tts.enabled', false);

        return [
            ...parent::share($request),
            'csrf_token' => csrf_token(),
            'features' => [
                'tts' => $ttsEnabled,
                'ttsVoice' => $ttsEnabled ? config('services.tts.voice') : null,
                'ttsSpeed' => $ttsEnabled ? config('services.tts.speed') : null,
            ],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'roles' => $user->getRoleNames()->toArray(),
                    'district' => $user->district,
                    'school' => $user->school,
                ] : null,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'old' => [
                'email' => $request->session()->getOldInput('email'),
            ],
            'alerts' => [
                'openCount' => $user && $user->hasRole(['teacher', 'school_admin', 'district_admin'])
                    ? SafetyAlert::where('teacher_id', $user->id)->where('status', 'open')->count()
                    : 0,
            ],
        ];
    }
}
