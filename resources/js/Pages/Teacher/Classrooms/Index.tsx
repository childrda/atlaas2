import TeacherLayout from '@/Layouts/TeacherLayout';
import type { Classroom } from '@/types/models';
import type { School } from '@/types/models';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

export default function ClassroomsIndex() {
    const { classrooms, schools } = usePage().props as {
        classrooms: Paginated<Classroom>;
        schools: School[];
    };
    const [open, setOpen] = useState(false);
    const form = useForm({
        name: '',
        subject: '',
        grade_level: '',
        school_id: '' as string,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post('/teach/classrooms', {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    }

    return (
        <TeacherLayout>
            <div className="p-8">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-medium text-gray-900">Classrooms</h1>
                    <button
                        type="button"
                        onClick={() => setOpen(true)}
                        className="rounded-md px-4 py-2 text-sm font-medium text-white"
                        style={{ backgroundColor: '#1E3A5F' }}
                    >
                        New classroom
                    </button>
                </div>

                {open && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                        <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-lg">
                            <h2 className="text-lg font-semibold text-gray-900">Create classroom</h2>
                            <form onSubmit={submit} className="mt-4 space-y-3">
                                {schools.length > 0 && (
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700">School</label>
                                        <select
                                            required
                                            value={form.data.school_id}
                                            onChange={(e) => form.setData('school_id', e.target.value)}
                                            className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                        >
                                            <option value="">Select school</option>
                                            {schools.map((s) => (
                                                <option key={s.id} value={s.id}>
                                                    {s.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                )}
                                <div>
                                    <label className="block text-sm font-medium text-gray-700">Name</label>
                                    <input
                                        required
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                        className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                    />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700">Subject</label>
                                    <input
                                        value={form.data.subject}
                                        onChange={(e) => form.setData('subject', e.target.value)}
                                        className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                    />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700">Grade level</label>
                                    <input
                                        value={form.data.grade_level}
                                        onChange={(e) => form.setData('grade_level', e.target.value)}
                                        className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                    />
                                </div>
                                <div className="flex justify-end gap-2 pt-2">
                                    <button
                                        type="button"
                                        onClick={() => setOpen(false)}
                                        className="rounded-md px-3 py-1.5 text-sm text-gray-600"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={form.processing}
                                        className="rounded-md px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                                        style={{ backgroundColor: '#1E3A5F' }}
                                    >
                                        Create
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

                <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {classrooms.data.map((c) => (
                        <Link
                            key={c.id}
                            href={`/teach/classrooms/${c.id}`}
                            className="rounded-lg border border-gray-200 bg-white p-5 shadow-sm transition hover:border-[#1E3A5F]/30"
                        >
                            <h3 className="font-medium text-gray-900">{c.name}</h3>
                            <p className="mt-1 text-sm text-gray-500">
                                {[c.subject, c.grade_level].filter(Boolean).join(' · ') || '—'}
                            </p>
                            <p className="mt-3 text-xs text-gray-400">
                                {c.students_count ?? 0} students
                            </p>
                            <span
                                className="mt-3 inline-block rounded-full px-2 py-0.5 font-mono text-xs"
                                style={{ backgroundColor: '#F5A62322', color: '#1E3A5F' }}
                            >
                                {c.join_code}
                            </span>
                        </Link>
                    ))}
                </div>

                {classrooms.data.length === 0 && (
                    <p className="mt-8 text-center text-sm text-gray-500">No classrooms yet.</p>
                )}

                {classrooms.links.length > 3 && (
                    <div className="mt-8 flex flex-wrap gap-1">
                        {classrooms.links.map((l, i) => (
                            <button
                                key={i}
                                type="button"
                                disabled={!l.url || l.active}
                                onClick={() => l.url && router.get(l.url)}
                                className={`rounded px-3 py-1 text-sm ${
                                    l.active ? 'bg-[#1E3A5F] text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200'
                                }`}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </TeacherLayout>
    );
}
