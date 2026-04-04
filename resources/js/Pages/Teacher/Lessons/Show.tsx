import TeacherLayout from '@/Layouts/TeacherLayout';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useCallback, useEffect, useMemo, useState } from 'react';

interface Scene {
    id: string;
    title: string;
    scene_type: string;
    generation_status: string;
    sequence_order: number;
}

interface Agent {
    id: string;
    display_name: string;
    role: string;
    archetype: string;
    is_active: boolean;
    system_prompt_addendum: string | null;
}

interface SpaceOpt {
    id: string;
    title: string;
}

interface Lesson {
    id: string;
    title: string;
    subject: string | null;
    grade_level: string | null;
    language: string;
    generation_status: string;
    generation_progress: Record<string, unknown> | null;
    status: string;
    space_id: string | null;
    scenes: Scene[];
    agents: Agent[];
}

export default function LessonsShow() {
    const { lesson, teacherSpaces, flash } = usePage().props as {
        lesson: Lesson;
        teacherSpaces: SpaceOpt[];
        flash?: { success?: string };
    };

    const [status, setStatus] = useState(lesson.generation_status);
    const [progress, setProgress] = useState(lesson.generation_progress);
    const [scenes, setScenes] = useState<Scene[]>(lesson.scenes ?? []);
    const [spaceId, setSpaceId] = useState(lesson.space_id ?? '');
    const [sceneOrder, setSceneOrder] = useState<string[]>(() =>
        [...(lesson.scenes ?? [])].sort((a, b) => a.sequence_order - b.sequence_order).map((s) => s.id),
    );

    const lessonForm = useForm({
        title: lesson.title,
        subject: lesson.subject ?? '',
        grade_level: lesson.grade_level ?? '',
        language: lesson.language,
        status: lesson.status,
    });

    const addSceneForm = useForm({
        scene_type: 'slide' as Scene['scene_type'],
        title: '',
    });

    useEffect(() => {
        setScenes(lesson.scenes ?? []);
        setSceneOrder(
            [...(lesson.scenes ?? [])].sort((a, b) => a.sequence_order - b.sequence_order).map((s) => s.id),
        );
    }, [lesson.scenes]);

    useEffect(() => {
        if (status === 'completed' || status === 'failed') return;

        const id = setInterval(async () => {
            try {
                const res = await fetch(`/teach/lessons/${lesson.id}/status`, {
                    headers: { Accept: 'application/json' },
                });
                if (!res.ok) return;
                const data = await res.json();
                setStatus(data.generation_status);
                setProgress(data.generation_progress);
                setScenes(data.scenes ?? []);
            } catch {
                /* ignore */
            }
        }, 5000);

        return () => clearInterval(id);
    }, [lesson.id, status]);

    const publish = (e: FormEvent) => {
        e.preventDefault();
        if (!spaceId) return;
        router.post(`/teach/lessons/${lesson.id}/publish`, { space_id: spaceId });
    };

    const saveLessonMeta = (e: FormEvent) => {
        e.preventDefault();
        lessonForm.patch(`/teach/lessons/${lesson.id}`);
    };

    const deleteLesson = () => {
        if (!confirm('Delete this lesson? Students will no longer access it from linked spaces.')) return;
        router.delete(`/teach/lessons/${lesson.id}`);
    };

    const orderedScenes = useMemo(() => {
        const map = new Map(scenes.map((s) => [s.id, s]));
        return sceneOrder.map((id) => map.get(id)).filter(Boolean) as Scene[];
    }, [scenes, sceneOrder]);

    const moveScene = useCallback((index: number, dir: -1 | 1) => {
        const next = index + dir;
        if (next < 0 || next >= sceneOrder.length) return;
        setSceneOrder((o) => {
            const copy = [...o];
            const t = copy[index];
            copy[index] = copy[next];
            copy[next] = t;
            return copy;
        });
    }, [sceneOrder.length]);

    const saveSceneOrder = () => {
        router.patch(`/teach/lessons/${lesson.id}/scenes/reorder`, { scene_ids: sceneOrder });
    };

    const addScene = (e: FormEvent) => {
        e.preventDefault();
        addSceneForm.post(`/teach/lessons/${lesson.id}/scenes`, {
            preserveScroll: true,
            onSuccess: () => addSceneForm.reset('title'),
        });
    };

    const pct =
        typeof progress?.progress === 'number'
            ? progress.progress
            : status === 'completed'
              ? 100
              : 0;

    return (
        <TeacherLayout>
            <div className="p-8">
                <Link href="/teach/lessons" className="text-sm text-[#1E3A5F] hover:underline">
                    ← Lessons
                </Link>

                {flash?.success && (
                    <div className="mt-4 rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}

                <div className="mt-4 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-medium text-gray-900">{lesson.title}</h1>
                        <p className="mt-2 text-sm text-gray-500">
                            Status: <strong>{status}</strong>
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <a
                            href={`/teach/lessons/${lesson.id}/export?format=html`}
                            className="rounded-md border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
                        >
                            Export HTML
                        </a>
                        <Link
                            href={`/teach/lessons/${lesson.id}/sessions`}
                            className="rounded-md border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
                        >
                            Session replay
                        </Link>
                        <button
                            type="button"
                            onClick={deleteLesson}
                            className="rounded-md border border-red-200 px-3 py-1.5 text-xs text-red-700 hover:bg-red-50"
                        >
                            Delete lesson
                        </button>
                    </div>
                </div>

                <form onSubmit={saveLessonMeta} className="mt-8 max-w-xl space-y-3 rounded-lg border border-gray-200 bg-white p-4">
                    <h2 className="text-sm font-semibold text-gray-800">Lesson details</h2>
                    <div>
                        <label className="text-xs font-medium text-gray-600">Title</label>
                        <input
                            className="mt-1 w-full rounded border border-gray-300 px-2 py-1.5 text-sm"
                            value={lessonForm.data.title}
                            onChange={(e) => lessonForm.setData('title', e.target.value)}
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-2">
                        <div>
                            <label className="text-xs font-medium text-gray-600">Subject</label>
                            <input
                                className="mt-1 w-full rounded border border-gray-300 px-2 py-1.5 text-sm"
                                value={lessonForm.data.subject}
                                onChange={(e) => lessonForm.setData('subject', e.target.value)}
                            />
                        </div>
                        <div>
                            <label className="text-xs font-medium text-gray-600">Grade</label>
                            <input
                                className="mt-1 w-full rounded border border-gray-300 px-2 py-1.5 text-sm"
                                value={lessonForm.data.grade_level}
                                onChange={(e) => lessonForm.setData('grade_level', e.target.value)}
                            />
                        </div>
                    </div>
                    <div>
                        <label className="text-xs font-medium text-gray-600">Lesson status</label>
                        <select
                            className="mt-1 w-full rounded border border-gray-300 px-2 py-1.5 text-sm"
                            value={lessonForm.data.status}
                            onChange={(e) => lessonForm.setData('status', e.target.value)}
                        >
                            <option value="draft">draft</option>
                            <option value="published">published</option>
                            <option value="archived">archived</option>
                        </select>
                    </div>
                    <button
                        type="submit"
                        disabled={lessonForm.processing}
                        className="rounded-md bg-[#1E3A5F] px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50"
                    >
                        Save details
                    </button>
                </form>

                {status !== 'completed' && status !== 'failed' && (
                    <div className="mt-6 max-w-xl">
                        <div className="h-2 w-full rounded-full bg-gray-200">
                            <div
                                className="h-2 rounded-full bg-blue-500 transition-all"
                                style={{ width: `${pct}%` }}
                            />
                        </div>
                        <p className="mt-2 text-xs text-gray-500">Generation in progress… polling every 5s.</p>
                    </div>
                )}

                <div className="mt-10">
                    <h2 className="text-sm font-semibold text-gray-800">Scenes</h2>
                    <div className="mt-2 flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={saveSceneOrder}
                            className="rounded border border-gray-300 px-2 py-1 text-xs text-gray-700 hover:bg-gray-50"
                        >
                            Save scene order
                        </button>
                    </div>
                    <ol className="mt-3 list-decimal space-y-3 pl-5 text-sm text-gray-700">
                        {orderedScenes.map((s, i) => (
                            <li key={s.id} className="rounded-lg border border-gray-100 bg-white p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <span className="font-medium">{s.title}</span>{' '}
                                        <span className="text-gray-400">
                                            ({s.scene_type}) — {s.generation_status}
                                        </span>
                                    </div>
                                    <div className="flex flex-wrap gap-1">
                                        <button
                                            type="button"
                                            className="rounded border px-2 py-0.5 text-xs"
                                            onClick={() => moveScene(i, -1)}
                                            disabled={i === 0}
                                        >
                                            Up
                                        </button>
                                        <button
                                            type="button"
                                            className="rounded border px-2 py-0.5 text-xs"
                                            onClick={() => moveScene(i, 1)}
                                            disabled={i === orderedScenes.length - 1}
                                        >
                                            Down
                                        </button>
                                        <Link
                                            href={`/teach/lessons/${lesson.id}/scenes/${s.id}/edit`}
                                            className="rounded border border-[#1E3A5F] px-2 py-0.5 text-xs text-[#1E3A5F]"
                                        >
                                            Edit
                                        </Link>
                                        <button
                                            type="button"
                                            className="rounded border border-red-200 px-2 py-0.5 text-xs text-red-700"
                                            onClick={() => {
                                                if (confirm('Remove this scene?')) {
                                                    router.delete(`/teach/lessons/${lesson.id}/scenes/${s.id}`);
                                                }
                                            }}
                                        >
                                            Remove
                                        </button>
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ol>

                    <form onSubmit={addScene} className="mt-6 flex max-w-lg flex-wrap items-end gap-2 rounded-lg border border-dashed border-gray-300 p-4">
                        <div className="min-w-[140px] flex-1">
                            <label className="text-xs text-gray-600">New scene type</label>
                            <select
                                className="mt-1 w-full rounded border px-2 py-1 text-sm"
                                value={addSceneForm.data.scene_type}
                                onChange={(e) =>
                                    addSceneForm.setData('scene_type', e.target.value as Scene['scene_type'])
                                }
                            >
                                <option value="slide">slide</option>
                                <option value="quiz">quiz</option>
                                <option value="discussion">discussion</option>
                                <option value="interactive">interactive</option>
                                <option value="pbl">pbl</option>
                            </select>
                        </div>
                        <div className="min-w-[160px] flex-[2]">
                            <label className="text-xs text-gray-600">Title</label>
                            <input
                                className="mt-1 w-full rounded border px-2 py-1 text-sm"
                                value={addSceneForm.data.title}
                                onChange={(e) => addSceneForm.setData('title', e.target.value)}
                                required
                            />
                        </div>
                        <button
                            type="submit"
                            disabled={addSceneForm.processing}
                            className="rounded bg-gray-800 px-3 py-1.5 text-xs text-white disabled:opacity-50"
                        >
                            Add scene
                        </button>
                    </form>
                </div>

                <div className="mt-10">
                    <h2 className="text-sm font-semibold text-gray-800">Agents &amp; prompt addendum</h2>
                    <p className="mt-1 text-xs text-gray-500">
                        Addenda are sanitized (no HTML, length-capped) and appended to each agent&apos;s system prompt.
                    </p>
                    <div className="mt-4 space-y-4">
                        {lesson.agents.map((agent) => (
                            <AgentAddendumForm key={agent.id} lessonId={lesson.id} agent={agent} />
                        ))}
                    </div>
                </div>

                {status === 'completed' && (
                    <form onSubmit={publish} className="mt-10 max-w-md space-y-3 rounded-lg border border-gray-200 bg-white p-4">
                        <h2 className="text-sm font-semibold text-gray-800">Publish to space</h2>
                        <select
                            className="w-full rounded border p-2 text-sm"
                            value={spaceId}
                            onChange={(e) => setSpaceId(e.target.value)}
                            required
                        >
                            <option value="">Select a learning space…</option>
                            {teacherSpaces.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.title}
                                </option>
                            ))}
                        </select>
                        <button
                            type="submit"
                            className="rounded-md px-4 py-2 text-sm font-medium text-white"
                            style={{ backgroundColor: '#1E3A5F' }}
                        >
                            Publish
                        </button>
                        {lesson.status === 'published' && (
                            <p className="text-xs text-green-600">This lesson is published.</p>
                        )}
                    </form>
                )}
            </div>
        </TeacherLayout>
    );
}

function AgentAddendumForm({ lessonId, agent }: { lessonId: string; agent: Agent }) {
    const form = useForm({
        display_name: agent.display_name,
        is_active: agent.is_active,
        system_prompt_addendum: agent.system_prompt_addendum ?? '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.patch(`/teach/lessons/${lessonId}/agents/${agent.id}`);
    };

    return (
        <form
            onSubmit={submit}
            className="rounded-lg border border-gray-200 bg-gray-50/80 p-4"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <span className="font-medium text-gray-900">{agent.display_name}</span>
                    <span className="ml-2 text-xs text-gray-500">
                        {agent.role} · {agent.archetype}
                    </span>
                </div>
                <label className="flex items-center gap-1 text-xs text-gray-600">
                    <input
                        type="checkbox"
                        checked={form.data.is_active}
                        onChange={(e) => form.setData('is_active', e.target.checked)}
                    />
                    Active
                </label>
            </div>
            <div className="mt-2">
                <label className="text-xs text-gray-600">Display name</label>
                <input
                    className="mt-1 w-full rounded border border-gray-300 bg-white px-2 py-1 text-sm"
                    value={form.data.display_name}
                    onChange={(e) => form.setData('display_name', e.target.value)}
                />
            </div>
            <div className="mt-2">
                <label className="text-xs text-gray-600">System prompt addendum (plain text)</label>
                <textarea
                    rows={3}
                    className="mt-1 w-full rounded border border-gray-300 bg-white px-2 py-1 text-sm"
                    value={form.data.system_prompt_addendum}
                    onChange={(e) => form.setData('system_prompt_addendum', e.target.value)}
                    maxLength={2500}
                />
            </div>
            <button
                type="submit"
                disabled={form.processing}
                className="mt-2 rounded bg-[#1E3A5F] px-3 py-1 text-xs text-white disabled:opacity-50"
            >
                Save agent
            </button>
        </form>
    );
}
