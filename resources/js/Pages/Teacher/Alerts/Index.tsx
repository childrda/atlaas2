import TeacherLayout from '@/Layouts/TeacherLayout';
import type { SafetyAlert } from '@/types/models';
import { router, usePage } from '@inertiajs/react';

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

const severityConfig = {
    critical: { label: 'Critical', classes: 'bg-red-100 text-red-800 border-red-200' },
    high: { label: 'High', classes: 'bg-orange-100 text-orange-800 border-orange-200' },
    medium: { label: 'Medium', classes: 'bg-yellow-100 text-yellow-800 border-yellow-200' },
    low: { label: 'Low', classes: 'bg-gray-100 text-gray-600 border-gray-200' },
} as const;

function severityBadge(severity: SafetyAlert['severity']) {
    const key = severity in severityConfig ? severity : 'low';
    const cfg = severityConfig[key];
    return (
        <span
            className={`inline-block rounded border px-2 py-0.5 text-xs font-medium ${cfg.classes}`}
        >
            {cfg.label}
        </span>
    );
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

function categoryLabel(cat: string): string {
    return cat.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export default function AlertsIndex() {
    const { alerts, openCriticalCount, flash } = usePage().props as {
        alerts: Paginated<SafetyAlert>;
        openCriticalCount: number;
        flash?: { success?: string };
    };

    function patchAlert(alert: SafetyAlert, status: SafetyAlert['status']) {
        router.patch(`/teach/alerts/${alert.id}`, { status });
    }

    return (
        <TeacherLayout>
            <div className="p-8">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-medium text-gray-900">Safety alerts</h1>
                        <p className="mt-1 text-sm text-gray-500">
                            Review flags from student sessions. Open critical:{' '}
                            <span className="font-medium text-gray-800">{openCriticalCount}</span>
                        </p>
                    </div>
                </div>

                {flash?.success && (
                    <div className="mt-6 rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}

                <div className="mt-8 overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                        <thead className="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                            <tr>
                                <th className="px-4 py-3">Severity</th>
                                <th className="px-4 py-3">Student</th>
                                <th className="px-4 py-3">Space</th>
                                <th className="px-4 py-3">Type</th>
                                <th className="px-4 py-3">Category</th>
                                <th className="px-4 py-3">Time</th>
                                <th className="px-4 py-3">Status</th>
                                <th className="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {alerts.data.map((alert) => (
                                <tr key={alert.id} className="hover:bg-gray-50/80">
                                    <td className="px-4 py-3">{severityBadge(alert.severity)}</td>
                                    <td className="px-4 py-3 font-medium text-gray-900">
                                        {alert.student.name}
                                    </td>
                                    <td className="px-4 py-3 text-gray-700">
                                        {alert.session?.space?.title ??
                                            alert.classroom_session?.lesson?.space?.title ??
                                            '—'}
                                    </td>
                                    <td className="px-4 py-3 text-gray-600">
                                        {alert.alert_type ? categoryLabel(alert.alert_type) : 'Content'}
                                    </td>
                                    <td className="px-4 py-3 text-gray-600">{categoryLabel(alert.category)}</td>
                                    <td className="px-4 py-3 whitespace-nowrap text-gray-500">
                                        {formatTime(alert.created_at)}
                                    </td>
                                    <td className="px-4 py-3">
                                        <span className="rounded bg-gray-100 px-2 py-0.5 text-xs capitalize text-gray-700">
                                            {alert.status}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <div className="flex flex-wrap justify-end gap-1">
                                            {alert.status === 'open' && (
                                                <>
                                                    <button
                                                        type="button"
                                                        onClick={() => patchAlert(alert, 'reviewed')}
                                                        className="rounded border border-gray-200 bg-white px-2 py-1 text-xs text-gray-700 hover:bg-gray-50"
                                                    >
                                                        Mark reviewed
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => patchAlert(alert, 'escalated')}
                                                        className="rounded border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-900 hover:bg-amber-100"
                                                    >
                                                        Escalate
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => patchAlert(alert, 'dismissed')}
                                                        className="rounded border border-gray-200 px-2 py-1 text-xs text-gray-500 hover:bg-gray-100"
                                                    >
                                                        Dismiss
                                                    </button>
                                                </>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {alerts.data.length === 0 && (
                    <p className="mt-8 text-center text-sm text-gray-500">No alerts yet.</p>
                )}

                {alerts.links.length > 3 && (
                    <div className="mt-8 flex flex-wrap gap-1">
                        {alerts.links.map((l, i) => (
                            <button
                                key={i}
                                type="button"
                                disabled={!l.url || l.active}
                                onClick={() => l.url && router.get(l.url)}
                                className={`rounded px-3 py-1 text-sm ${
                                    l.active ? 'bg-[#1E3A5F] text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200'
                                }`}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </TeacherLayout>
    );
}
