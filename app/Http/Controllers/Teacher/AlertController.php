<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\SafetyAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    public function index(): Response
    {
        $alerts = SafetyAlert::where('teacher_id', auth()->id())
            ->with([
                'student:id,name',
                'session.space:id,title',
                'classroomSession.lesson.space:id,title',
            ])
            ->orderByRaw("
                CASE severity
                    WHEN 'critical' THEN 1
                    WHEN 'high'     THEN 2
                    WHEN 'medium'   THEN 3
                    ELSE 4
                END
            ")
            ->orderBy('created_at', 'desc')
            ->paginate(25);

        $openCriticalCount = SafetyAlert::where('teacher_id', auth()->id())
            ->where('status', 'open')
            ->where('severity', 'critical')
            ->count();

        return Inertia::render('Teacher/Alerts/Index', [
            'alerts' => $alerts,
            'openCriticalCount' => $openCriticalCount,
        ]);
    }

    public function update(Request $request, SafetyAlert $alert): RedirectResponse
    {
        abort_unless(
            $alert->teacher_id === auth()->id() || auth()->user()->hasRole(['school_admin', 'district_admin']),
            403
        );

        $data = $request->validate([
            'status' => 'required|in:reviewed,resolved,dismissed,escalated',
            'reviewer_notes' => 'nullable|string|max:1000',
        ]);

        $alert->update([
            ...$data,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Alert updated.');
    }
}
