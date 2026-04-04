import { create } from 'zustand';

export interface SessionState {
    session_id: string;
    session_kind?: 'chat' | 'classroom';
    student_id: string;
    student_name: string;
    space_id: string;
    space_title: string;
    started_at: string;
    message_count: number;
    status: string;
    last_message: string | null;
    last_activity_at?: string | null;
}

export interface AlertState {
    alert_id: string;
    session_id: string;
    classroom_session_id?: string;
    student_id: string;
    student_name: string;
    severity: 'critical' | 'high' | 'medium' | 'low';
    category: string;
    alert_type?: string;
    counselor_notified?: boolean;
    timestamp: string;
    space_title?: string;
}

interface CompassStore {
    sessions: Record<string, SessionState>;
    alerts: AlertState[];
    criticalAlert: AlertState | null;

    upsertSession: (data: Partial<SessionState> & { session_id: string }) => void;
    removeSession: (sessionId: string) => void;
    addAlert: (alert: AlertState) => void;
    removeAlert: (alertId: string) => void;
}

export const useCompassStore = create<CompassStore>((set) => ({
    sessions: {},
    alerts: [],
    criticalAlert: null,

    upsertSession: (data) =>
        set((state) => ({
            sessions: {
                ...state.sessions,
                [data.session_id]: {
                    ...state.sessions[data.session_id],
                    ...data,
                } as SessionState,
            },
        })),

    removeSession: (sessionId) =>
        set((state) => {
            const { [sessionId]: _, ...rest } = state.sessions;

            return { sessions: rest };
        }),

    addAlert: (alert) =>
        set((state) => ({
            alerts: [alert, ...state.alerts.filter((a) => a.alert_id !== alert.alert_id)],
            criticalAlert:
                alert.severity === 'critical' ? alert : state.criticalAlert,
        })),

    removeAlert: (alertId) =>
        set((state) => ({
            alerts: state.alerts.filter((a) => a.alert_id !== alertId),
            criticalAlert: state.criticalAlert?.alert_id === alertId ? null : state.criticalAlert,
        })),
}));
