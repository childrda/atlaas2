<?php

namespace App\Services\Safety;

use App\Models\ClassroomSession;
use App\Models\LearningSpace;
use App\Models\LmsEnrollment;
use App\Models\StudentModeSettings;
use App\Models\StudentSession;
use App\Models\User;

class SessionModeResolver
{
    public function settingsForUser(User $user): ?StudentModeSettings
    {
        if ($user->school_id) {
            $row = StudentModeSettings::query()
                ->where('district_id', $user->district_id)
                ->where('school_id', $user->school_id)
                ->first();
            if ($row) {
                return $row;
            }
        }

        return StudentModeSettings::query()
            ->where('district_id', $user->district_id)
            ->whereNull('school_id')
            ->first();
    }

    /**
     * @return list<string>
     */
    public function allowedModesForTeacher(User $teacher): array
    {
        $settings = $this->settingsForUser($teacher);
        $modes = [];
        if (! $settings || $settings->teacher_session_enabled) {
            $modes[] = 'teacher_session';
        }
        if ($settings && $settings->lms_help_enabled) {
            $modes[] = 'lms_help';
        }
        if ($settings && $settings->open_tutor_enabled) {
            $modes[] = 'open_tutor';
        }

        return $modes !== [] ? $modes : ['teacher_session'];
    }

    public function normalizeSpaceMode(LearningSpace $space, User $teacher): string
    {
        $allowed = $this->allowedModesForTeacher($teacher);
        $mode = $space->student_mode ?? 'teacher_session';
        if (in_array($mode, $allowed, true)) {
            return $mode;
        }

        return 'teacher_session';
    }

    public function resolveForChat(StudentSession $session): ModeContext
    {
        $session->loadMissing(['space.teacher', 'student']);
        $space = $session->space;
        $student = $session->student;
        $teacher = $space?->teacher;
        $mode = $teacher ? $this->normalizeSpaceMode($space, $teacher) : ($space->student_mode ?? 'teacher_session');

        $courses = [];
        if ($mode === 'lms_help' && $student) {
            $courses = LmsEnrollment::query()
                ->where('district_id', $student->district_id)
                ->where('student_id', $student->id)
                ->where('is_active', true)
                ->pluck('course_name')
                ->unique()
                ->values()
                ->all();
        }

        $scope = match ($mode) {
            'lms_help' => $this->chatScopeLms($space, $courses),
            'open_tutor' => 'You are helping with K-12 academic subjects only. Redirect personal, medical, legal, or non-academic chit-chat back to school-appropriate learning.',
            default => $this->chatScopeTeacherSession($space),
        };

        return new ModeContext($mode, $scope, $courses);
    }

    public function resolveForClassroom(ClassroomSession $session): ModeContext
    {
        $session->loadMissing(['lesson.space.teacher', 'currentScene', 'student']);
        $space = $session->lesson?->space;
        $teacher = $space?->teacher ?? $session->lesson?->teacher;
        $mode = $space && $teacher ? $this->normalizeSpaceMode($space, $teacher) : 'teacher_session';

        $lesson = $session->lesson;
        $scene = $session->currentScene;
        $scope = 'You are in a live classroom lesson. Stay on the current lesson and scene. '
            .'Lesson: '.($lesson->title ?? 'lesson').'. '
            .'Subject: '.($lesson->subject ?? '').'. '
            .'Current scene: '.($scene->title ?? 'current activity').'. '
            .'Objective: '.(trim(strip_tags((string) ($scene->learning_objective ?? ''))) ?: 'follow the lesson flow').'.';

        return new ModeContext($mode === 'open_tutor' ? 'open_tutor' : 'teacher_session', $scope, []);
    }

    public function systemPromptAppendixForChat(StudentSession $session, ModeContext $ctx): string
    {
        return $ctx->scopeDescription;
    }

    public function orchestratorScopeBlock(ClassroomSession $session, ModeContext $ctx): string
    {
        return $ctx->scopeDescription;
    }

    private function chatScopeTeacherSession(?LearningSpace $space): string
    {
        if (! $space) {
            return 'Stay focused on the learning space topic and teacher instructions.';
        }

        $goals = is_array($space->goals) && $space->goals !== []
            ? ' Goals: '.implode('; ', array_map('strval', $space->goals)).'.'
            : '';

        return 'You are helping a student in the space "'.$space->title.'". '
            .'Subject: '.($space->subject ?? 'general').'. '
            .'Only discuss topics directly related to this space and its learning goals.'.$goals
            .' If the student goes off-topic, acknowledge briefly and steer back.';
    }

    /**
     * @param  list<string>  $courses
     */
    private function chatScopeLms(?LearningSpace $space, array $courses): string
    {
        $list = $courses !== [] ? implode(', ', array_map('strval', $courses)) : 'their enrolled courses (none on file yet — keep help general and grade-appropriate)';
        $title = $space?->title ? ' Current space: '.$space->title.'.' : '';

        return 'The student may ask about any topic from their enrolled courses: '.$list.'.'.$title
            .' Decline topics clearly outside those subjects.';
    }
}
