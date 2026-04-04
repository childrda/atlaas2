import TeacherLayout from '@/Layouts/TeacherLayout';
import type { Classroom } from '@/types/models';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';


const tones = [
    { value: 'encouraging', label: 'Encouraging' },
    { value: 'socratic', label: 'Socratic' },
    { value: 'direct', label: 'Direct' },
    { value: 'playful', label: 'Playful' },
] as const;

type ModeOpt = { value: string; label: string };

export default function SpacesCreate() {
    const { classrooms, studentModeOptions } = usePage().props as {
        classrooms: Classroom[];
        studentModeOptions: ModeOpt[];
    };
    const [goalInputs, setGoalInputs] = useState<string[]>(['']);
    const [submitting, setSubmitting] = useState(false);

    const form = useForm({
        title: '',
        description: '',
        subject: '',
        grade_level: '',
        classroom_id: '' as string,
        system_prompt: '',
        goals: [] as string[],
        atlaas_tone: 'encouraging' as string,
        language: 'en',
        max_messages: '' as string | number,
        student_mode: (studentModeOptions[0]?.value ?? 'teacher_session') as string,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        const goals = goalInputs.map((g) => g.trim()).filter(Boolean).slice(0, 5);
        setSubmitting(true);
        router.post(
            '/teach/spaces',
            {
                title: form.data.title,
                description: form.data.description || null,
                subject: form.data.subject || null,
                grade_level: form.data.grade_level || null,
                classroom_id: form.data.classroom_id || null,
                system_prompt: form.data.system_prompt || null,
                goals,
                atlaas_tone: form.data.atlaas_tone,
                language: form.data.language,
                max_messages:
                    form.data.max_messages === '' || form.data.max_messages === null
                        ? null
                        : Number(form.data.max_messages),
                student_mode: form.data.student_mode,
            },
            { onFinish: () => setSubmitting(false) }
        );
    }

    return (
        <TeacherLayout>
            <div className="mx-auto max-w-2xl p-8">
                <Link href="/teach/spaces" className="text-sm text-[#1E3A5F] hover:underline">
                    ← Spaces
                </Link>
                <h1 className="mt-4 text-2xl font-medium text-gray-900">Create space</h1>

                <form onSubmit={submit} className="mt-8 space-y-5">
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Title</label>
                        <input
                            required
                            value={form.data.title}
                            onChange={(e) => form.setData('title', e.target.value)}
                            className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Description</label>
                        <textarea
                            rows={3}
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                            className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div className="flex gap-3">
                        <div className="flex-1">
                            <label className="block text-sm font-medium text-gray-700">Subject</label>
                            <input
                                value={form.data.subject}
                                onChange={(e) => form.setData('subject', e.target.value)}
                                className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                            />
                        </div>
                        <div className="w-28">
                            <label className="block text-sm font-medium text-gray-700">Grade</label>
                            <input
                                value={form.data.grade_level}
                                onChange={(e) => form.setData('grade_level', e.target.value)}
                                className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                            />
                        </div>
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Student interaction mode</label>
                        <p className="mt-0.5 text-xs text-gray-500">
                            Set by your district. Scope and safety layers apply per mode.
                        </p>
                        <div className="mt-2 space-y-2">
                            {studentModeOptions.map((opt) => (
                                <label key={opt.value} className="flex items-center gap-2 text-sm">
                                    <input
                                        type="radio"
                                        name="student_mode"
                                        value={opt.value}
                                        checked={form.data.student_mode === opt.value}
                                        onChange={() => form.setData('student_mode', opt.value)}
                                    />
                                    {opt.label}
                                </label>
                            ))}
                        </div>
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Classroom</label>
                        <select
                            value={form.data.classroom_id}
                            onChange={(e) => form.setData('classroom_id', e.target.value)}
                            className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                        >
                            <option value="">None</option>
                            {classrooms.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">
                            Instructions for ATLAAS (system prompt)
                        </label>
                        <textarea
                            rows={5}
                            value={form.data.system_prompt}
                            onChange={(e) => form.setData('system_prompt', e.target.value)}
                            className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Goals (up to 5)</label>
                        {goalInputs.map((g, i) => (
                            <input
                                key={i}
                                value={g}
                                onChange={(e) => {
                                    const next = [...goalInputs];
                                    next[i] = e.target.value;
                                    setGoalInputs(next);
                                }}
                                className="mt-2 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                placeholder={`Goal ${i + 1}`}
                            />
                        ))}
                        {goalInputs.length < 5 && (
                            <button
                                type="button"
                                onClick={() => setGoalInputs([...goalInputs, ''])}
                                className="mt-2 text-sm text-[#1E3A5F] hover:underline"
                            >
                                + Add goal
                            </button>
                        )}
                    </div>
                    <fieldset>
                        <legend className="text-sm font-medium text-gray-700">Tone</legend>
                        <div className="mt-2 flex flex-wrap gap-3">
                            {tones.map((t) => (
                                <label key={t.value} className="flex items-center gap-2 text-sm">
                                    <input
                                        type="radio"
                                        name="atlaas_tone"
                                        value={t.value}
                                        checked={form.data.atlaas_tone === t.value}
                                        onChange={() => form.setData('atlaas_tone', t.value)}
                                    />
                                    {t.label}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Max messages (optional)</label>
                        <input
                            type="number"
                            min={5}
                            max={500}
                            value={form.data.max_messages}
                            onChange={(e) => form.setData('max_messages', e.target.value)}
                            className="mt-1 w-40 rounded-md border border-gray-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <button
                        type="submit"
                        disabled={submitting}
                        className="rounded-md px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                        style={{ backgroundColor: '#1E3A5F' }}
                    >
                        Create space
                    </button>
                </form>
            </div>
        </TeacherLayout>
    );
}
