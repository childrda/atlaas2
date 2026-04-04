import type { AlertState, SessionState } from '@/stores/compass';
import { useCompassStore } from '@/stores/compass';
import { AlertTray } from '@/Components/Compass/AlertTray';
import { CriticalAlertModal } from '@/Components/Compass/CriticalAlertModal';
import { StudentCard } from '@/Components/Compass/StudentCard';
import { useCompassView } from '@/hooks/useCompassView';
import TeacherLayout from '@/Layouts/TeacherLayout';
import { useEffect, useRef } from 'react';

type OpenAlert = {
    id: string;
    session_id: string | null;
    classroom_session_id?: string | null;
    student_id: string;
    severity: AlertState['severity'];
    category: string;
    alert_type?: string | null;
    counselor_notified?: boolean;
    created_at: string;
    student: { id: string; name: string };
    session?: { space?: { id: string; title: string } | null } | null;
    classroom_session?: { lesson?: { space?: { id: string; title: string } | null } | null } | null;
};

interface Props {
    initialSessions: SessionState[];
    openAlerts: OpenAlert[];
    teacherId: string;
}

function mapOpenAlert(a: OpenAlert): AlertState {
    return {
        alert_id: a.id,
        session_id: a.session_id ?? '',
        classroom_session_id: a.classroom_session_id ? String(a.classroom_session_id) : undefined,
        student_id: a.student_id,
        student_name: a.student.name,
        severity: a.severity,
        category: a.category,
        alert_type: a.alert_type ?? undefined,
        counselor_notified: a.counselor_notified ?? false,
        timestamp: a.created_at,
        space_title: a.session?.space?.title ?? a.classroom_session?.lesson?.space?.title ?? undefined,
    };
}

export default function CompassIndex({ initialSessions, openAlerts, teacherId }: Props) {
    const sessions = useCompassStore((s) => s.sessions);
    const criticalAlert = useCompassStore((s) => s.criticalAlert);
    const trayAlerts = useCompassStore((s) => s.alerts);
    const upsertSession = useCompassStore((s) => s.upsertSession);
    const addAlert = useCompassStore((s) => s.addAlert);
    const seeded = useRef(false);

    useEffect(() => {
        if (seeded.current) {
            return;
        }
        seeded.current = true;
        initialSessions.forEach((s) => upsertSession(s));
        openAlerts.forEach((a) => addAlert(mapOpenAlert(a)));
    }, [initialSessions, openAlerts, upsertSession, addAlert]);

    useCompassView(teacherId);

    const sessionList = Object.values(sessions);

    return (
        <TeacherLayout>
            <div className="flex h-[calc(100vh-64px)] flex-col">
                <div className="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <div>
                        <h1 className="text-lg font-medium text-gray-900">Compass View</h1>
                        <p className="text-sm text-gray-500">
                            {sessionList.length} active {sessionList.length === 1 ? 'session' : 'sessions'}
                        </p>
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto p-6">
                    {sessionList.length === 0 ? (
                        <div className="flex h-full items-center justify-center">
                            <p className="text-gray-400">No active sessions right now.</p>
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 gap-4 lg:grid-cols-3 xl:grid-cols-4">
                            {sessionList.map((session) => (
                                <StudentCard key={session.session_id} session={session} />
                            ))}
                        </div>
                    )}
                </div>

                <AlertTray alerts={trayAlerts} />
            </div>

            {criticalAlert && <CriticalAlertModal alert={criticalAlert} />}
        </TeacherLayout>
    );
}
