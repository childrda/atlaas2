<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateLessonOutlineJob;
use App\Models\ClassroomLesson;
use App\Models\LearningSpace;
use App\Models\LessonAgent;
use App\Services\Classroom\AgentArchetypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LessonController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ClassroomLesson::class);

        $lessons = ClassroomLesson::query()
            ->where('teacher_id', $request->user()->id)
            ->with(['scenes' => fn ($q) => $q->select('id', 'lesson_id', 'sequence_order', 'scene_type', 'title', 'generation_status')])
            ->latest()
            ->paginate(20);

        return Inertia::render('Teacher/Lessons/Index', [
            'lessons' => $lessons,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', ClassroomLesson::class);

        return Inertia::render('Teacher/Lessons/Create', [
            'archetypes' => AgentArchetypes::ARCHETYPES,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', ClassroomLesson::class);

        $data = $request->validate([
            'title' => 'required|string|max:200',
            'source_type' => 'required|in:topic,pdf,standard',
            'source_text' => 'required_if:source_type,topic|nullable|string|max:5000',
            'subject' => 'nullable|string|max:100',
            'grade_level' => 'nullable|string|max:10',
            'language' => 'nullable|in:en,es,fr',
            'agent_mode' => 'in:default,custom',
            'agents' => 'nullable|array',
            'agents.*.archetype' => 'in:teacher,assistant,curious,notetaker,skeptic,enthusiast',
            'agents.*.display_name' => 'nullable|string|max:50',
        ]);

        $lesson = ClassroomLesson::create([
            'district_id' => $request->user()->district_id,
            'teacher_id' => $request->user()->id,
            'title' => $data['title'],
            'source_type' => $data['source_type'],
            'source_text' => $data['source_text'] ?? null,
            'subject' => $data['subject'] ?? null,
            'grade_level' => $data['grade_level'] ?? null,
            'language' => $data['language'] ?? 'en',
            'agent_mode' => $data['agent_mode'] ?? 'default',
            'generation_status' => 'pending',
        ]);

        $archetypes = $data['agents'] ?? array_map(
            fn ($a) => ['archetype' => $a],
            AgentArchetypes::defaultAgentsForLesson($data['grade_level'] ?? '')
        );

        foreach ($archetypes as $i => $agentData) {
            $arche = AgentArchetypes::get($agentData['archetype']);
            $role = $arche['role'];
            LessonAgent::create([
                'lesson_id' => $lesson->id,
                'district_id' => $lesson->district_id,
                'role' => $role,
                'display_name' => $agentData['display_name'] ?? $arche['display_name'],
                'archetype' => $agentData['archetype'],
                'avatar_emoji' => $arche['avatar_emoji'],
                'color_hex' => $arche['color_hex'],
                'persona_text' => $arche['persona_text'],
                'allowed_actions' => AgentArchetypes::ROLE_ACTIONS[$role],
                'priority' => $arche['priority'],
                'sequence_order' => $i,
                'is_active' => true,
            ]);
        }

        GenerateLessonOutlineJob::dispatch($lesson->id)->onQueue('default');

        return redirect()->route('teacher.lessons.show', $lesson)->with('success', 'Lesson generation started.');
    }

    public function update(Request $request, ClassroomLesson $lesson): RedirectResponse
    {
        $this->authorize('update', $lesson);

        $data = $request->validate([
            'title' => 'sometimes|string|max:200',
            'subject' => 'nullable|string|max:100',
            'grade_level' => 'nullable|string|max:10',
            'language' => 'nullable|in:en,es,fr',
            'status' => 'sometimes|in:draft,published,archived',
        ]);

        $lesson->update($data);

        return back()->with('success', 'Lesson updated.');
    }

    public function destroy(ClassroomLesson $lesson): RedirectResponse
    {
        $this->authorize('delete', $lesson);
        $lesson->delete();

        return redirect()
            ->route('teacher.lessons.index')
            ->with('success', 'Lesson deleted.');
    }

    public function export(Request $request, ClassroomLesson $lesson): StreamedResponse
    {
        $this->authorize('view', $lesson);

        $format = $request->query('format', 'html');
        if ($format !== 'html') {
            throw new NotFoundHttpException('Unsupported export format.');
        }

        $lesson->load([
            'scenes' => fn ($q) => $q->orderBy('sequence_order'),
            'agents' => fn ($q) => $q->orderBy('sequence_order'),
        ]);

        $filename = Str::slug($lesson->title).'-lesson.html';

        return response()->streamDownload(function () use ($lesson): void {
            echo view('exports.classroom-lesson', ['lesson' => $lesson])->render();
        }, $filename, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function show(Request $request, ClassroomLesson $lesson): Response
    {
        $this->authorize('view', $lesson);
        $lesson->load(['scenes', 'agents' => fn ($q) => $q->orderBy('sequence_order')]);

        $teacherSpaces = LearningSpace::forTeacherPortal($request->user()->id)
            ->orderBy('title')
            ->get(['id', 'title']);

        return Inertia::render('Teacher/Lessons/Show', [
            'lesson' => $lesson,
            'teacherSpaces' => $teacherSpaces,
        ]);
    }

    public function status(ClassroomLesson $lesson)
    {
        $this->authorize('view', $lesson);
        $scenes = $lesson->scenes()->select(
            'id',
            'scene_type',
            'title',
            'generation_status',
            'sequence_order',
            'generation_error',
        )->get();

        return response()->json([
            'generation_status' => $lesson->generation_status,
            'generation_progress' => $lesson->generation_progress,
            'scenes' => $scenes,
        ]);
    }

    public function regenerate(ClassroomLesson $lesson): RedirectResponse
    {
        $this->authorize('update', $lesson);

        if ($lesson->status === 'published') {
            return back()->with('error', 'Unpublish this lesson before regenerating content.');
        }

        if ($lesson->generation_status !== 'failed') {
            return back()->with('error', 'Regeneration is only available when generation has failed.');
        }

        DB::transaction(function () use ($lesson): void {
            $lesson->scenes()->delete();
            $lesson->update([
                'outline' => null,
                'generation_status' => 'pending',
                'generation_progress' => ['message' => 'Queued for regeneration…'],
            ]);
        });

        GenerateLessonOutlineJob::dispatch($lesson->id)->onQueue('default');

        return back()->with('success', 'Lesson regeneration queued. Keep a queue worker running on the default queue.');
    }

    public function publish(Request $request, ClassroomLesson $lesson)
    {
        $this->authorize('update', $lesson);

        if ($lesson->scenes()->count() < 1) {
            return back()->with('error', 'Add at least one scene before publishing this lesson.');
        }

        $data = $request->validate([
            'space_id' => 'required|uuid',
        ]);

        $lesson->update([
            'space_id' => $data['space_id'],
            'status' => 'published',
            'published_at' => now(),
        ]);

        return back()->with('success', 'Lesson published to space.');
    }
}
