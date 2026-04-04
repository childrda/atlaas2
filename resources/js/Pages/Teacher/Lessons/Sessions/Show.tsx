import TeacherLayout from '@/Layouts/TeacherLayout';
import { Link, usePage } from '@inertiajs/react';

interface MessageRow {
    id: string;
    sender_type: string;
    content: string;
    agent_name?: string;
    created_at: string;
}

export default function LessonSessionShow() {
    const { lesson, session, messages } = usePage().props as {
        lesson: { id: string; title: string };
        session: {
            id: string;
            status: string;
            started_at: string | null;
            ended_at: string | null;
            student: { id: string; name: string };
        };
        messages: MessageRow[];
    };

    return (
        <TeacherLayout>
            <div className="mx-auto max-w-3xl p-8">
                <Link
                    href={`/teach/lessons/${lesson.id}/sessions`}
                    className="text-sm text-[#1E3A5F] hover:underline"
                >
                    ← Sessions
                </Link>

                <h1 className="mt-4 text-xl font-medium text-gray-900">{session.student.name}</h1>
                <p className="mt-1 text-sm text-gray-500">{lesson.title}</p>
                <p className="mt-1 text-xs text-gray-400">
                    {session.status}
                    {session.started_at && ` · started ${new Date(session.started_at).toLocaleString()}`}
                </p>

                <div className="mt-8 space-y-3 rounded-xl border border-gray-200 bg-white p-4">
                    <h2 className="text-sm font-semibold text-gray-900">Transcript</h2>
                    {messages.length === 0 && <p className="text-sm text-gray-500">No messages.</p>}
                    {messages.map((m) => (
                        <div
                            key={m.id}
                            className={`rounded-lg px-3 py-2 text-sm ${
                                m.sender_type === 'student' ? 'bg-gray-50' : 'bg-indigo-50'
                            }`}
                        >
                            {m.agent_name && (
                                <p className="mb-1 text-xs font-medium text-indigo-800">{m.agent_name}</p>
                            )}
                            <p className="whitespace-pre-wrap text-gray-800">{m.content}</p>
                            <p className="mt-1 text-[10px] text-gray-400">
                                {m.sender_type} · {new Date(m.created_at).toLocaleString()}
                            </p>
                        </div>
                    ))}
                </div>
            </div>
        </TeacherLayout>
    );
}
