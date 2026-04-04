import { SiteLogo } from '@/Components/Atlaas/SiteLogo';
import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function Login() {
    const { errors, old } = usePage().props as {
        errors: Record<string, string>;
        old?: { email?: string };
    };

    const { data, setData, post, processing } = useForm({
        email: old?.email ?? '',
        password: '',
        remember: false,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/login');
    }

    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-gray-50 px-4">
            <div className="w-full max-w-sm">
                <div className="flex justify-center px-2">
                    <SiteLogo className="h-auto max-h-28 w-full max-w-xs object-contain" />
                </div>
                <p className="mt-4 text-center text-sm text-gray-500">Sign in to continue</p>

                <form onSubmit={submit} className="mt-8 space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                    {errors.email && (
                        <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{errors.email}</div>
                    )}

                    <div>
                        <label htmlFor="email" className="block text-sm font-medium text-gray-700">
                            Email
                        </label>
                        <input
                            id="email"
                            type="email"
                            autoComplete="username"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-[#1E3A5F] focus:outline-none focus:ring-1 focus:ring-[#1E3A5F]"
                            required
                        />
                    </div>

                    <div>
                        <label htmlFor="password" className="block text-sm font-medium text-gray-700">
                            Password
                        </label>
                        <input
                            id="password"
                            type="password"
                            autoComplete="current-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-[#1E3A5F] focus:outline-none focus:ring-1 focus:ring-[#1E3A5F]"
                            required
                        />
                    </div>

                    <label className="flex items-center gap-2 text-sm text-gray-600">
                        <input
                            type="checkbox"
                            checked={data.remember}
                            onChange={(e) => setData('remember', e.target.checked)}
                            className="rounded border-gray-300 text-[#1E3A5F] focus:ring-[#1E3A5F]"
                        />
                        Remember me
                    </label>

                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full rounded-md py-2 text-sm font-medium text-white disabled:opacity-50"
                        style={{ backgroundColor: '#1E3A5F' }}
                    >
                        Sign in
                    </button>
                </form>

                <div className="relative my-6">
                    <div className="absolute inset-0 flex items-center">
                        <div className="w-full border-t border-gray-200" />
                    </div>
                    <div className="relative flex justify-center text-xs uppercase">
                        <span className="bg-gray-50 px-2 text-gray-500">Or</span>
                    </div>
                </div>

                <a
                    href="/auth/google"
                    className="flex w-full items-center justify-center rounded-md border border-gray-300 bg-white py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                >
                    Sign in with Google
                </a>
            </div>
        </div>
    );
}
