import { SiteLogo } from '@/Components/Atlaas/SiteLogo';
import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { User } from '@/types/models';

export default function StudentLayout({ children }: { children: ReactNode }) {
    const { auth } = usePage().props as { auth: { user: User | null } };
    const user = auth.user!;

    return (
        <div className="min-h-screen bg-white">
            <header
                className="flex items-center justify-between border-b border-gray-100 px-6 py-3"
                style={{ borderBottomColor: '#F5A62333' }}
            >
                <div className="flex min-w-0 flex-1 flex-col gap-0.5 sm:flex-row sm:items-center sm:gap-4">
                    <Link href="/learn" className="shrink-0">
                        <SiteLogo className="h-9 w-auto max-w-[min(100%,220px)] object-contain object-left sm:h-11" />
                    </Link>
                    <p className="truncate text-xs font-semibold sm:text-sm" style={{ color: '#1E3A5F' }}>
                        {user.district.name}
                    </p>
                </div>
                <div className="flex items-center gap-6">
                    <Link href="/learn" className="text-sm text-gray-600 hover:text-[#1E3A5F]">
                        Dashboard
                    </Link>
                    <Link href="/learn/spaces" className="text-sm text-gray-600 hover:text-[#1E3A5F]">
                        Spaces
                    </Link>
                    <span className="text-sm text-gray-700">{user.name}</span>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        className="rounded-md px-3 py-1.5 text-sm font-medium text-white"
                        style={{ backgroundColor: '#F5A623' }}
                    >
                        Log out
                    </Link>
                </div>
            </header>
            {children}
        </div>
    );
}
