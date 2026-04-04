import TeacherLayout from '@/Layouts/TeacherLayout';
import { router } from '@inertiajs/react';
import { useState } from 'react';

const ARCHETYPES = [
    { key: 'teacher', emoji: '👩‍🏫', label: 'Teacher', role: 'teacher', required: true },
    { key: 'assistant', emoji: '🤝', label: 'Teaching Assistant', role: 'assistant', required: false },
    { key: 'curious', emoji: '🤔', label: 'Sam (Curious)', role: 'student', required: false },
    { key: 'notetaker', emoji: '📝', label: 'Alex (Note-taker)', role: 'student', required: false },
    { key: 'skeptic', emoji: '🧐', label: 'Jordan (Skeptic)', role: 'student', required: false },
    { key: 'enthusiast', emoji: '🌟', label: 'Riley (Enthusiast)', role: 'student', required: false },
];

export default function LessonsCreate() {
    const [form, setForm] = useState({
        title: '',
        source_type: 'topic',
        source_text: '',
        subject: '',
        grade_level: '',
        language: 'en',
        agents: ['teacher', 'curious'] as string[],
    });
    const [submitting, setSubmitting] = useState(false);

    const toggleAgent = (key: string) => {
        if (key === 'teacher') return;
        setForm((f) => ({
            ...f,
            agents: f.agents.includes(key) ? f.agents.filter((a) => a !== key) : [...f.agents, key],
        }));
    };

    const submit = () => {
        setSubmitting(true);
        router.post(
            '/teach/lessons',
            {
                ...form,
                agents: form.agents.map((a) => ({ archetype: a })),
            },
            { onFinish: () => setSubmitting(false) }
        );
    };

    return (
        <TeacherLayout>
            <div className="mx-auto max-w-2xl space-y-6 py-8">
                <h1 className="text-2xl font-semibold text-gray-900">Create a new lesson</h1>

                <div className="space-y-2">
                    <label className="block text-sm font-medium text-gray-700">Lesson topic / requirements</label>
                    <textarea
                        className="h-32 w-full resize-none rounded-lg border p-3 text-sm focus:ring-2 focus:ring-blue-500"
                        placeholder="Describe what you want to teach..."
                        value={form.source_text}
                        onChange={(e) => setForm((f) => ({ ...f, source_text: e.target.value }))}
                    />
                </div>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div>
                        <label className="mb-1 block text-sm font-medium text-gray-700">Title</label>
                        <input
                            className="w-full rounded border p-2 text-sm"
                            value={form.title}
                            onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))}
                            placeholder="The Water Cycle"
                        />
                    </div>
                    <div>
                        <label className="mb-1 block text-sm font-medium text-gray-700">Subject</label>
                        <input
                            className="w-full rounded border p-2 text-sm"
                            value={form.subject}
                            onChange={(e) => setForm((f) => ({ ...f, subject: e.target.value }))}
                            placeholder="Science"
                        />
                    </div>
                    <div>
                        <label className="mb-1 block text-sm font-medium text-gray-700">Grade</label>
                        <select
                            className="w-full rounded border p-2 text-sm"
                            value={form.grade_level}
                            onChange={(e) => setForm((f) => ({ ...f, grade_level: e.target.value }))}
                        >
                            <option value="">General</option>
                            {['K', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12'].map((g) => (
                                <option key={g} value={g}>
                                    Grade {g}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                <div>
                    <label className="mb-2 block text-sm font-medium text-gray-700">Classroom agents</label>
                    <p className="mb-3 text-xs text-gray-500">The teacher agent is always included.</p>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        {ARCHETYPES.map((a) => {
                            const selected = form.agents.includes(a.key);
                            return (
                                <button
                                    key={a.key}
                                    type="button"
                                    onClick={() => toggleAgent(a.key)}
                                    className={`flex items-center gap-2 rounded-lg border p-3 text-left text-sm transition-colors ${
                                        selected ? 'border-blue-500 bg-blue-50 text-blue-800' : 'border-gray-200 hover:border-gray-300'
                                    } ${a.required ? 'cursor-default opacity-80' : 'cursor-pointer'}`}
                                >
                                    <span className="text-lg">{a.emoji}</span>
                                    <div>
                                        <div className="font-medium">{a.label}</div>
                                        <div className="text-xs capitalize text-gray-500">{a.role}</div>
                                    </div>
                                </button>
                            );
                        })}
                    </div>
                </div>

                <button
                    type="button"
                    onClick={submit}
                    disabled={submitting || !form.title || !form.source_text}
                    className="w-full rounded-lg py-3 text-sm font-medium text-white disabled:opacity-50"
                    style={{ backgroundColor: '#1E3A5F' }}
                >
                    {submitting ? 'Starting…' : 'Generate lesson'}
                </button>
            </div>
        </TeacherLayout>
    );
}
