<?php

namespace App\Services\Safety;

use App\Events\AlertFired;
use App\Models\ClassroomSession;
use App\Models\SafetyAlert;
use App\Models\User;
use App\Services\AI\FlagResult;
use App\Services\AI\SafetyFilter;

class ClassroomStudentSafetyCoordinator
{
    public function __construct(
        private CrisisDetector $crisis,
        private CrisisResponder $crisisResponder,
        private SafetyFilter $safety,
        private AcademicIntegrityGuard $integrity,
        private TopicScopeService $topicScope,
        private SessionModeResolver $modeResolver,
    ) {}

    /**
     * @return array{0: FlagResult|null, 1: string|null}
     */
    public function evaluateBeforeAgents(ClassroomSession $session, string $userMessage): array
    {
        $session->loadMissing('student', 'lesson.space', 'lesson.teacher');
        $student = $session->student;
        if (! $student instanceof User) {
            return [null, null];
        }

        $crisisHit = $this->crisis->detect($userMessage);
        if ($crisisHit->detected && $crisisHit->type) {
            $reply = $this->crisisResponder->respondForClassroom($session, $student, $crisisHit->type, $userMessage);

            return [new FlagResult(true, 'crisis_'.$crisisHit->type, 'critical'), $reply];
        }

        $flag = $this->safety->check($userMessage);
        if ($flag && $flag->flagged && in_array($flag->severity, ['critical', 'high'], true)) {
            return [$flag, $this->safety->safeAtlaasResponse($flag->category)];
        }
        if ($flag && $flag->severity === 'medium' && $flag->category === 'cheating_request') {
            return [$flag, $this->safety->safeAtlaasResponse($flag->category)];
        }

        $ctx = $this->modeResolver->resolveForClassroom($session);
        if ($this->integrity->shouldBlock($userMessage)) {
            $this->recordIntegrityAlert($session, $student, $userMessage, $ctx->studentMode);

            return [new FlagResult(true, 'academic_integrity', 'medium'), $this->integrity->response()];
        }

        if (! $this->topicScope->isOnTopic($userMessage, $ctx)) {
            $this->recordOffTopicAlert($session, $student, $userMessage, $ctx->studentMode);

            return [new FlagResult(true, 'scope:off_topic', 'low'), $this->topicScope->offTopicRedirect()];
        }

        return [null, null];
    }

    public function orchestratorScopeBlock(ClassroomSession $session): string
    {
        $ctx = $this->modeResolver->resolveForClassroom($session);

        return $this->modeResolver->orchestratorScopeBlock($session, $ctx);
    }

    private function recordIntegrityAlert(ClassroomSession $session, User $student, string $userMessage, string $mode): void
    {
        $session->loadMissing('lesson.teacher');
        $lesson = $session->lesson;
        $teacherId = $lesson?->teacher_id;
        if (! $teacherId) {
            return;
        }

        $alert = SafetyAlert::create([
            'district_id' => $session->district_id,
            'school_id' => $lesson?->teacher?->school_id,
            'session_id' => null,
            'classroom_session_id' => $session->id,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'severity' => 'medium',
            'category' => 'academic_integrity',
            'alert_type' => 'academic_integrity',
            'student_mode' => $mode,
            'trigger_content' => $userMessage,
            'status' => 'open',
        ]);
        AlertFired::dispatch($alert->load('student'));
    }

    private function recordOffTopicAlert(ClassroomSession $session, User $student, string $userMessage, string $mode): void
    {
        $session->loadMissing('lesson.teacher');
        $lesson = $session->lesson;
        $teacherId = $lesson?->teacher_id;
        if (! $teacherId) {
            return;
        }

        $alert = SafetyAlert::create([
            'district_id' => $session->district_id,
            'school_id' => $lesson?->teacher?->school_id,
            'session_id' => null,
            'classroom_session_id' => $session->id,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'severity' => 'low',
            'category' => 'scope:off_topic',
            'alert_type' => 'off_topic',
            'student_mode' => $mode,
            'trigger_content' => $userMessage,
            'status' => 'open',
        ]);
        AlertFired::dispatch($alert->load('student'));
    }
}
