<?php

namespace App\Http\Controllers\Student;

use App\Events\SessionEnded;
use App\Events\SessionStarted;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\AuthorizesStudentLearningSpace;
use App\Jobs\GenerateSessionSummary;
use App\Models\LearningSpace;
use App\Models\StudentSession;
use App\Services\AI\LLMService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    use AuthorizesStudentLearningSpace;

    public function start(LearningSpace $space): RedirectResponse
    {
        $this->authorizeStudentLearningSpace(auth()->user(), $space);

        abort_unless($space->is_published, 403, 'This space is not available.');
        abort_if($space->is_archived, 403, 'This space has been archived.');

        if ($space->opens_at && now()->lt($space->opens_at)) {
            return back()->with('error', 'This space is not open yet.');
        }

        if ($space->closes_at && now()->gt($space->closes_at)) {
            return back()->with('error', 'This space has closed.');
        }

        $session = StudentSession::firstOrCreate(
            [
                'student_id' => auth()->id(),
                'space_id' => $space->id,
                'status' => 'active',
            ],
            [
                'district_id' => auth()->user()->district_id,
                'started_at' => now(),
            ]
        );

        $session->load(['student', 'space']);
        if ($session->wasRecentlyCreated) {
            SessionStarted::dispatch($session);
        }

        return redirect()->route('student.sessions.show', $session);
    }

    public function show(StudentSession $session): Response
    {
        abort_unless($session->student_id === auth()->id(), 403);

        $rows = $session->messages()
            ->whereIn('role', ['user', 'assistant', 'teacher_inject'])
            ->orderBy('created_at')
            ->get(['id', 'role', 'content', 'created_at']);

        $llm = app(LLMService::class);
        $messages = $rows->map(function ($m) use ($llm) {
            $base = [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'created_at' => $m->created_at?->toIso8601String(),
            ];
            if ($m->role === 'assistant') {
                $base['segments'] = $llm->parseAndEnrichForDisplay($m->content);
            }

            return $base;
        });

        return Inertia::render('Student/Session', [
            'session' => $session->load('space:id,title,description,atlaas_tone,goals,max_messages'),
            'messages' => $messages,
        ]);
    }

    public function end(StudentSession $session): RedirectResponse
    {
        abort_unless($session->student_id === auth()->id(), 403);
        abort_unless($session->status === 'active', 422);

        $session->update([
            'status' => 'completed',
            'ended_at' => now(),
        ]);

        $session->load(['student', 'space']);
        SessionEnded::dispatch($session);

        GenerateSessionSummary::dispatch($session);

        return redirect()->route('student.dashboard')
            ->with('success', 'Great work! Your session is complete.');
    }
}
