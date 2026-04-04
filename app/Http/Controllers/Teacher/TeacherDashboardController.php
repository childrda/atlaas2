<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\LearningSpace;
use App\Models\SafetyAlert;
use App\Models\StudentSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeacherDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $teacherId = (string) $request->user()->id;

        $activeSpaces = LearningSpace::forTeacherPortal($teacherId)->count();

        $activeStudents = (int) StudentSession::query()
            ->where('status', 'active')
            ->whereHas('space', fn ($q) => $q->forTeacherPortal($teacherId))
            ->distinct()
            ->count('student_id');

        $openAlerts = SafetyAlert::query()
            ->where('teacher_id', $teacherId)
            ->where('status', 'open')
            ->count();

        return Inertia::render('Teacher/Dashboard', [
            'user' => $request->user()->load('school', 'district'),
            'activeSpacesCount' => $activeSpaces,
            'activeStudentsCount' => $activeStudents,
            'openAlertsCount' => $openAlerts,
            'stats' => [
                'active_spaces' => $activeSpaces,
                'active_students' => $activeStudents,
                'open_alerts' => $openAlerts,
            ],
        ]);
    }
}
