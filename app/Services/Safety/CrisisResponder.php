<?php

namespace App\Services\Safety;

use App\Events\AlertFired;
use App\Mail\CrisisCounselorMail;
use App\Mail\SafetyAlertMail;
use App\Models\ClassroomSession;
use App\Models\SafetyAlert;
use App\Models\StudentModeSettings;
use App\Models\StudentSession;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class CrisisResponder
{
    public function respondForChat(StudentSession $session, User $student, string $crisisType, string $originalMessage): string
    {
        $session->loadMissing('space.teacher');
        $space = $session->space;
        $teacherId = $space?->teacher_id;
        $schoolId = $space?->teacher?->school_id;

        $text = $this->composeMessage($student, $crisisType);
        $this->persistAlert(
            student: $student,
            crisisType: $crisisType,
            originalMessage: $originalMessage,
            teacherId: $teacherId,
            schoolId: $schoolId,
            studentMode: $space?->student_mode,
            sessionId: $session->id,
            classroomSessionId: null,
        );

        return $text;
    }

    public function respondForClassroom(ClassroomSession $session, User $student, string $crisisType, string $originalMessage): string
    {
        $session->loadMissing(['lesson.space', 'lesson.teacher']);
        $lesson = $session->lesson;
        $teacherId = $lesson?->teacher_id;
        $schoolId = $lesson?->teacher?->school_id;

        $text = $this->composeMessage($student, $crisisType);
        $this->persistAlert(
            student: $student,
            crisisType: $crisisType,
            originalMessage: $originalMessage,
            teacherId: $teacherId,
            schoolId: $schoolId,
            studentMode: $lesson?->space?->student_mode,
            sessionId: null,
            classroomSessionId: $session->id,
        );

        return $text;
    }

    private function settingsFor(User $student): ?StudentModeSettings
    {
        if ($student->school_id) {
            $row = StudentModeSettings::query()
                ->where('district_id', $student->district_id)
                ->where('school_id', $student->school_id)
                ->first();
            if ($row) {
                return $row;
            }
        }

        return StudentModeSettings::query()
            ->where('district_id', $student->district_id)
            ->whereNull('school_id')
            ->first();
    }

    private function composeMessage(User $student, string $crisisType): string
    {
        $settings = $this->settingsFor($student);
        $template = $settings?->crisis_response_template;
        if (is_string($template) && trim($template) !== '') {
            return trim($template);
        }

        return $this->defaultCrisisMessage($crisisType);
    }

    private function defaultCrisisMessage(string $type): string
    {
        if ($type === 'immediate_danger') {
            return "This sounds like it could be an emergency. Please tell a teacher or trusted adult near you right away, or call 911 if you are in immediate danger.\n\n"
                . "I've alerted your teacher that you reached out.";
        }

        if ($type === 'abuse_signal') {
            return "Thank you for trusting me with this. What you're describing sounds serious, and a trusted adult should know.\n\n"
                . "Please talk to your school counselor as soon as you can. You can also call or text 1-800-422-4453 (Childhelp National Child Abuse Hotline — free, 24/7).\n\n"
                . "I've let your teacher know you reached out.";
        }

        return "It sounds like you might be going through something really hard right now. You don't have to deal with this alone.\n\n"
            . "If you're in crisis, please reach out:\n"
            . "• Call or text 988 (Suicide & Crisis Lifeline — free, 24/7)\n"
            . "• Text HOME to 741741 (Crisis Text Line)\n"
            . "• Talk to a trusted adult at school\n\n"
            . "I'm going to let your teacher know you reached out. Would you like to keep talking? I'm here.";
    }

    private function persistAlert(
        User $student,
        string $crisisType,
        string $originalMessage,
        ?string $teacherId,
        $schoolId,
        ?string $studentMode,
        ?string $sessionId,
        ?string $classroomSessionId,
    ): void {
        if (! $teacherId) {
            return;
        }

        $alert = SafetyAlert::create([
            'district_id' => $student->district_id,
            'school_id' => $schoolId,
            'session_id' => $sessionId,
            'classroom_session_id' => $classroomSessionId,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'severity' => 'critical',
            'category' => 'crisis_'.$crisisType,
            'alert_type' => 'crisis_'.$crisisType,
            'student_mode' => $studentMode,
            'trigger_content' => $originalMessage,
            'status' => 'open',
            'counselor_notified' => false,
        ]);

        $alert->load(['student', 'teacher']);

        if ($alert->teacher?->email) {
            Mail::to($alert->teacher->email)->send(new SafetyAlertMail($alert->load(['student', 'session.space', 'classroomSession.lesson.space'])));
        }

        $settings = $this->settingsFor($student);
        if ($settings && filter_var($settings->crisis_counselor_email, FILTER_VALIDATE_EMAIL)) {
            Mail::to($settings->crisis_counselor_email)->send(new CrisisCounselorMail($alert, $settings));
            $alert->update([
                'counselor_notified' => true,
                'counselor_notified_at' => now(),
            ]);
        }

        $alert->refresh();
        $alert->load(['student', 'teacher']);
        AlertFired::dispatch($alert);
    }
}
