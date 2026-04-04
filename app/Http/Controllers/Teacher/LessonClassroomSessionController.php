<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\ClassroomLesson;
use App\Models\ClassroomMessage;
use App\Models\ClassroomSession;
use Inertia\Inertia;
use Inertia\Response;

class LessonClassroomSessionController extends Controller
{
    public function index(ClassroomLesson $lesson): Response
    {
        $this->authorize('view', $lesson);

        $sessions = $lesson->sessions()
            ->with(['student:id,name'])
            ->withCount('messages')
            ->latest('started_at')
            ->paginate(25);

        $sessions->setCollection(
            $sessions->getCollection()->map(fn (ClassroomSession $s) => [
                'id' => $s->id,
                'status' => $s->status,
                'started_at' => $s->started_at?->toIso8601String(),
                'ended_at' => $s->ended_at?->toIso8601String(),
                'student' => $s->student,
                'messages_count' => $s->messages_count,
            ])
        );

        return Inertia::render('Teacher/Lessons/Sessions/Index', [
            'lesson' => $lesson->only(['id', 'title']),
            'sessions' => $sessions,
        ]);
    }

    public function show(ClassroomLesson $lesson, ClassroomSession $session): Response
    {
        $this->authorize('view', $lesson);
        abort_unless($session->lesson_id === $lesson->id, 404);
        $this->authorize('view', $session);

        $session->load([
            'student:id,name',
            'messages' => fn ($q) => $q->with('agent:id,display_name')->orderBy('created_at'),
        ]);

        $messages = $session->messages->map(fn (ClassroomMessage $m) => [
            'id' => $m->id,
            'sender_type' => $m->sender_type,
            'content' => $m->content_text ?? '',
            'agent_name' => $m->agent?->display_name,
            'created_at' => $m->created_at?->toIso8601String() ?? '',
        ]);

        return Inertia::render('Teacher/Lessons/Sessions/Show', [
            'lesson' => $lesson->only(['id', 'title']),
            'session' => [
                'id' => $session->id,
                'status' => $session->status,
                'started_at' => $session->started_at?->toIso8601String(),
                'ended_at' => $session->ended_at?->toIso8601String(),
                'student' => $session->student,
            ],
            'messages' => $messages,
        ]);
    }
}
