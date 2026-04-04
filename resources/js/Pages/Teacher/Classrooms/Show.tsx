import TeacherLayout from '@/Layouts/TeacherLayout';
import type { Classroom, User } from '@/types/models';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

function copyCode(code: string) {
    void navigator.clipboard.writeText(code);
}

export default function ClassroomShow() {
    const { classroom } = usePage().props as { classroom: Classroom };
    const [copied, setCopied] = useState(false);
    const form = useForm({ email: '' });
    const editForm = useForm({
        name: classroom.name,
        subject: classroom.subject ?? '',
        grade_level: classroom.grade_level ?? '',
    });

    function addStudent(e: FormEvent) {
        e.preventDefault();
        form.post(`/teach/classrooms/${classroom.id}/students`, {
            preserveScroll: true,
            onSuccess: () => form.reset('email'),
        });
    }

    function updateClassroom(e: FormEvent) {
        e.preventDefault();
        editForm.patch(`/teach/classrooms/${classroom.id}`, { preserveScroll: true });
    }

    return (
        <TeacherLayout>
            <div className="p-8">
                <Link href="/teach/classrooms" className="text-sm text-[#1E3A5F] hover:underline">
                    ← Classrooms
                </Link>

                <div className="mt-4 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-medium text-gray-900">{classroom.name}</h1>
                        <p className="mt-1 text-sm text-gray-500">
                            {[classroom.subject, classroom.grade_level].filter(Boolean).join(' · ') || '—'}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => {
                                copyCode(classroom.join_code);
                                setCopied(true);
                                setTimeout(() => setCopied(false), 2000);
                            }}
                            className="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-mono"
                        >
                            {classroom.join_code} {copied ? '✓' : 'Copy'}
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                if (confirm('Archive this classroom?')) {
                                    router.delete(`/teach/classrooms/${classroom.id}`);
                                }
                            }}
                            className="rounded-md border border-red-200 px-3 py-1.5 text-sm text-red-700"
                        >
                            Archive
                        </button>
                    </div>
                </div>

                <form onSubmit={updateClassroom} className="mt-6 max-w-xl space-y-3 rounded-lg border border-gray-200 bg-white p-4">
                    <h2 className="text-sm font-semibold text-gray-900">Edit details</h2>
                    <input
                        value={editForm.data.name}
                        onChange={(e) => editForm.setData('name', e.target.value)}
                        className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                    />
                    <div className="flex gap-2">
                        <input
                            placeholder="Subject"
                            value={editForm.data.subject}
                            onChange={(e) => editForm.setData('subject', e.target.value)}
                            className="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm"
                        />
                        <input
                            placeholder="Grade"
                            value={editForm.data.grade_level}
                            onChange={(e) => editForm.setData('grade_level', e.target.value)}
                            className="w-28 rounded-md border border-gray-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <button
                        type="submit"
                        disabled={editForm.processing}
                        className="rounded-md px-3 py-1.5 text-sm text-white disabled:opacity-50"
                        style={{ backgroundColor: '#1E3A5F' }}
                    >
                        Save
                    </button>
                </form>

                <div className="mt-10">
                    <h2 className="text-lg font-medium text-gray-900">Students</h2>
                    <table className="mt-3 w-full text-left text-sm">
                        <thead>
                            <tr className="border-b text-gray-500">
                                <th className="py-2 pr-4">Name</th>
                                <th className="py-2 pr-4">Email</th>
                                <th className="py-2 pr-4">Enrolled</th>
                                <th className="py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {(classroom.students ?? []).map((s: User & { pivot?: { enrolled_at: string } }) => (
                                <tr key={s.id} className="border-b border-gray-100">
                                    <td className="py-2 pr-4">{s.name}</td>
                                    <td className="py-2 pr-4 text-gray-600">{s.email}</td>
                                    <td className="py-2 pr-4 text-gray-500">
                                        {s.pivot?.enrolled_at
                                            ? new Date(s.pivot.enrolled_at).toLocaleDateString()
                                            : '—'}
                                    </td>
                                    <td className="py-2 text-right">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.delete(
                                                    `/teach/classrooms/${classroom.id}/students/${s.id}`
                                                )
                                            }
                                            className="text-xs text-red-600 hover:underline"
                                        >
                                            Remove
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <form onSubmit={addStudent} className="mt-4 flex max-w-md gap-2">
                        <input
                            type="email"
                            placeholder="Student email"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            className="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm"
                        />
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-md px-3 py-2 text-sm text-white disabled:opacity-50"
                            style={{ backgroundColor: '#F5A623' }}
                        >
                            Add
                        </button>
                    </form>
                    {form.errors.email && (
                        <p className="mt-1 text-sm text-red-600">{form.errors.email}</p>
                    )}
                </div>

                <div className="mt-10">
                    <h2 className="text-lg font-medium text-gray-900">Spaces in this classroom</h2>
                    <ul className="mt-3 space-y-2">
                        {(classroom.spaces ?? []).map((sp) => (
                            <li key={sp.id}>
                                <Link
                                    href={`/teach/spaces/${sp.id}`}
                                    className="text-sm text-[#1E3A5F] hover:underline"
                                >
                                    {sp.title}
                                </Link>
                            </li>
                        ))}
                    </ul>
                    {(classroom.spaces ?? []).length === 0 && (
                        <p className="mt-2 text-sm text-gray-500">No spaces linked yet.</p>
                    )}
                </div>
            </div>
        </TeacherLayout>
    );
}
