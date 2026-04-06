import TeacherLayout from '@/Layouts/TeacherLayout';
import { studentModeLabel } from '@/lib/studentMode';
import type { LearningSpace, TeacherSpaceSessionRow } from '@/types/models';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function SpacesShow() {
    const { space, recentSessions, sessionCount, flash } = usePage().props as {
        space: LearningSpace;
        recentSessions: TeacherSpaceSessionRow[];
        sessionCount: number;
        flash: { success?: string; error?: string };
    };
    const [copied, setCopied] = useState(false);
    const [publishOpen, setPublishOpen] = useState(false);
    const [shareToDiscover, setShareToDiscover] = useState(false);
    const [gradeBand, setGradeBand] = useState('');
    const [tags, setTags] = useState('');
    const [libraryDescription, setLibraryDescription] = useState('');

    function openPublishModal() {
        const lib = space.library_item;
        setShareToDiscover(space.is_public);
        setGradeBand(lib?.grade_band ?? space.grade_level ?? '');
        setTags((lib?.tags ?? []).join(', '));
        setLibraryDescription(lib?.description ?? space.description ?? '');
        setPublishOpen(true);
    }

    function submitPublish() {
        router.post(`/teach/spaces/${space.id}/publish`, {
            share_to_discover: shareToDiscover,
            grade_band: gradeBand || null,
            tags,
            library_description: libraryDescription || null,
        });
        setPublishOpen(false);
    }

    function copyCode() {
        void navigator.clipboard.writeText(space.join_code);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }

    return (
        <TeacherLayout>
            <div className="p-8">
                <Link href="/teach/spaces" className="text-sm text-[#1E3A5F] hover:underline">
                    ← Spaces
                </Link>

                {flash.error && (
                    <p className="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">{flash.error}</p>
                )}
                {flash.success && (
                    <p className="mt-4 rounded-md bg-green-50 px-3 py-2 text-sm text-green-800">{flash.success}</p>
                )}

                <div className="mt-4 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-medium text-gray-900">{space.title}</h1>
                        <p className="mt-1 text-sm text-gray-500">
                            {[space.subject, space.grade_level].filter(Boolean).join(' · ') || '—'}
                        </p>
                        <p className="mt-2 max-w-xl text-xs text-gray-600">
                            <span className="font-medium text-gray-800">Student mode:</span>{' '}
                            {studentModeLabel(space.student_mode)}
                            <span className="mx-2 text-gray-300">|</span>
                            <span className="font-medium text-gray-800">Multi-agent classroom:</span>{' '}
                            {space.multi_agent_classroom_enabled === false ? 'Off' : 'On'}
                            <span className="mx-2 text-gray-300">|</span>
                            <Link href={`/teach/spaces/${space.id}/edit`} className="text-[#1E3A5F] underline">
                                Change in Edit
                            </Link>
                        </p>
                        {space.is_public && (
                            <p className="mt-2 text-xs font-medium text-[#1E3A5F]">Listed on Discover</p>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={copyCode}
                            className="rounded-md border border-gray-300 bg-white px-3 py-1.5 font-mono text-sm"
                        >
                            {space.join_code} {copied ? '✓' : 'Copy'}
                        </button>
                        {!space.is_published ? (
                            <button
                                type="button"
                                onClick={openPublishModal}
                                className="rounded-md px-3 py-1.5 text-sm text-white"
                                style={{ backgroundColor: '#F5A623' }}
                            >
                                Publish
                            </button>
                        ) : (
                            <>
                                <button
                                    type="button"
                                    onClick={() => {
                                        if (confirm('Unpublish this space? Students will no longer be able to start new sessions.')) {
                                            router.post(`/teach/spaces/${space.id}/publish`, { unpublish: true });
                                        }
                                    }}
                                    className="rounded-md bg-gray-600 px-3 py-1.5 text-sm text-white"
                                >
                                    Unpublish
                                </button>
                                <button
                                    type="button"
                                    onClick={openPublishModal}
                                    className="rounded-md border border-gray-300 px-3 py-1.5 text-sm"
                                >
                                    Discover settings
                                </button>
                            </>
                        )}
                        <Link
                            href={`/teach/spaces/${space.id}/edit`}
                            className="rounded-md border border-gray-300 px-3 py-1.5 text-sm"
                        >
                            Edit
                        </Link>
                        <button
                            type="button"
                            onClick={() => router.post(`/teach/spaces/${space.id}/duplicate`)}
                            className="rounded-md border border-gray-300 px-3 py-1.5 text-sm"
                        >
                            Duplicate
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                if (confirm('Archive this space?')) {
                                    router.delete(`/teach/spaces/${space.id}`);
                                }
                            }}
                            className="rounded-md border border-red-200 px-3 py-1.5 text-sm text-red-700"
                        >
                            Archive
                        </button>
                    </div>
                </div>

                {space.description && (
                    <p className="mt-4 text-sm text-gray-600">{space.description}</p>
                )}

                <div className="mt-6">
                    <h2 className="text-sm font-semibold text-gray-900">Goals</h2>
                    <ul className="mt-2 list-inside list-disc text-sm text-gray-700">
                        {(space.goals ?? []).map((g, i) => (
                            <li key={i}>{g}</li>
                        ))}
                    </ul>
                    {(space.goals ?? []).length === 0 && (
                        <p className="text-sm text-gray-500">No goals set.</p>
                    )}
                </div>

                <div className="mt-8">
                    <h2 className="text-sm font-semibold text-gray-900">
                        Recent sessions ({sessionCount} total)
                    </h2>
                    <table className="mt-3 w-full text-left text-sm">
                        <thead>
                            <tr className="border-b text-gray-500">
                                <th className="py-2 pr-4">Student</th>
                                <th className="py-2 pr-4">Started</th>
                                <th className="py-2 pr-4">Status</th>
                                <th className="py-2">Messages</th>
                            </tr>
                        </thead>
                        <tbody>
                            {recentSessions.map((s) => (
                                <tr key={s.id} className="border-b border-gray-100">
                                    <td className="py-2 pr-4">{s.student?.name ?? '—'}</td>
                                    <td className="py-2 pr-4 text-gray-600">
                                        {new Date(s.started_at).toLocaleString()}
                                    </td>
                                    <td className="py-2 pr-4">{s.status}</td>
                                    <td className="py-2">{s.message_count}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {recentSessions.length === 0 && (
                        <p className="mt-2 text-sm text-gray-500">No sessions yet.</p>
                    )}
                </div>

                {publishOpen && (
                    <div
                        className="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="publish-modal-title"
                    >
                        <div className="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-lg bg-white p-6 shadow-xl">
                            <h2 id="publish-modal-title" className="text-lg font-medium text-gray-900">
                                {space.is_published ? 'Discover settings' : 'Publish space'}
                            </h2>
                            <p className="mt-1 text-sm text-gray-500">
                                Students can join with your code when the space is published. Optionally list it on Discover
                                for other teachers to find and import.
                            </p>

                            <label className="mt-4 flex cursor-pointer items-start gap-2">
                                <input
                                    type="checkbox"
                                    checked={shareToDiscover}
                                    onChange={(e) => setShareToDiscover(e.target.checked)}
                                    className="mt-1"
                                />
                                <span className="text-sm text-gray-800">Share to Discover (public catalog)</span>
                            </label>

                            {shareToDiscover && (
                                <>
                                    <div className="mt-4">
                                        <label htmlFor="pub-grade" className="block text-xs font-medium text-gray-600">
                                            Grade band (shown on Discover)
                                        </label>
                                        <select
                                            id="pub-grade"
                                            value={gradeBand}
                                            onChange={(e) => setGradeBand(e.target.value)}
                                            className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                        >
                                            <option value="">Same as space ({space.grade_level || '—'})</option>
                                            <option value="K-2">K-2</option>
                                            <option value="3-5">3-5</option>
                                            <option value="6-8">6-8</option>
                                            <option value="9-12">9-12</option>
                                        </select>
                                    </div>
                                    <div className="mt-4">
                                        <label htmlFor="pub-tags" className="block text-xs font-medium text-gray-600">
                                            Tags (comma-separated, up to 5)
                                        </label>
                                        <input
                                            id="pub-tags"
                                            type="text"
                                            value={tags}
                                            onChange={(e) => setTags(e.target.value)}
                                            className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                            placeholder="e.g. fractions, group work"
                                        />
                                    </div>
                                    <div className="mt-4">
                                        <label htmlFor="pub-desc" className="block text-xs font-medium text-gray-600">
                                            Discover description
                                        </label>
                                        <textarea
                                            id="pub-desc"
                                            value={libraryDescription}
                                            onChange={(e) => setLibraryDescription(e.target.value)}
                                            rows={4}
                                            className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                        />
                                    </div>
                                </>
                            )}

                            <div className="mt-6 flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => setPublishOpen(false)}
                                    className="rounded-md border border-gray-300 px-4 py-2 text-sm"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    onClick={submitPublish}
                                    className="rounded-md px-4 py-2 text-sm font-medium text-white"
                                    style={{ backgroundColor: '#1E3A5F' }}
                                >
                                    Save
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </TeacherLayout>
    );
}
