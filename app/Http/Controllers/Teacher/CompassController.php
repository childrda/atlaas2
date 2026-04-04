<?php

namespace App\Http\Controllers\Teacher;

use App\Events\MessageSent;
use App\Events\SessionEnded;
use App\Http\Controllers\Controller;
use App\Models\ClassroomMessage;
use App\Models\ClassroomSession;
use App\Models\Message;
use App\Models\SafetyAlert;
use App\Models\StudentSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompassController extends Controller
{
    public function index(): Response
    {
        $activeSessions = StudentSession::query()
            ->whereHas('space', fn ($q) => $q->where('teacher_id', auth()->id()))
            ->where('status', 'active')
            ->with(['student:id,name', 'space:id,title'])
            ->get()
            ->map(fn (StudentSession $s) => [
                'session_id' => $s->id,
                'session_kind' => 'chat',
                'student_id' => $s->student_id,
                'student_name' => $s->student->name,
                'space_id' => $s->space_id,
                'space_title' => $s->space->title,
                'started_at' => $s->started_at?->toISOString(),
                'message_count' => $s->message_count,
                'status' => $s->status,
                'last_message' => null,
                'last_activity_at' => $s->started_at?->toISOString(),
            ]);

        $classroomActive = ClassroomSession::query()
            ->where('status', 'active')
            ->whereHas('lesson', fn ($q) => $q->where('teacher_id', auth()->id()))
            ->with(['student:id,name', 'lesson:id,title,space_id', 'lesson.space:id,title'])
            ->withCount('messages')
            ->get()
            ->map(fn (ClassroomSession $s) => [
                'session_id' => $s->id,
                'session_kind' => 'classroom',
                'student_id' => $s->student_id,
                'student_name' => $s->student->name,
                'space_id' => $s->lesson->space_id ?? '',
                'space_title' => $s->lesson->space?->title ?? $s->lesson->title,
                'started_at' => $s->started_at?->toISOString(),
                'message_count' => $s->messages_count,
                'status' => $s->status,
                'last_message' => null,
                'last_activity_at' => $s->started_at?->toISOString(),
            ]);

        $activeSessions = $activeSessions->concat($classroomActive);

        $openAlerts = SafetyAlert::query()
            ->where('teacher_id', auth()->id())
            ->where('status', 'open')
            ->with([
                'student:id,name',
                'session.space:id,title',
                'classroomSession.lesson.space:id,title',
            ])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Teacher/Compass/Index', [
            'initialSessions' => $activeSessions,
            'openAlerts' => $openAlerts,
            'teacherId' => auth()->id(),
        ]);
    }

    public function session(StudentSession $session): Response
    {
        $this->authorizeSession($session);

        return Inertia::render('Teacher/Compass/SessionDetail', [
            'session' => $session->load(['student:id,name', 'space:id,title']),
            'messages' => $session->messages()->orderBy('created_at')->get(),
        ]);
    }

    public function classroomSession(ClassroomSession $classroomSession): Response
    {
        $this->authorize('view', $classroomSession);

        $classroomSession->load([
            'student:id,name',
            'lesson.space:id,title',
            'currentScene:id,title,scene_type',
            'messages' => fn ($q) => $q->with('agent:id,display_name')->orderBy('created_at'),
        ]);

        $messages = $classroomSession->messages->map(fn (ClassroomMessage $m) => [
            'id' => $m->id,
            'sender_type' => $m->sender_type,
            'content' => $m->content_text ?? '',
            'agent_name' => $m->agent?->display_name,
            'created_at' => $m->created_at?->toIso8601String() ?? '',
        ]);

        return Inertia::render('Teacher/Compass/ClassroomSessionDetail', [
            'session' => $classroomSession,
            'messages' => $messages,
        ]);
    }

    public function injectMessage(Request $request, StudentSession $session): RedirectResponse
    {
        $this->authorizeSession($session);
        abort_unless($session->status === 'active', 422, 'Session is not active.');

        $data = $request->validate(['content' => 'required|string|max:500']);

        Message::create([
            'session_id' => $session->id,
            'district_id' => $session->district_id,
            'role' => 'teacher_inject',
            'content' => $data['content'],
        ]);

        $session->increment('message_count');
        $session->refresh();
        $session->load(['student', 'space']);
        MessageSent::dispatch($session, '(Teacher) '.substr($data['content'], 0, 70));

        return back()->with('success', 'Message sent to student.');
    }

    public function endSession(StudentSession $session): RedirectResponse
    {
        $this->authorizeSession($session);

        $session->update(['status' => 'abandoned', 'ended_at' => now()]);
        $session->load(['student', 'space']);
        SessionEnded::dispatch($session);

        return back()->with('success', 'Session ended.');
    }

    private function authorizeSession(StudentSession $session): void
    {
        $session->loadMissing('space');

        $isTeacherOwner = $session->space->teacher_id === auth()->id();
        $isAdmin = auth()->user()->hasRole(['school_admin', 'district_admin']);

        abort_unless($isTeacherOwner || $isAdmin, 403);
    }
}
