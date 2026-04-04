import TeacherLayout from '@/Layouts/TeacherLayout';
import type { LearningSpace, SpaceLibraryItem, User } from '@/types/models';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

type DiscoverRow = SpaceLibraryItem & {
    space: (LearningSpace & { teacher?: { id: string; name: string; school?: { name: string } | null } }) | null;
};

const DISCOVER_PATH = '/teach/discover';
const SEARCH_DEBOUNCE_MS = 350;

function StarRating({ rating, count }: { rating: number; count: number }) {
    const rounded = Math.min(5, Math.max(0, Math.round(Number(rating))));
    return (
        <div className="flex items-center gap-0.5" aria-label={`Rating ${Number(rating).toFixed(1)} out of 5, ${count} ratings`}>
            {[1, 2, 3, 4, 5].map((star) => (
                <span key={star} className={star <= rounded ? 'text-amber-400' : 'text-gray-200'} aria-hidden>
                    ★
                </span>
            ))}
            <span className="ml-1 text-xs text-gray-400">({count})</span>
        </div>
    );
}

function visitDiscover(
    params: { q: string; subject: string; grade_band: string; sort: string },
    opts?: { preserveScroll?: boolean },
) {
    router.get(DISCOVER_PATH, params, {
        preserveState: true,
        preserveScroll: opts?.preserveScroll ?? true,
        replace: true,
        only: ['items', 'filters', 'subjects', 'gradeBands'],
    });
}

