import { useState } from 'react';
import { router } from '@inertiajs/react';
import type { AlertState } from '@/stores/compass';
import { useCompassStore } from '@/stores/compass';

function alertPresentation(a: AlertState): { icon: string; label: string; rowClass: string; badgeClass: string } {
    const at = a.alert_type ?? '';
    const cat = a.category ?? '';

    if (at === 'off_topic' || cat === 'scope:off_topic') {
        return {
            icon: 'ℹ️',
            label: 'Off topic',
            rowClass: 'border-blue-200 bg-blue-50/80',
            badgeClass: 'bg-blue-100 text-blue-900',
        };
    }
    if (at === 'academic_integrity' || cat === 'academic_integrity') {
        return {
            icon: '📋',
            label: 'Academic integrity',
            rowClass: 'border-amber-200 bg-amber-50/80',
            badgeClass: 'bg-amber-100 text-amber-950',
        };
    }
    if (at === 'crisis_immediate_danger' || cat === 'crisis_immediate_danger') {
        return {
            icon: '🚨',
            label: 'Immediate danger',
            rowClass: 'border-red-800 bg-red-950 text-red-50',
            badgeClass: 'bg-red-900 text-white',
        };
    }
    if (at.startsWith('crisis_') || cat.startsWith('crisis_')) {
        return {
            icon: '🆘',
            label: 'Crisis',
            rowClass: 'border-red-200 bg-red-50/90',
            badgeClass: 'bg-red-200 text-red-950',
        };
    }
    return {
        icon: '⚠️',
        label: 'Safety',
        rowClass: 'border-orange-200 bg-orange-50/80',
        badgeClass: 'bg-orange-100 text-orange-950',
    };
}

function trayTone(alerts: AlertState[]): { wrap: string; btn: string } {
    if (alerts.some((a) => a.alert_type === 'crisis_immediate_danger' || a.category === 'crisis_immediate_danger')) {
        return { wrap: 'border-t border-red-900/50 bg-red-950', btn: 'text-red-100' };
    }
    if (alerts.some((a) => a.severity === 'critical')) {
        return { wrap: 'border-t border-red-200 bg-red-50', btn: 'text-red-800' };
    }
    if (alerts.every((a) => a.alert_type === 'off_topic' || a.category === 'scope:off_topic')) {
        return { wrap: 'border-t border-blue-200 bg-blue-50', btn: 'text-blue-900' };
    }
    return { wrap: 'border-t border-amber-200 bg-amber-50/90', btn: 'text-amber-950' };
}

export function AlertTray({ alerts }: { alerts: AlertState[] }) {
    const [isOpen, setIsOpen] = useState(alerts.length > 0);
    const removeAlert = useCompassStore((s) => s.removeAlert);

    if (alerts.length === 0) {
        return null;
    }

    const tone = trayTone(alerts);

    function markReviewed(alertId: string) {
        router.patch(
            `/teach/alerts/${alertId}`,
            { status: 'reviewed' },
            { onSuccess: () => removeAlert(alertId) },
        );
    }

    return (
        <div className={tone.wrap}>
            <button
                type="button"
                onClick={() => setIsOpen(!isOpen)}
                className={`flex w-full items-center justify-between px-6 py-3 text-sm font-medium ${tone.btn}`}
            >
                <span>
                    {alerts.length} open {alerts.length === 1 ? 'alert' : 'alerts'}
                </span>
                <span>{isOpen ? '▼' : '▲'}</span>
            </button>

            {isOpen && (
                <div className="max-h-52 space-y-2 overflow-y-auto px-6 pb-4">
                    {alerts.map((alert) => {
                        const p = alertPresentation(alert);
                        return (
                            <div
                                key={alert.alert_id}
                                className={`flex items-center justify-between rounded-lg border px-4 py-2 ${p.rowClass}`}
                            >
                                <div className="min-w-0 flex-1">
                                    <span className="mr-1.5" aria-hidden>
                                        {p.icon}
                                    </span>
                                    <span className={`rounded px-1.5 py-0.5 text-xs font-medium ${p.badgeClass}`}>
                                        {p.label}
                                    </span>
                                    <span
                                        className={`ml-2 text-sm ${
                                            p.rowClass.includes('red-950') ? 'text-red-100' : 'text-gray-800'
                                        }`}
                                    >
                                        {alert.student_name}
                                    </span>
                                    {alert.space_title && (
                                        <span
                                            className={`ml-2 text-xs ${
                                                p.rowClass.includes('red-950') ? 'text-red-200' : 'text-gray-500'
                                            }`}
                                        >
                                            {alert.space_title}
                                        </span>
                                    )}
                                </div>
                                <button
                                    type="button"
                                    onClick={() => markReviewed(alert.alert_id)}
                                    className={`shrink-0 text-xs underline ${
                                        p.rowClass.includes('red-950')
                                            ? 'text-red-200 hover:text-white'
                                            : 'text-gray-600 hover:text-gray-900'
                                    }`}
                                >
                                    Mark reviewed
                                </button>
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
