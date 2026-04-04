import TeacherLayout from '@/Layouts/TeacherLayout';
import type { LearningSpace } from '@/types/models';
import { Link, router, usePage } from '@inertiajs/react';

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

export default function SpacesIndex() {
    const { spaces } = usePage().props as { spaces: Paginated<LearningSpace> };

    return (
        <TeacherLayout>
            <div className="p-8">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-medium text-gray-900">Learning spaces</h1>
                    <Link
                        href="/teach/spaces/create"
                        className="rounded-md px-4 py-2 text-sm font-medium text-white"
                        style={{ backgroundColor: '#1E3A5F' }}
                    >
                        New space
                    </Link>
                </div>

                <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {spaces.data.map((s) => (
                        <Link
                            key={s.id}
                            href={`/teach/spaces/${s.id}`}
                            className="rounded-lg border border-gray-200 bg-white p-5 shadow-sm transition hover:border-[#1E3A5F]/30"
                        >
                            <div className="flex items-start justify-between gap-2">
                                <h3 className="font-medium text-gray-900">{s.title}</h3>
                                {s.is_published ? (
                                    <span className="shrink-0 rounded bg-green-100 px-2 py-0.5 text-xs text-green-800">
                                        Live
                                    </span>
                                ) : (
                                    <span className="shrink-0 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                                        Draft
                                    </span>
                                )}
                            </div>
                            <p className="mt-1 text-sm text-gray-500">
                                {[s.subject, s.grade_level].filter(Boolean).join(' · ') || '—'}
                            </p>
                            <p className="mt-2 text-xs text-gray-400">
                                {s.sessions_count ?? 0} sessions
                            </p>
                            <span
                                className="mt-2 inline-block rounded-full px-2 py-0.5 font-mono text-xs"
                                style={{ backgroundColor: '#F5A62322', color: '#1E3A5F' }}
                            >
                                {s.join_code}
                            </span>
                        </Link>
                    ))}
                </div>

                {spaces.data.length === 0 && (
                    <p className="mt-8 text-center text-sm text-gray-500">No spaces yet.</p>
                )}

                {spaces.links.length > 3 && (
                    <div className="mt-8 flex flex-wrap gap-1">
                        {spaces.links.map((l, i) => (
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
