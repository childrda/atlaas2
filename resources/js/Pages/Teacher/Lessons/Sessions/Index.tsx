import TeacherLayout from '@/Layouts/TeacherLayout';
import { Link, usePage } from '@inertiajs/react';

interface SessionRow {
    id: string;
    status: string;
    started_at: string | null;
    ended_at: string | null;
    student: { id: string; name: string };
    messages_count: number;
}

interface Paginated {
    data: SessionRow[];
    links: { url: string | null; label: string; active: boolean }[];
}

export default function LessonSessionsIndex() {
    const { lesson, sessions } = usePage().props as {
        lesson: { id: string; title: string };
        sessions: Paginated;
    };

    return (
        <TeacherLayout>
            <div className="p-8">
                <Link href={`/teach/lessons/${lesson.id}`} className="text-sm text-[#1E3A5F] hover:underline">
                    ← {lesson.title}
                </Link>
                <h1 className="mt-4 text-2xl font-medium text-gray-900">Classroom sessions</h1>
                <p className="mt-1 text-sm text-gray-500">Replay transcripts from multi-agent classroom runs.</p>

                <ul className="mt-8 divide-y divide-gray-200 rounded-lg border border-gray-200 bg-white">
                    {sessions.data.map((s) => (
                        <li key={s.id}>
                            <Link
                                href={`/teach/lessons/${lesson.id}/sessions/${s.id}`}
                                className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-gray-50"
                            >
                                <div>
                                    <span className="font-medium text-gray-900">{s.student.name}</span>
                                    <span className="ml-2 text-xs text-gray-500">
                                        {s.started_at ? new Date(s.started_at).toLocaleString() : '—'}
                                    </span>
                                </div>
                                <span className="text-xs text-gray-500">
                                    {s.status} · {s.messages_count} messages
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>

                {sessions.data.length === 0 && (
                    <p className="mt-8 text-sm text-gray-500">No sessions recorded for this lesson yet.</p>
                )}

                <div className="mt-6 flex flex-wrap justify-center gap-2">
                    {sessions.links.map((link, i) =>
                        link.url ? (
                            <Link
                                key={i}
                                href={link.url}
                                className={`rounded px-3 py-1 text-sm ${link.active ? 'bg-[#1E3A5F] text-white' : 'border border-gray-200 text-gray-600'}`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ) : null
                    )}
                </div>
            </div>
        </TeacherLayout>
    );
}
