import StudentLayout from '@/Layouts/StudentLayout';
import { studentModeLabel } from '@/lib/studentMode';
import type { LearningSpace } from '@/types/models';
import { Link, usePage } from '@inertiajs/react';

export default function StudentSpacesIndex() {
    const { spaces } = usePage().props as { spaces: LearningSpace[] };

    return (
        <StudentLayout>
            <div className="mx-auto max-w-3xl px-6 py-10">
                <Link href="/learn" className="text-sm text-[#1E3A5F] hover:underline">
                    ← Dashboard
                </Link>
                <h1 className="mt-4 text-2xl font-medium text-gray-900">Your spaces</h1>
                <div className="mt-8 grid gap-4 sm:grid-cols-2">
                    {spaces.map((s) => (
                        <div
                            key={s.id}
                            className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm"
                        >
                            <h2 className="font-medium text-gray-900">{s.title}</h2>
                            <p className="mt-1 text-sm text-gray-500">
                                {s.teacher?.name ?? 'Teacher'}
                                {s.subject ? ` · ${s.subject}` : ''}
                            </p>
                            <p className="mt-2 text-xs text-slate-600">{studentModeLabel(s.student_mode)}</p>
                            <Link
                                href={`/learn/spaces/${s.id}`}
                                className="mt-4 inline-block rounded-md px-4 py-2 text-sm font-medium text-white"
                                style={{ backgroundColor: '#F5A623' }}
                            >
                                Open
                            </Link>
                        </div>
                    ))}
                </div>
                {spaces.length === 0 && (
                    <p className="mt-8 text-center text-sm text-gray-500">
                        No spaces yet. Join a classroom on your dashboard.
                    </p>
                )}
            </div>
        </StudentLayout>
    );
}
