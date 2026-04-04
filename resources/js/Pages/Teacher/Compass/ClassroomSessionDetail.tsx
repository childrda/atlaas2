import TeacherLayout from '@/Layouts/TeacherLayout';
import type { LearningSpace } from '@/types/models';
import { Link, usePage } from '@inertiajs/react';

type MessageRow = {
    id: string;
    sender_type: string;
    content: string;
    agent_name?: string;
    created_at: string;
};

type SessionRow = {
    id: string;
    status: string;
    student_id: string;
    lesson: {
        title: string;
        space: Pick<LearningSpace, 'id' | 'title'> | null;
    };
    current_scene_id: string | null;
    student: { id: string; name: string };
};

export default function ClassroomSessionDetail() {
    const { session, messages } = usePage().props as {
        session: SessionRow;
        messages: MessageRow[];
    };

    const spaceTitle = session.lesson.space?.title ?? session.lesson.title;

    return (
        <TeacherLayout>
            <div className="mx-auto flex max-w-3xl flex-col px-6 py-8">
                <Link href="/teach/compass" className="text-sm text-[#1E3A5F] hover:underline">
                    ← Compass View
                </Link>

                <div className="mt-6 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-medium text-gray-900">{session.student.name}</h1>
                        <p className="mt-1 text-sm text-gray-500">{spaceTitle}</p>
                        <p className="mt-1 text-xs font-medium uppercase tracking-wide text-indigo-600">
                            Multi-agent classroom
                        </p>
                        <p className="mt-1 text-xs text-gray-400">Status: {session.status}</p>
                    </div>
                </div>

                <div className="mt-8 space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <h2 className="text-sm font-semibold text-gray-900">Transcript</h2>
                    <div className="max-h-[50vh] space-y-3 overflow-y-auto">
                        {messages.length === 0 && <p className="text-sm text-gray-500">No messages yet.</p>}
                        {messages.map((m) => (
                            <div
                                key={m.id}
                                className={`rounded-lg px-3 py-2 text-sm ${
                                    m.sender_type === 'student'
                                        ? 'ml-0 mr-8 bg-gray-100 text-gray-900'
                                        : 'ml-8 mr-0 bg-indigo-50 text-gray-900'
                                }`}
                            >
                                {m.sender_type === 'agent' && m.agent_name && (
                                    <p className="mb-1 text-xs font-medium text-indigo-800">{m.agent_name}</p>
                                )}
                                <p className="whitespace-pre-wrap">{m.content}</p>
                                <p className="mt-1 text-[10px] text-gray-400">
                                    {m.sender_type} · {new Date(m.created_at).toLocaleString()}
                                </p>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </TeacherLayout>
    );
}
