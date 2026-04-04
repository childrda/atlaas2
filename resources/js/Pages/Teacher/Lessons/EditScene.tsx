import TeacherLayout from '@/Layouts/TeacherLayout';
import { Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';

interface ScenePayload {
    id: string;
    sequence_order: number;
    scene_type: string;
    title: string;
    learning_objective: string | null;
    estimated_duration_seconds: number;
    content: Record<string, unknown> | null;
    generation_status: string;
}

export default function EditScene() {
    const { lesson, scene } = usePage().props as {
        lesson: { id: string; title: string };
        scene: ScenePayload;
    };

    const [contentJson, setContentJson] = useState(() =>
        JSON.stringify(scene.content ?? {}, null, 2),
    );
    const [jsonError, setJsonError] = useState<string | null>(null);

    const form = useForm({
        title: scene.title,
        learning_objective: scene.learning_objective ?? '',
        estimated_duration_seconds: scene.estimated_duration_seconds,
        content: scene.content ?? {},
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setJsonError(null);
        let parsed: Record<string, unknown>;
        try {
            parsed = JSON.parse(contentJson) as Record<string, unknown>;
            if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
                setJsonError('Content must be a JSON object.');
                return;
            }
        } catch {
            setJsonError('Invalid JSON.');
            return;
        }

        form.setData('content', parsed);
        form.patch(`/teach/lessons/${lesson.id}/scenes/${scene.id}`);
    };

    const prettyType = useMemo(() => scene.scene_type.replace(/_/g, ' '), [scene.scene_type]);

    return (
        <TeacherLayout>
            <div className="mx-auto max-w-3xl p-8">
                <Link href={`/teach/lessons/${lesson.id}`} className="text-sm text-[#1E3A5F] hover:underline">
                    ← {lesson.title}
                </Link>

                <h1 className="mt-4 text-xl font-medium text-gray-900">Edit scene</h1>
                <p className="mt-1 text-sm text-gray-500">
                    {prettyType} · order {scene.sequence_order + 1}
                </p>

                <form onSubmit={submit} className="mt-8 space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Title</label>
                        <input
                            className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                            value={form.data.title}
                            onChange={(e) => form.setData('title', e.target.value)}
                        />
                        {form.errors.title && <p className="mt-1 text-sm text-red-600">{form.errors.title}</p>}
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700">Learning objective</label>
                        <input
                            className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                            value={form.data.learning_objective}
                            onChange={(e) => form.setData('learning_objective', e.target.value)}
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700">Duration (seconds)</label>
                        <input
                            type="number"
                            min={30}
                            max={7200}
                            className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                            value={form.data.estimated_duration_seconds}
                            onChange={(e) =>
                                form.setData('estimated_duration_seconds', Number(e.target.value) || 120)
                            }
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700">Content (JSON)</label>
                        <textarea
                            rows={16}
                            className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-xs"
                            value={contentJson}
                            onChange={(e) => setContentJson(e.target.value)}
                            spellCheck={false}
                        />
                        {jsonError && <p className="mt-1 text-sm text-red-600">{jsonError}</p>}
                    </div>

                    <div className="flex gap-2">
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-lg bg-[#1E3A5F] px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                        >
                            Save scene
                        </button>
                        <Link
                            href={`/teach/lessons/${lesson.id}`}
                            className="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700"
                        >
                            Cancel
                        </Link>
                    </div>
                </form>
            </div>
        </TeacherLayout>
    );
}
