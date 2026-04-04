import { SiteLogo } from '@/Components/Atlaas/SiteLogo';
import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    Bell,
    BookOpen,
    Globe2,
    LayoutDashboard,
    Layers,
    Presentation,
    ShieldAlert,
    Sparkles,
} from 'lucide-react';
import type { ReactNode } from 'react';
import type { User } from '@/types/models';

type NavItem = {
    label: string;
    href: string;
    prefix: string;
    exact: boolean;
    icon: 'dash' | 'layers' | 'book' | 'lessons' | 'bell' | 'compass' | 'toolkit' | 'discover' | 'shield' | null;
    soon?: boolean;
    /** Red open-alert count badge (same count as Alerts). */
    badgeKey?: 'alerts' | 'compass_alerts';
};

const nav: NavItem[] = [
    { label: 'Dashboard', href: '/teach', prefix: '/teach', exact: true, icon: 'dash' as const },
    { label: 'My Spaces', href: '/teach/spaces', prefix: '/teach/spaces', exact: false, icon: 'layers' as const },
    { label: 'Classrooms', href: '/teach/classrooms', prefix: '/teach/classrooms', exact: false, icon: 'book' as const },
    {
        label: 'Lessons',
        href: '/teach/lessons',
        prefix: '/teach/lessons',
        exact: false,
        icon: 'lessons' as const,
    },
    {
        label: 'Alerts',
        href: '/teach/alerts',
        prefix: '/teach/alerts',
        exact: false,
        icon: 'bell' as const,
        badgeKey: 'alerts' as const,
    },
    {
        label: 'Compass View',
        href: '/teach/compass',
        prefix: '/teach/compass',
        exact: false,
        icon: 'compass' as const,
        badgeKey: 'compass_alerts' as const,
    },
    {
        label: 'Toolkit',
        href: '/teach/toolkit',
        prefix: '/teach/toolkit',
        exact: false,
        icon: 'toolkit' as const,
    },
    {
        label: 'Discover',
        href: '/teach/discover',
        prefix: '/teach/discover',
        exact: false,
        icon: 'discover' as const,
    },
];

const adminSafetyNav: NavItem = {
    label: 'Student safety',
    href: '/teach/settings/student-safety',
    prefix: '/teach/settings/student-safety',
    exact: false,
    icon: 'shield' as const,
};

function pathActive(url: string, prefix: string, exact: boolean): boolean {
    const path = url.split('?')[0] ?? '';
    if (exact) {
        return path === prefix || path === `${prefix}/`;
    }
    return path === prefix || path.startsWith(`${prefix}/`);
}

export default function TeacherLayout({ children }: { children: ReactNode }) {
    const page = usePage();
    const { auth, alerts } = page.props as {
        auth: { user: User | null };
        alerts?: { openCount: number };
    };
    const url = page.url;
    const user = auth.user!;
    const openAlertCount = alerts?.openCount ?? 0;
    const showAdminSafety = user.roles.some((r) => r === 'district_admin' || r === 'school_admin');
    const navItems = showAdminSafety ? [...nav, adminSafetyNav] : nav;

    return (
        <div className="flex min-h-screen bg-gray-50">
            <aside
                className="flex w-56 shrink-0 flex-col text-white"
                style={{ backgroundColor: '#1E3A5F' }}
            >
                <div className="border-b border-white/10 px-3 py-3">
                    <Link href="/teach" className="block">
                        <SiteLogo
                            onDark
                            className="h-9 w-full max-w-[200px] object-contain object-left"
                        />
                    </Link>
                    <p className="mt-3 text-sm font-medium text-white/90">{user.district.name}</p>
                </div>
                <nav className="flex flex-1 flex-col gap-0.5 p-3">
                    {navItems.map((item) => {
                        const active =
                            !item.soon && item.prefix
                                ? pathActive(url, item.prefix, item.exact)
                                : false;
                        const showAlertBadge =
                            (item.badgeKey === 'alerts' || item.badgeKey === 'compass_alerts') &&
                            openAlertCount > 0;
                        return (
                            <Link
                                key={item.label}
                                href={item.href}
                                className={`flex items-center gap-2 rounded-md px-3 py-2 text-sm ${
                                    active
                                        ? 'font-medium text-white'
                                        : 'text-white/60 hover:bg-white/5 hover:text-white/90'
                                }`}
                                style={
                                    active
                                        ? { borderLeft: '3px solid #F5A623', paddingLeft: '9px' }
                                        : undefined
                                }
                            >
                                {item.icon === 'dash' && (
                                    <LayoutDashboard className="h-4 w-4 shrink-0 opacity-80" />
                                )}
                                {item.icon === 'layers' && <Layers className="h-4 w-4 shrink-0 opacity-80" />}
                                {item.icon === 'book' && <BookOpen className="h-4 w-4 shrink-0 opacity-80" />}
                                {item.icon === 'lessons' && <Presentation className="h-4 w-4 shrink-0 opacity-80" />}
                                {item.icon === 'bell' && <Bell className="h-4 w-4 shrink-0 opacity-80" />}
                                {item.icon === 'compass' && <Activity className="h-4 w-4 shrink-0 opacity-80" />}
                                {item.icon === 'toolkit' && <Sparkles className="h-4 w-4 shrink-0 opacity-80" />}
                                {item.icon === 'discover' && <Globe2 className="h-4 w-4 shrink-0 opacity-80" />}
                                {item.icon === 'shield' && <ShieldAlert className="h-4 w-4 shrink-0 opacity-80" />}
                                <span className="min-w-0 flex-1 truncate">{item.label}</span>
                                {showAlertBadge && (
                                    <span className="shrink-0 rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white">
                                        {openAlertCount > 99 ? '99+' : openAlertCount}
                                    </span>
                                )}
                                {item.soon && (
                                    <span className="ml-auto shrink-0 text-[10px] uppercase tracking-wide text-white/40">
                                        soon
                                    </span>
                                )}
                            </Link>
                        );
                    })}
                </nav>
                <div className="border-t border-white/10 p-3">
                    <div className="flex items-center gap-2">
                        <div className="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-xs font-medium">
                            {user.name.charAt(0).toUpperCase()}
                        </div>
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium">{user.name}</p>
                            <p className="truncate text-xs text-white/50">
                                {user.roles[0]?.replace('_', ' ') ?? 'Member'}
                            </p>
                        </div>
                    </div>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        className="mt-3 w-full rounded-md bg-white/10 px-3 py-1.5 text-center text-xs font-medium text-white hover:bg-white/15"
                    >
                        Log out
                    </Link>
                </div>
            </aside>
            <main className="min-w-0 flex-1">{children}</main>
        </div>
    );
}
