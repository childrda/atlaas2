import { useCurrentUser } from '@/hooks/useCurrentUser';
import StudentLayout from '@/Layouts/StudentLayout';
import type { CompletedSessionRow, LearningSpace } from '@/types/models';
import { Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function StudentDashboard() {
    const user = useCurrentUser();
    const { enrolledSpaces, completedSessions, flash } = usePage().props as {
        enrolledSpaces: LearningSpace[];
        completedSessions: CompletedSessionRow[];
        flash?: { success?: string };
    };
    const joinForm = useForm({ code: '' });

    function joinSubmit(e: FormEvent) {
        e.preventDefault();
        joinForm.post('/learn/join');
    }

    function formatEndedAt(iso: string): string {
        return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    }

    return (
        <StudentLayout>
            <div className="mx-auto max-w-2xl px-6 py-10">
                {flash?.success && (
                    <div className="mb-6 rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}

                <h1 className="text-4xl font-medium" style={{ color: '#F5A623' }}>
                    Hello, {user.name.split(' ')[0]}!
                </h1>
                <p className="mt-3 text-lg text-gray-500">
                    Your learning spaces will appear here.
                </p>

                <div className="mt-10 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 className="text-sm font-semibold text-gray-900">Join a space or classroom</h2>
                    <p className="mt-1 text-xs text-gray-500">Enter the join code from your teacher.</p>
                    <form onSubmit={joinSubmit} className="mt-3 flex gap-2">
                        <input
                            value={joinForm.data.code}
                            onChange={(e) => joinForm.setData('code', e.target.value.toUpperCase())}
                            placeholder="Code"
                            className="flex-1 rounded-md border border-gray-300 px-3 py-2 font-mono text-sm uppercase"
                            maxLength={10}
                        />
                        <button
                            type="submit"
                            disabled={joinForm.processing}
                            className="rounded-md px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                            style={{ backgroundColor: '#1E3A5F' }}
                        >
                            Join
                        </button>
                    </form>
                    {joinForm.errors.code && (
                        <p className="mt-2 text-sm text-red-600">{joinForm.errors.code}</p>
                    )}
                </div>

                <div className="mt-10">
                    <div className="flex items-center justify-between">
                        <h2 className="text-lg font-medium text-gray-900">Your spaces</h2>
                        <Link href="/learn/spaces" className="text-sm text-[#1E3A5F] hover:underline">
                            View all
                        </Link>
                    </div>
                    <ul className="mt-4 space-y-2">
                        {enrolledSpaces.map((s) => (
                            <li key={s.id}>
                                <Link
                                    href={`/learn/spaces/${s.id}`}
                                    className="block rounded-lg border border-gray-100 bg-white px-4 py-3 text-sm shadow-sm hover:border-[#F5A623]/50"
                                >
                                    <span className="font-medium text-gray-900">{s.title}</span>
                                    <span className="mt-0.5 block text-xs text-gray-500">
                                        {s.teacher?.name ?? 'Teacher'}
                                        {s.subject ? ` · ${s.subject}` : ''}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                    {enrolledSpaces.length === 0 && (
                        <p className="mt-4 text-sm text-gray-500">
                            You are not enrolled in any published spaces yet. Join with a code above.
                        </p>
                    )}
                </div>

                {completedSessions.length > 0 && (
                    <div className="mt-12">
                        <h2 className="text-lg font-medium text-gray-900">Your recent sessions</h2>
                        <ul className="mt-4 space-y-3">
                            {completedSessions.map((session) => (
                                <li
                                    key={session.id}
                                    className="rounded-lg border border-gray-100 bg-white p-4 shadow-sm"
                                >
                                    <p className="text-sm font-medium text-gray-900">{session.space.title}</p>
                                    <p className="mt-1 text-sm text-gray-600">{session.student_summary}</p>
                                    <p className="mt-2 text-xs text-gray-400">
                                        {session.message_count} messages · {formatEndedAt(session.ended_at)}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {import.meta.env.DEV && (
                    <div className="mt-12 rounded border border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-800">
                        <span className="font-medium">Dev:</span>{' '}
                        Role: {user.roles.join(', ')} | District: {user.district.name}
                    </div>
                )}
            </div>
        </StudentLayout>
    );
}
