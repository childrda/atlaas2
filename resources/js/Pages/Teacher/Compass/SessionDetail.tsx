import TeacherLayout from '@/Layouts/TeacherLayout';
import type { LearningSpace } from '@/types/models';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

type MessageRow = {
    id: string;
    role: string;
    content: string;
    created_at: string;
};

type SessionRow = {
    id: string;
    status: string;
    student_id: string;
    space_id: string;
    student: { id: string; name: string };
    space: Pick<LearningSpace, 'id' | 'title'>;
};

export default function CompassSessionDetail() {
    const { session, messages } = usePage().props as {
        session: SessionRow;
        messages: MessageRow[];
    };

    const form = useForm({ content: '' });

    function submitInject(e: FormEvent) {
        e.preventDefault();
        form.post(`/teach/compass/sessions/${session.id}/inject`, {
            preserveScroll: true,
            onSuccess: () => form.reset('content'),
        });
    }

    function endSession() {
        if (!confirm('End this session for the student?')) {
            return;
        }
        router.post(`/teach/compass/sessions/${session.id}/end`);
    }

    return (
        <TeacherLayout>
            <div className="mx-auto flex max-w-3xl flex-col px-6 py-8">
                <Link href="/teach/compass" className="text-sm text-[#1E3A5F] hover:underline">
                    ← Compass View
                </Link>

                <div className="mt-6 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-medium text-gray-900">{session.student.name}</h1>
                        <p className="mt-1 text-sm text-gray-500">{session.space.title}</p>
                        <p className="mt-1 text-xs text-gray-400">Status: {session.status}</p>
                    </div>
                    {session.status === 'active' && (
                        <button
                            type="button"
                            onClick={endSession}
                            className="rounded-lg border border-red-200 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50"
                        >
                            End this session
                        </button>
                    )}
                </div>

                <div className="mt-8 space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <h2 className="text-sm font-semibold text-gray-900">Transcript</h2>
                    <div className="max-h-[50vh] space-y-3 overflow-y-auto">
                        {messages.length === 0 && (
                            <p className="text-sm text-gray-500">No messages yet.</p>
                        )}
                        {messages.map((m) => (
                            <div
                                key={m.id}
                                className={`rounded-lg px-3 py-2 text-sm ${
                                    m.role === 'teacher_inject'
                                        ? 'border border-amber-100 bg-amber-50 text-center text-amber-900'
                                        : m.role === 'user'
                                          ? 'ml-0 mr-8 bg-gray-100 text-gray-900'
                                          : 'ml-8 mr-0 bg-[#EEF2F8] text-gray-900'
                                }`}
                            >
                                {m.role === 'teacher_inject' && (
                                    <p className="mb-1 text-xs font-medium text-amber-800">Your teacher says:</p>
                                )}
                                <p className="whitespace-pre-wrap">{m.content}</p>
                                <p className="mt-1 text-[10px] text-gray-400">
                                    {m.role} · {new Date(m.created_at).toLocaleString()}
                                </p>
                            </div>
                        ))}
                    </div>
                </div>

                {session.status === 'active' && (
                    <form onSubmit={submitInject} className="mt-6 space-y-3">
                        <label htmlFor="inject" className="block text-sm font-medium text-gray-700">
                            Send a message to this student
                        </label>
                        <textarea
                            id="inject"
                            rows={3}
                            value={form.data.content}
                            onChange={(e) => form.setData('content', e.target.value)}
                            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-[#1E3A5F] focus:outline-none focus:ring-1 focus:ring-[#1E3A5F]"
                            placeholder="Your message appears in their chat."
                            maxLength={500}
                        />
                        {form.errors.content && (
                            <p className="text-sm text-red-600">{form.errors.content}</p>
                        )}
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-lg bg-[#1E3A5F] px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                        >
                            Send message
                        </button>
                    </form>
                )}
            </div>
        </TeacherLayout>
    );
}
