<?php

namespace App\Services\Safety;

use App\Events\AlertFired;
use App\Models\SafetyAlert;
use App\Models\StudentSession;
use App\Models\User;
use App\Services\AI\FlagResult;
use App\Services\AI\SafetyFilter;

class StudentChatSafetyCoordinator
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
     * @return array{0: FlagResult|null, 1: string|null} [userFlag, syntheticAssistantReply or null if proceed]
     */
    public function evaluateBeforeLlm(StudentSession $session, string $userMessage): array
    {
        $session->loadMissing('student', 'space');
        $student = $session->student;
        if (! $student) {
            return [null, null];
        }

        $crisisHit = $this->crisis->detect($userMessage);
        if ($crisisHit->detected && $crisisHit->type) {
            $reply = $this->crisisResponder->respondForChat($session, $student, $crisisHit->type, $userMessage);

            return [new FlagResult(true, 'crisis_'.$crisisHit->type, 'critical'), $reply];
        }

        $flag = $this->safety->check($userMessage);
        if ($flag && in_array($flag->severity, ['critical', 'high'], true)) {
            return [$flag, $this->safety->safeAtlaasResponse($flag->category)];
        }
        if ($flag && $flag->severity === 'medium' && $flag->category === 'cheating_request') {
            return [$flag, $this->safety->safeAtlaasResponse($flag->category)];
        }

        $ctx = $this->modeResolver->resolveForChat($session);
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

    public function systemPromptAppendix(StudentSession $session): string
    {
        $ctx = $this->modeResolver->resolveForChat($session);

        return $this->modeResolver->systemPromptAppendixForChat($session, $ctx);
    }

    private function recordIntegrityAlert(StudentSession $session, $student, string $userMessage, string $mode): void
    {
        $session->loadMissing('space.teacher');
        $space = $session->space;
        $teacherId = $space?->teacher_id;
        if (! $teacherId) {
            return;
        }

        $alert = SafetyAlert::create([
            'district_id' => $session->district_id,
            'school_id' => $space?->teacher?->school_id,
            'session_id' => $session->id,
            'classroom_session_id' => null,
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

    private function recordOffTopicAlert(StudentSession $session, User $student, string $userMessage, string $mode): void
    {
        $session->loadMissing('space.teacher');
        $space = $session->space;
        $teacherId = $space?->teacher_id;
        if (! $teacherId) {
            return;
        }

        $alert = SafetyAlert::create([
            'district_id' => $session->district_id,
            'school_id' => $space?->teacher?->school_id,
            'session_id' => $session->id,
            'classroom_session_id' => null,
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
