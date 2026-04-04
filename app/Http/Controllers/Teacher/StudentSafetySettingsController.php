<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\StudentModeSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StudentSafetySettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        abort_unless($request->user()->hasRole(['district_admin', 'school_admin']), 403);

        $user = $request->user();

        if ($user->hasRole('district_admin')) {
            $districtDefault = StudentModeSettings::firstOrCreate(
                ['district_id' => $user->district_id, 'school_id' => null],
                [
                    'teacher_session_enabled' => true,
                    'lms_help_enabled' => false,
                    'open_tutor_enabled' => false,
                ]
            );

            $schools = School::query()->where('district_id', $user->district_id)->orderBy('name')->get();
            $schoolSettings = $schools->map(function (School $school) use ($user) {
                $m = StudentModeSettings::firstOrCreate(
                    ['district_id' => $user->district_id, 'school_id' => $school->id],
                    [
                        'teacher_session_enabled' => true,
                        'lms_help_enabled' => false,
                        'open_tutor_enabled' => false,
                    ]
                );
                $m->setRelation('school', $school);

                return $m;
            });

            return Inertia::render('Teacher/Settings/StudentSafety', [
                'scope' => 'district',
                'districtDefault' => $districtDefault,
                'schoolSettings' => $schoolSettings,
            ]);
        }

        abort_unless($user->school_id, 403, 'School administrators must be assigned to a school.');

        $settings = StudentModeSettings::firstOrCreate(
            ['district_id' => $user->district_id, 'school_id' => $user->school_id],
            [
                'teacher_session_enabled' => true,
                'lms_help_enabled' => false,
                'open_tutor_enabled' => false,
            ]
        );

        return Inertia::render('Teacher/Settings/StudentSafety', [
            'scope' => 'school',
            'settings' => $settings,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole(['district_admin', 'school_admin']), 403);

        $user = $request->user();

        if ($user->hasRole('district_admin')) {
            $data = $request->validate([
                'district_default' => 'required|array',
                'district_default.teacher_session_enabled' => 'boolean',
                'district_default.lms_help_enabled' => 'boolean',
                'district_default.open_tutor_enabled' => 'boolean',
                'district_default.crisis_counselor_name' => 'nullable|string|max:255',
                'district_default.crisis_counselor_email' => 'nullable|email|max:255',
                'district_default.crisis_response_template' => 'nullable|string|max:5000',
                'schools' => 'nullable|array',
                'schools.*.id' => [
                    'required',
                    'uuid',
                    Rule::exists('student_mode_settings', 'id')->where('district_id', $user->district_id),
                ],
                'schools.*.teacher_session_enabled' => 'boolean',
                'schools.*.lms_help_enabled' => 'boolean',
                'schools.*.open_tutor_enabled' => 'boolean',
                'schools.*.crisis_counselor_name' => 'nullable|string|max:255',
                'schools.*.crisis_counselor_email' => 'nullable|email|max:255',
                'schools.*.crisis_response_template' => 'nullable|string|max:5000',
            ]);

            $dd = $data['district_default'];
            StudentModeSettings::query()
                ->where('district_id', $user->district_id)
                ->whereNull('school_id')
                ->update([
                    'teacher_session_enabled' => (bool) ($dd['teacher_session_enabled'] ?? true),
                    'lms_help_enabled' => (bool) ($dd['lms_help_enabled'] ?? false),
                    'open_tutor_enabled' => (bool) ($dd['open_tutor_enabled'] ?? false),
                    'crisis_counselor_name' => $dd['crisis_counselor_name'] ?? null,
                    'crisis_counselor_email' => $dd['crisis_counselor_email'] ?? null,
                    'crisis_response_template' => $dd['crisis_response_template'] ?? null,
                ]);

            foreach ($data['schools'] ?? [] as $row) {
                $s = StudentModeSettings::query()
                    ->where('id', $row['id'])
                    ->where('district_id', $user->district_id)
                    ->whereNotNull('school_id')
                    ->first();
                if (! $s) {
                    continue;
                }
                $s->update([
                    'teacher_session_enabled' => (bool) ($row['teacher_session_enabled'] ?? true),
                    'lms_help_enabled' => (bool) ($row['lms_help_enabled'] ?? false),
                    'open_tutor_enabled' => (bool) ($row['open_tutor_enabled'] ?? false),
                    'crisis_counselor_name' => $row['crisis_counselor_name'] ?? null,
                    'crisis_counselor_email' => $row['crisis_counselor_email'] ?? null,
                    'crisis_response_template' => $row['crisis_response_template'] ?? null,
                ]);
            }

            return back()->with('success', 'Student safety settings saved.');
        }

        abort_unless($user->school_id, 403);

        $row = $request->validate([
            'teacher_session_enabled' => 'boolean',
            'lms_help_enabled' => 'boolean',
            'open_tutor_enabled' => 'boolean',
            'crisis_counselor_name' => 'nullable|string|max:255',
            'crisis_counselor_email' => 'nullable|email|max:255',
            'crisis_response_template' => 'nullable|string|max:5000',
        ]);

        StudentModeSettings::query()
            ->where('district_id', $user->district_id)
            ->where('school_id', $user->school_id)
            ->update([
                'teacher_session_enabled' => (bool) ($row['teacher_session_enabled'] ?? true),
                'lms_help_enabled' => (bool) ($row['lms_help_enabled'] ?? false),
                'open_tutor_enabled' => (bool) ($row['open_tutor_enabled'] ?? false),
                'crisis_counselor_name' => $row['crisis_counselor_name'] ?? null,
                'crisis_counselor_email' => $row['crisis_counselor_email'] ?? null,
                'crisis_response_template' => $row['crisis_response_template'] ?? null,
            ]);

        return back()->with('success', 'Student safety settings saved.');
    }
}
