import TeacherLayout from '@/Layouts/TeacherLayout';
import { Link, router, usePage } from '@inertiajs/react';

interface LessonRow {
    id: string;
    title: string;
    generation_status: string;
    status: string;
    scenes?: { generation_status: string }[];
}

interface Paginated {
    data: LessonRow[];
    links: { url: string | null; label: string; active: boolean }[];
}

export default function LessonsIndex() {
    const { lessons } = usePage().props as { lessons: Paginated };

    return (
        <TeacherLayout>
            <div className="p-8">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-medium text-gray-900">Classroom lessons</h1>
                    <Link
                        href="/teach/lessons/create"
                        className="rounded-md px-4 py-2 text-sm font-medium text-white"
                        style={{ backgroundColor: '#1E3A5F' }}
                    >
                        New lesson
                    </Link>
                </div>

                <ul className="mt-8 divide-y divide-gray-200 rounded-lg border border-gray-200 bg-white">
                    {lessons.data.map((l) => (
                        <li key={l.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-gray-50">
                            <Link href={`/teach/lessons/${l.id}`} className="min-w-0 flex-1 font-medium text-gray-900">
                                {l.title}
                            </Link>
                            <div className="flex items-center gap-3">
                                <span className="text-xs text-gray-500">
                                    {l.generation_status} · {l.status}
                                </span>
                                <button
                                    type="button"
                                    className="text-xs text-red-600 hover:underline"
                                    onClick={() => {
                                        if (confirm(`Delete “${l.title}”?`)) {
                                            router.delete(`/teach/lessons/${l.id}`);
                                        }
                                    }}
                                >
                                    Delete
                                </button>
                            </div>
                        </li>
                    ))}
                </ul>

                {lessons.data.length === 0 && (
                    <p className="mt-8 text-sm text-gray-500">No lessons yet. Create one to get started.</p>
                )}

                <div className="mt-6 flex justify-center gap-2">
                    {lessons.links.map((link, i) =>
                        link.url ? (
                            <Link
                                key={i}
                                href={link.url}
                                className={`rounded px-3 py-1 text-sm ${link.active ? 'bg-[#1E3A5F] text-white' : 'border border-gray-200 text-gray-600'}`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ) : null
                    )}
                </div>
            </div>
        </TeacherLayout>
    );
}
