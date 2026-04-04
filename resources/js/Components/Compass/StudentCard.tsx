import { Link } from '@inertiajs/react';
import type { AlertState, SessionState } from '@/stores/compass';
import { useCompassStore } from '@/stores/compass';

function sessionMatchesAlert(session: SessionState, alert: AlertState): boolean {
    if (alert.classroom_session_id && session.session_kind === 'classroom') {
        return alert.classroom_session_id === session.session_id;
    }

    return alert.session_id === session.session_id && session.session_kind !== 'classroom';
}

function statusDot(session: SessionState, liveAlerts: AlertState[]) {
    const hasAlert = liveAlerts.some((a) => sessionMatchesAlert(session, a));
    if (hasAlert) {
        return 'animate-pulse bg-red-500';
    }

    const lastActivity = session.last_activity_at
        ? new Date(session.last_activity_at)
        : new Date(session.started_at);
    const minutesIdle = (Date.now() - lastActivity.getTime()) / 60000;

    if (minutesIdle < 2) {
        return 'animate-pulse bg-green-500';
    }
    if (minutesIdle < 10) {
        return 'bg-amber-400';
    }

    return 'bg-gray-300';
}

export function StudentCard({ session }: { session: SessionState }) {
    const liveAlerts = useCompassStore((s) => s.alerts);

    return (
        <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md">
            <div className="flex items-start justify-between">
                <div className="flex items-center gap-2">
                    <span className={`h-2.5 w-2.5 rounded-full ${statusDot(session, liveAlerts)}`} />
                    <p className="text-sm font-medium text-gray-900">{session.student_name}</p>
                </div>
            </div>

            <p className="mt-1 text-xs text-gray-500">{session.space_title}</p>

            <p className="mt-2 text-xs text-gray-400">{session.message_count} messages</p>

            {session.last_message && (
                <p className="mt-2 truncate text-xs italic text-gray-500">&quot;{session.last_message}&quot;</p>
            )}

            <div className="mt-3 flex gap-2">
                <Link
                    href={
                        session.session_kind === 'classroom'
                            ? `/teach/compass/classroom/${session.session_id}`
                            : `/teach/compass/sessions/${session.session_id}`
                    }
                    className="flex-1 rounded-lg py-1.5 text-center text-xs font-medium text-[#1E3A5F] hover:opacity-90"
                    style={{ backgroundColor: '#EEF2F8' }}
                >
                    View session
                </Link>
            </div>
        </div>
    );
}
