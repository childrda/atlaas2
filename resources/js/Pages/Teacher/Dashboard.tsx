import { useCurrentUser } from '@/hooks/useCurrentUser';
import TeacherLayout from '@/Layouts/TeacherLayout';
import { Link, usePage } from '@inertiajs/react';

type DashboardProps = {
    activeSpacesCount?: number;
    activeStudentsCount?: number;
    openAlertsCount?: number;
    stats?: {
        active_spaces?: number;
        active_students?: number;
        open_alerts?: number;
    };
};

export default function TeacherDashboard() {
    const user = useCurrentUser();
    const p = usePage().props as DashboardProps;
    const activeSpaces = p.activeSpacesCount ?? p.stats?.active_spaces ?? 0;
    const activeStudents = p.activeStudentsCount ?? p.stats?.active_students ?? 0;
    const openAlerts = p.openAlertsCount ?? p.stats?.open_alerts ?? 0;

    return (
        <TeacherLayout>
            <div className="p-8">
                <h1 className="text-2xl font-medium text-gray-900">Welcome back, {user.name}</h1>
                <p className="mt-1 text-gray-500">{user.district.name}</p>

                <div className="mt-8 grid grid-cols-1 gap-6 sm:grid-cols-3">
                    {(
                        [
                            {
                                label: 'Active Spaces',
                                value: activeSpaces,
                                href: '/teach/spaces',
                                hint: 'Go to My Spaces',
                            },
                            {
                                label: 'Active Students',
                                value: activeStudents,
                                href: '/teach/compass',
                                hint: 'Open Compass View for live sessions',
                            },
                            {
                                label: 'Open Alerts',
                                value: openAlerts,
                                href: '/teach/alerts',
                                hint: 'Go to Alerts',
                            },
                        ] as const
                    ).map((stat) => (
                        <Link
                            key={stat.label}
                            href={stat.href}
                            className="block rounded-lg border border-gray-200 bg-white p-6 shadow-sm transition hover:border-[#1E3A5F]/30 hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#1E3A5F]"
                            aria-label={`${stat.label}: ${stat.value}. ${stat.hint}`}
                        >
                            <p className="text-sm text-gray-500">{stat.label}</p>
                            <p className="mt-2 text-3xl font-medium text-gray-900">{stat.value}</p>
                        </Link>
                    ))}
                </div>

                {import.meta.env.DEV && (
                    <div className="mt-8 rounded border border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-800">
                        <span className="font-medium">Dev:</span>{' '}
                        Role: {user.roles.join(', ')} | District: {user.district.name}
                        {user.school && ` | School: ${user.school.name}`}
                    </div>
                )}
            </div>
        </TeacherLayout>
    );
}
