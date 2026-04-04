<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\ClassroomLesson;
use App\Models\LessonScene;
use App\Services\Classroom\SceneContentSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LessonSceneController extends Controller
{
    public function edit(ClassroomLesson $lesson, LessonScene $scene): Response
    {
        $this->authorize('update', $lesson);
        abort_unless($scene->lesson_id === $lesson->id, 404);

        return Inertia::render('Teacher/Lessons/EditScene', [
            'lesson' => $lesson->only(['id', 'title']),
            'scene' => [
                'id' => $scene->id,
                'sequence_order' => $scene->sequence_order,
                'scene_type' => $scene->scene_type,
                'title' => $scene->title,
                'learning_objective' => $scene->learning_objective,
                'estimated_duration_seconds' => $scene->estimated_duration_seconds,
                'content' => $scene->content,
                'generation_status' => $scene->generation_status,
            ],
        ]);
    }

    public function update(Request $request, ClassroomLesson $lesson, LessonScene $scene): RedirectResponse
    {
        $this->authorize('update', $lesson);
        abort_unless($scene->lesson_id === $lesson->id, 404);

        $data = $request->validate([
            'title' => 'sometimes|string|max:200',
            'learning_objective' => 'nullable|string|max:500',
            'estimated_duration_seconds' => 'sometimes|integer|min:30|max:7200',
            'content' => 'nullable|array',
        ]);

        if (isset($data['content']) && is_array($data['content'])) {
            $data['content'] = SceneContentSanitizer::sanitizeForType($scene->scene_type, $data['content']);
        }

        $scene->update($data);

        return redirect()
            ->route('teacher.lessons.show', $lesson)
            ->with('success', 'Scene updated.');
    }

    public function store(Request $request, ClassroomLesson $lesson): RedirectResponse
    {
        $this->authorize('update', $lesson);

        $data = $request->validate([
            'scene_type' => 'required|in:slide,quiz,interactive,pbl,discussion',
            'title' => 'required|string|max:200',
        ]);

        $max = (int) $lesson->scenes()->max('sequence_order');
        $order = $max + 1;

        $content = match ($data['scene_type']) {
            'slide' => ['elements' => []],
            'quiz' => ['questions' => []],
            'discussion' => ['topic' => $data['title'], 'prompt' => ''],
            'interactive' => ['html' => '<p>Edit this interactive content.</p>'],
            'pbl' => ['brief' => ''],
            default => [],
        };

        LessonScene::create([
            'lesson_id' => $lesson->id,
            'district_id' => $lesson->district_id,
            'sequence_order' => $order,
            'scene_type' => $data['scene_type'],
            'title' => $data['title'],
            'learning_objective' => null,
            'estimated_duration_seconds' => 120,
            'content' => $content,
            'generation_status' => 'ready',
        ]);

        return redirect()
            ->route('teacher.lessons.show', $lesson)
            ->with('success', 'Scene added.');
    }

    public function destroy(ClassroomLesson $lesson, LessonScene $scene): RedirectResponse
    {
        $this->authorize('update', $lesson);
        abort_unless($scene->lesson_id === $lesson->id, 404);

        $scene->delete();

        foreach ($lesson->scenes()->orderBy('sequence_order')->get() as $i => $s) {
            $s->update(['sequence_order' => $i]);
        }

        return redirect()
            ->route('teacher.lessons.show', $lesson)
            ->with('success', 'Scene removed.');
    }

    public function reorder(Request $request, ClassroomLesson $lesson): RedirectResponse
    {
        $this->authorize('update', $lesson);

        $data = $request->validate([
            'scene_ids' => 'required|array|min:1',
            'scene_ids.*' => 'uuid',
        ]);

        $ids = $data['scene_ids'];
        $existing = $lesson->scenes()->pluck('id')->sort()->values()->all();
        $sorted = collect($ids)->sort()->values()->all();

        if (count($existing) !== count($ids) || $existing !== $sorted) {
            abort(422, 'Scene order must include each scene exactly once.');
        }

        foreach ($ids as $i => $id) {
            LessonScene::whereKey($id)->where('lesson_id', $lesson->id)->update(['sequence_order' => $i]);
        }

        return back()->with('success', 'Scene order saved.');
    }
}
