<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\LearningSpace;
use App\Models\StudentSession;
use Inertia\Inertia;
use Inertia\Response;

class StudentDashboardController extends Controller
{
    public function index(): Response
    {
        $enrolledSpaces = LearningSpace::withoutGlobalScope('district')
            ->where('learning_spaces.district_id', auth()->user()->district_id)
            ->where('is_published', true)
            ->where('is_archived', false)
            ->whereHas('classroom.students', fn ($q) => $q->where('users.id', auth()->id()))
            ->with('teacher:id,name')
            ->get()
            ->each->makeHidden(LearningSpace::HIDDEN_FROM_STUDENT_CLIENT);

        $completedSessions = StudentSession::where('student_id', auth()->id())
            ->where('status', 'completed')
            ->whereNotNull('student_summary')
            ->with('space:id,title')
            ->latest('ended_at')
            ->limit(5)
            ->get(['id', 'space_id', 'student_summary', 'ended_at', 'message_count']);

        return Inertia::render('Student/Dashboard', [
            'user' => auth()->user()->load('school', 'district'),
            'enrolledSpaces' => $enrolledSpaces,
            'completedSessions' => $completedSessions,
        ]);
    }
}
