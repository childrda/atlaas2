<?php

namespace App\Http\Controllers\Teacher;

use App\Helpers\JoinCode;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\LearningSpace;
use App\Models\SpaceLibraryItem;
use App\Services\Safety\SessionModeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SpaceController extends Controller
{
    public function __construct(private SessionModeResolver $sessionModeResolver) {}

    public function index(): Response
    {
        $spaces = LearningSpace::forTeacherPortal(auth()->id())
            ->withCount('sessions')
            ->latest()
            ->paginate(20);

        return Inertia::render('Teacher/Spaces/Index', ['spaces' => $spaces]);
    }

    public function create(): Response
    {
        return Inertia::render('Teacher/Spaces/Create', [
            'classrooms' => Classroom::where('teacher_id', auth()->id())
                ->get(['id', 'name', 'grade_level', 'subject']),
            'studentModeOptions' => $this->studentModeOptions(request()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $allowed = $this->sessionModeResolver->allowedModesForTeacher($request->user());
        $data = $request->validate([
            'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:1000',
            'subject' => 'nullable|string|max:100',
            'grade_level' => 'nullable|string|max:20',
            'classroom_id' => [
                'nullable',
                'uuid',
                Rule::exists('classrooms', 'id')->where('teacher_id', $request->user()->id),
            ],
            'system_prompt' => 'nullable|string|max:4000',
            'goals' => 'nullable|array|max:5',
            'goals.*' => 'string|max:200',
            'atlaas_tone' => 'in:encouraging,socratic,direct,playful',
            'language' => 'string|max:10',
            'max_messages' => 'nullable|integer|min:5|max:500',
            'student_mode' => ['required', Rule::in($allowed)],
        ]);

        $space = LearningSpace::create([
            ...$data,
            'district_id' => $request->user()->district_id,
            'teacher_id' => $request->user()->id,
            'goals' => $data['goals'] ?? [],
        ]);

        return redirect()->route('teacher.spaces.show', $space)
            ->with('success', 'Space created. Configure it before sharing the join code.');
    }

    public function show(LearningSpace $space): Response
    {
        $this->authorize('view', $space);

        return Inertia::render('Teacher/Spaces/Show', [
            'space' => $space->load(['classroom', 'libraryItem']),
            'recentSessions' => $space->sessions()
                ->with('student:id,name')
                ->latest('started_at')
                ->limit(10)
                ->get(),
            'sessionCount' => $space->sessions()->count(),
        ]);
    }

    public function edit(LearningSpace $space): Response
    {
        $this->authorize('update', $space);

        return Inertia::render('Teacher/Spaces/Edit', [
            'space' => $space,
            'classrooms' => Classroom::where('teacher_id', auth()->id())
                ->get(['id', 'name']),
            'studentModeOptions' => $this->studentModeOptions(request()),
        ]);
    }

    public function update(Request $request, LearningSpace $space): RedirectResponse
    {
        $this->authorize('update', $space);

        $allowed = $this->sessionModeResolver->allowedModesForTeacher($request->user());
        $space->update($request->validate([
            'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:1000',
            'subject' => 'nullable|string|max:100',
            'grade_level' => 'nullable|string|max:20',
            'classroom_id' => [
                'nullable',
                'uuid',
                Rule::exists('classrooms', 'id')->where('teacher_id', $request->user()->id),
            ],
            'system_prompt' => 'nullable|string|max:4000',
            'goals' => 'nullable|array|max:5',
            'goals.*' => 'string|max:200',
            'atlaas_tone' => 'in:encouraging,socratic,direct,playful',
            'language' => 'string|max:10',
            'max_messages' => 'nullable|integer|min:5|max:500',
            'student_mode' => ['required', Rule::in($allowed)],
        ]));

        return back()->with('success', 'Space updated.');
    }

    public function publish(Request $request, LearningSpace $space): RedirectResponse
    {
        $this->authorize('update', $space);

        if ($request->boolean('unpublish')) {
            $space->update(['is_published' => false, 'is_public' => false]);
            SpaceLibraryItem::where('space_id', $space->id)->delete();
            $this->syncSearchIndex($space, false);

            return back()->with('success', 'Space unpublished and removed from Discover.');
        }

        $request->validate([
            'share_to_discover' => 'boolean',
            'grade_band' => 'nullable|string|max:50',
            'tags' => 'nullable|string|max:500',
            'library_description' => 'nullable|string|max:2000',
        ]);

        $wasPublished = $space->is_published;
        $share = $request->boolean('share_to_discover');
        $tagsRaw = $request->input('tags', '');
        $tags = array_values(array_filter(array_map('trim', explode(',', (string) $tagsRaw))));
        $tags = array_slice($tags, 0, 5);

        if (! $space->is_published) {
            $space->update([
                'is_published' => true,
                'is_public' => $share,
            ]);
        } else {
            $space->update(['is_public' => $share]);
        }

        if ($share) {
            SpaceLibraryItem::updateOrCreate(
                ['space_id' => $space->id],
                [
                    'title' => $space->title,
                    'description' => $request->input('library_description') ?: $space->description,
                    'subject' => $space->subject,
                    'grade_band' => $request->input('grade_band') ?: $space->grade_level,
                    'tags' => $tags ?: null,
                    'published_at' => now(),
                ]
            );
            $this->syncSearchIndex($space, true);
            $message = $wasPublished
                ? 'Discover listing updated.'
                : 'Space published and listed on Discover.';
        } else {
            SpaceLibraryItem::where('space_id', $space->id)->delete();
            $this->syncSearchIndex($space, false);
            $message = $wasPublished
                ? 'Removed from Discover. Students can still join with your join code.'
                : 'Space published (not shared to Discover).';
        }

        return back()->with('success', $message);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function studentModeOptions(Request $request): array
    {
        $allowed = $this->sessionModeResolver->allowedModesForTeacher($request->user());
        $labels = [
            'teacher_session' => 'Teacher session (this space / lesson)',
            'lms_help' => 'LMS help (enrolled courses)',
            'open_tutor' => 'Open tutor (K-12 academic)',
        ];

        return array_values(array_map(
            fn (string $m) => ['value' => $m, 'label' => $labels[$m] ?? $m],
            $allowed
        ));
    }

    private function syncSearchIndex(LearningSpace $space, bool $index): void
    {
        if (config('scout.driver') === 'null') {
            return;
        }
        try {
            if ($index) {
                $space->searchable();
            } else {
                $space->unsearchable();
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function duplicate(LearningSpace $space): RedirectResponse
    {
        $this->authorize('view', $space);

        $copy = $space->replicate(['join_code', 'is_public', 'classroom_id', 'is_published']);
        $copy->title = $space->title.' (Copy)';
        $copy->teacher_id = auth()->id();
        $copy->join_code = JoinCode::generate('learning_spaces');
        $copy->save();

        return redirect()->route('teacher.spaces.edit', $copy)
            ->with('success', 'Space duplicated. Edit it before publishing.');
    }

    public function destroy(LearningSpace $space): RedirectResponse
    {
        $this->authorize('delete', $space);
        $space->update(['is_archived' => true, 'is_public' => false]);
        SpaceLibraryItem::where('space_id', $space->id)->delete();
        $this->syncSearchIndex($space, false);

        return redirect()->route('teacher.spaces.index')
            ->with('success', 'Space archived.');
    }
}
