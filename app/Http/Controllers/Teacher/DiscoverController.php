<?php

namespace App\Http\Controllers\Teacher;

use App\Helpers\JoinCode;
use App\Http\Controllers\Controller;
use App\Models\LearningSpace;
use App\Models\SpaceLibraryItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DiscoverController extends Controller
{
    public function index(Request $request): Response
    {
        $qText = (string) $request->input('q', '');
        $subject = (string) $request->input('subject', '');
        $gradeBand = (string) $request->input('grade_band', '');
        $sort = (string) $request->input('sort', 'popular');
        if ($sort === 'rated') {
            $sort = 'rating';
        }

        $builder = SpaceLibraryItem::query()
            ->whereNotNull('published_at');

        if ($subject !== '') {
            $builder->where('subject', $subject);
        }
        if ($gradeBand !== '') {
            $builder->where('grade_band', $gradeBand);
        }

        if ($qText !== '') {
            $spaceIds = $this->discoverSearchSpaceIds($qText);
            if ($spaceIds->isEmpty()) {
                $builder->whereRaw('1 = 0');
            } else {
                $builder->whereIn('space_id', $spaceIds);
            }
        }

        match ($sort) {
            'newest' => $builder->orderByDesc('published_at'),
            'rating' => $builder->orderByDesc('rating')->orderByDesc('rating_count'),
            default => $builder->orderByDesc('download_count'),
        };

        $items = $builder
            ->with([
                'space' => fn ($rel) => $rel->withoutGlobalScope('district')->with([
                    'teacher' => fn ($t) => $t->with('school'),
                ]),
            ])
            ->paginate(12)
            ->withQueryString();

        $subjects = SpaceLibraryItem::query()
            ->whereNotNull('published_at')
            ->whereNotNull('subject')
            ->distinct()
            ->orderBy('subject')
            ->pluck('subject')
            ->values()
            ->all();

        return Inertia::render('Teacher/Discover/Index', [
            'items' => $items,
            'filters' => [
                'q' => $qText,
                'subject' => $subject,
                'grade_band' => $gradeBand,
                'sort' => $sort,
            ],
            'subjects' => $subjects,
            'gradeBands' => ['K-2', '3-5', '6-8', '9-12'],
        ]);
    }

    /** @return Collection<int, string> */
    private function discoverSearchSpaceIds(string $qText): Collection
    {
        if (config('scout.driver') === 'meilisearch') {
            try {
                return LearningSpace::search($qText)
                    ->where('is_public', true)
                    ->where('is_published', true)
                    ->where('is_archived', false)
                    ->take(500)
                    ->keys();
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $qText);
        $like = '%'.$escaped.'%';

        return SpaceLibraryItem::query()
            ->whereNotNull('published_at')
            ->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('subject', 'like', $like);
            })
            ->pluck('space_id')
            ->unique()
            ->values();
    }

    public function import(Request $request, SpaceLibraryItem $libraryItem): RedirectResponse
    {
        if ($libraryItem->published_at === null) {
            abort(404);
        }

        $original = LearningSpace::withoutGlobalScope('district')
            ->whereKey($libraryItem->space_id)
            ->firstOrFail();

        if ($original->teacher_id === $request->user()->id) {
            return back()->with('error', 'This is already your space.');
        }

        $copy = $original->replicate([
            'join_code', 'is_public', 'classroom_id', 'is_published',
        ]);
        $copy->title = $original->title.' (Imported)';
        $copy->teacher_id = $request->user()->id;
        $copy->district_id = $request->user()->district_id;
        $copy->classroom_id = null;
        $copy->join_code = JoinCode::generate('learning_spaces');
        $copy->is_published = false;
        $copy->is_public = false;

        DB::transaction(function () use ($copy, $libraryItem) {
            $copy->save();
            $libraryItem->increment('download_count');
        });

        return redirect()->route('teacher.spaces.edit', $copy)
            ->with('success', 'Space imported to your account. Review and publish when ready.');
    }

    public function approve(Request $request, SpaceLibraryItem $libraryItem): RedirectResponse
    {
        if ($libraryItem->published_at === null) {
            abort(404);
        }

        $user = $request->user();
        abort_unless($user->hasRole('district_admin'), 403);

        $space = LearningSpace::withoutGlobalScope('district')
            ->whereKey($libraryItem->space_id)
            ->firstOrFail();

        abort_unless($space->district_id === $user->district_id, 403);

        $libraryItem->update(['district_approved' => true]);

        return back()->with('success', 'Listing marked district-approved.');
    }

    public function rate(Request $request, SpaceLibraryItem $libraryItem): JsonResponse
    {
        if ($libraryItem->published_at === null) {
            abort(404);
        }

        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
        ]);
        $new = (float) $data['rating'];
        $count = (int) $libraryItem->rating_count;
        $avg = (float) $libraryItem->rating;

        if ($count === 0) {
            $libraryItem->rating = $new;
        } else {
            $libraryItem->rating = (($avg * $count) + $new) / ($count + 1);
        }
        $libraryItem->rating_count = $count + 1;
        $libraryItem->save();
        $libraryItem->refresh();

        return response()->json([
            'rating' => round((float) $libraryItem->rating, 2),
            'rating_count' => $libraryItem->rating_count,
        ]);
    }
}
