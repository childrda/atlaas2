import { router } from '@inertiajs/react';
import type { AlertState } from '@/stores/compass';
import { useCompassStore } from '@/stores/compass';

function isImmediateDanger(a: AlertState): boolean {
    return a.alert_type === 'crisis_immediate_danger' || a.category === 'crisis_immediate_danger';
}

function isCrisisFamily(a: AlertState): boolean {
    return (
        (a.alert_type?.startsWith('crisis_') ?? false) ||
        a.category.startsWith('crisis_') ||
        a.severity === 'critical'
    );
}

export function CriticalAlertModal({ alert }: { alert: AlertState }) {
    const removeAlert = useCompassStore((s) => s.removeAlert);
    const immediate = isImmediateDanger(alert);
    const crisis = isCrisisFamily(alert);

    function acknowledge() {
        router.patch(`/teach/alerts/${alert.alert_id}`, { status: 'reviewed' }, {
            onSuccess: () => removeAlert(alert.alert_id),
        });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60">
            <div
                className={`mx-4 max-w-md rounded-2xl p-8 shadow-2xl ${
                    immediate ? 'bg-red-950 text-red-50' : 'bg-white text-gray-900'
                }`}
            >
                <div
                    className={`mb-4 flex h-12 w-12 items-center justify-center rounded-full ${
                        immediate ? 'bg-red-800' : 'bg-red-100'
                    }`}
                >
                    <span className="text-2xl">{immediate ? '🚨' : crisis ? '🆘' : '⚠️'}</span>
                </div>

                <h2 className={`text-xl font-semibold ${immediate ? 'text-white' : ''}`}>
                    {immediate ? 'Possible emergency' : crisis ? 'Crisis safety alert' : 'Critical safety alert'}
                </h2>
                <p className={`mt-2 ${immediate ? 'text-red-100' : 'text-gray-600'}`}>
                    A critical concern was detected for <strong>{alert.student_name}</strong>.
                </p>
                <p className={`mt-1 text-sm capitalize ${immediate ? 'text-red-200' : 'text-gray-500'}`}>
                    {alert.category.replace(/_/g, ' ')}
                </p>

                <div
                    className={`mt-6 rounded-lg border p-4 text-sm ${
                        immediate
                            ? 'border-red-700 bg-red-900/50 text-red-100'
                            : 'border-red-100 bg-red-50 text-red-800'
                    }`}
                >
                    If this student may be in immediate danger, contact your school administration or emergency
                    services directly.
                </div>

                {alert.severity === 'critical' && (
                    <p className={`mt-4 text-sm ${immediate ? 'text-red-200' : 'text-gray-600'}`}>
                        The teacher was notified by email and on this dashboard.
                        {alert.counselor_notified
                            ? ' A designated crisis counselor email was also notified.'
                            : ''}
                    </p>
                )}

                <div className="mt-6">
                    <button
                        type="button"
                        onClick={acknowledge}
                        className={`w-full rounded-xl py-3 text-sm font-medium text-white ${
                            immediate ? 'bg-red-700 hover:bg-red-600' : 'bg-[#1E3A5F] hover:bg-[#162d4a]'
                        }`}
                    >
                        I understand — Mark reviewed
                    </button>
                </div>
            </div>
        </div>
    );
}