export default function DiscoverIndex() {
    const page = usePage();
    const { items, filters, subjects, gradeBands, csrf_token, auth, flash } = page.props as {
        items: Paginated<DiscoverRow>;
        filters: { q: string; subject: string; grade_band: string; sort: string };
        subjects: string[];
        gradeBands: string[];
        csrf_token: string;
        auth: { user: User };
        flash: { success?: string; error?: string };
    };

    const [qInput, setQInput] = useState(filters.q);
    const filtersRef = useRef(filters);
    filtersRef.current = filters;

    useEffect(() => {
        setQInput(filters.q);
    }, [filters.q]);

    useEffect(() => {
        const t = window.setTimeout(() => {
            const f = filtersRef.current;
            if (qInput === f.q) {
                return;
            }
            visitDiscover({
                q: qInput,
                subject: f.subject,
                grade_band: f.grade_band,
                sort: f.sort,
            });
        }, SEARCH_DEBOUNCE_MS);
        return () => window.clearTimeout(t);
    }, [qInput]);

    const [ratings, setRatings] = useState<Record<string, { rating: number; rating_count: number }>>({});

    async function submitRating(itemId: string, rating: number) {
        const res = await fetch(`${DISCOVER_PATH}/${itemId}/rate`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf_token,
            },
            body: JSON.stringify({ rating }),
        });
        if (!res.ok) return;
        const data = (await res.json()) as { rating: number; rating_count: number };
        setRatings((prev) => ({ ...prev, [itemId]: data }));
    }

    function applyAllFilters() {
        const f = filtersRef.current;
        visitDiscover({
            q: qInput,
            subject: f.subject,
            grade_band: f.grade_band,
            sort: f.sort,
        });
    }

    return (
        <TeacherLayout>
            <div className="p-8">
                <Link href="/teach/spaces" className="text-sm text-[#1E3A5F] hover:underline">
                    ← Spaces
                </Link>
                <h1 className="mt-4 text-2xl font-medium text-gray-900">Discover</h1>
                <p className="mt-1 text-sm text-gray-500">
                    Browse spaces shared by other teachers. Add a copy to your spaces and customize it.
                </p>

                {flash.success && (
                    <p className="mt-4 rounded-md bg-green-50 px-3 py-2 text-sm text-green-800">{flash.success}</p>
                )}
                {flash.error && (
                    <p className="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">{flash.error}</p>
                )}

                <div className="mt-6 flex flex-col gap-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm lg:flex-row lg:flex-wrap lg:items-end">
                    <div className="min-w-[12rem] flex-1">
                        <label htmlFor="discover-q" className="block text-xs font-medium text-gray-600">
                            Search
                        </label>
                        <input
                            id="discover-q"
                            type="search"
                            value={qInput}
                            onChange={(e) => setQInput(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    applyAllFilters();
                                }
                            }}
                            className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                            placeholder="Title, description, subject…"
                        />
                        <p className="mt-1 text-[11px] text-gray-400">Results update shortly after you stop typing.</p>
                    </div>
                    <div>
                        <label htmlFor="discover-subject" className="block text-xs font-medium text-gray-600">
                            Subject
                        </label>
                        <select
                            id="discover-subject"
                            value={filters.subject}
                            onChange={(e) =>
                                visitDiscover({
                                    q: qInput,
                                    subject: e.target.value,
                                    grade_band: filters.grade_band,
                                    sort: filters.sort,
                                })
                            }
                            className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm lg:w-44"
                        >
                            <option value="">All</option>
                            {subjects.map((s) => (
                                <option key={s} value={s}>
                                    {s}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="discover-grade" className="block text-xs font-medium text-gray-600">
                            Grade band
                        </label>
                        <select
                            id="discover-grade"
                            value={filters.grade_band}
                            onChange={(e) =>
                                visitDiscover({
                                    q: qInput,
                                    subject: filters.subject,
                                    grade_band: e.target.value,
                                    sort: filters.sort,
                                })
                            }
                            className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm lg:w-36"
                        >
                            <option value="">All</option>
                            {gradeBands.map((g) => (
                                <option key={g} value={g}>
                                    {g}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="discover-sort" className="block text-xs font-medium text-gray-600">
                            Sort
                        </label>
                        <select
                            id="discover-sort"
                            value={filters.sort === 'rated' ? 'rating' : filters.sort}
                            onChange={(e) =>
                                visitDiscover({
                                    q: qInput,
                                    subject: filters.subject,
                                    grade_band: filters.grade_band,
                                    sort: e.target.value,
                                })
                            }
                            className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm lg:w-44"
                        >
                            <option value="popular">Most downloaded</option>
                            <option value="newest">Newest</option>
                            <option value="rating">Highest rated</option>
                        </select>
                    </div>
                    <button
                        type="button"
                        onClick={() => applyAllFilters()}
                        className="rounded-md px-4 py-2 text-sm font-medium text-white"
                        style={{ backgroundColor: '#1E3A5F' }}
                    >
                        Apply
                    </button>
                </div>

                <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {items.data.map((item) => {
                        const schoolName = item.space?.teacher?.school?.name ?? '—';
                        const mine = item.space?.teacher?.id === auth.user.id;
                        const r = ratings[item.id];
                        const rating = r?.rating ?? item.rating;
                        const ratingCount = r?.rating_count ?? item.rating_count;
                        const canDistrictApprove =
                            auth.user.roles.includes('district_admin') &&
                            item.space?.district_id === auth.user.district.id &&
                            !item.district_approved;

                        return (
                            <article
                                key={item.id}
                                className="flex flex-col rounded-lg border border-gray-200 bg-white p-5 shadow-sm"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <h2 className="font-medium text-gray-900">{item.title}</h2>
                                    {item.district_approved && (
                                        <span className="shrink-0 rounded bg-[#1E3A5F]/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-[#1E3A5F]">
                                            District approved
                                        </span>
                                    )}
                                </div>
                                <p className="mt-1 text-xs text-gray-500">{schoolName}</p>
                                <p className="mt-2 max-h-16 overflow-hidden text-sm text-gray-600">
                                    {item.description || 'No description.'}
                                </p>
                                <p className="mt-2 text-xs text-gray-500">
                                    {[item.subject, item.grade_band].filter(Boolean).join(' · ') || '—'}
                                </p>
                                {(item.tags?.length ?? 0) > 0 && (
                                    <div className="mt-2 flex flex-wrap gap-1">
                                        {(item.tags ?? []).map((t) => (
                                            <span
                                                key={t}
                                                className="rounded bg-gray-100 px-2 py-0.5 text-[11px] text-gray-700"
                                            >
                                                {t}
                                            </span>
                                        ))}
                                    </div>
                                )}
                                <div className="mt-3">
                                    <StarRating rating={Number(rating)} count={ratingCount} />
                                </div>
                                <div className="mt-2 flex flex-wrap gap-1">
                                    {[1, 2, 3, 4, 5].map((n) => (
                                        <button
                                            key={n}
                                            type="button"
                                            onClick={() => void submitRating(item.id, n)}
                                            className="rounded border border-gray-200 px-2 py-0.5 text-xs text-gray-600 hover:bg-amber-50"
                                            title={`Rate ${n}`}
                                        >
                                            {n}★
                                        </button>
                                    ))}
                                </div>
                                <p className="mt-2 text-xs text-gray-500">{item.download_count} imports</p>
                                <div className="mt-4 flex flex-wrap gap-2">
                                    {!mine ? (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                if (confirm('Add a copy of this space to your account?')) {
                                                    router.post(`${DISCOVER_PATH}/${item.id}/import`);
                                                }
                                            }}
                                            className="rounded-md px-3 py-1.5 text-sm font-medium text-white"
                                            style={{ backgroundColor: '#F5A623' }}
                                        >
                                            Add to my spaces
                                        </button>
                                    ) : (
                                        <span className="text-xs text-gray-500">Your space</span>
                                    )}
                                    {canDistrictApprove && (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                if (confirm('Mark this listing as district-approved for Discover?')) {
                                                    router.post(`${DISCOVER_PATH}/${item.id}/approve`);
                                                }
                                            }}
                                            className="rounded-md border border-[#1E3A5F] px-3 py-1.5 text-sm text-[#1E3A5F] hover:bg-[#1E3A5F]/5"
                                        >
                                            District approve
                                        </button>
                                    )}
                                </div>
                            </article>
                        );
                    })}
                </div>

                {items.data.length === 0 && (
                    <p className="mt-8 text-sm text-gray-500">No spaces match your filters.</p>
                )}

                {items.links.length > 3 && (
                    <div className="mt-8 flex flex-wrap gap-2">
                        {items.links.map((l, i) =>
                            l.url ? (
                                <Link
                                    key={i}
                                    href={l.url}
                                    className={`rounded-md border px-3 py-1.5 text-sm ${
                                        l.active
                                            ? 'border-[#1E3A5F] bg-[#1E3A5F] text-white'
                                            : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'
                                    }`}
                                    preserveScroll
                                    dangerouslySetInnerHTML={{ __html: l.label }}
                                />
                            ) : (
                                <span
                                    key={i}
                                    className="rounded-md border border-gray-100 px-3 py-1.5 text-sm text-gray-400"
                                    dangerouslySetInnerHTML={{ __html: l.label }}
                                />
                            ),
                        )}
                    </div>
                )}
            </div>
        </TeacherLayout>
    );
}
