<?php

namespace App\Jobs;

use App\Events\AlertFired;
use App\Mail\SafetyAlertMail;
use App\Models\SafetyAlert;
use App\Models\StudentSession;
use App\Services\AI\FlagResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class ProcessSafetyAlert implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'critical';

    public int $tries = 3;

    public function __construct(
        public StudentSession $session,
        public FlagResult $flag,
        public string $triggerContent,
    ) {}

    public function handle(): void
    {
        $this->session->loadMissing('space.teacher');

        $space = $this->session->space;
        $teacher = $space->teacher;

        $alert = SafetyAlert::create([
            'district_id' => $this->session->district_id,
            'school_id' => $teacher->school_id,
            'session_id' => $this->session->id,
            'student_id' => $this->session->student_id,
            'teacher_id' => $space->teacher_id,
            'severity' => $this->flag->severity,
            'category' => $this->flag->category,
            'alert_type' => 'content',
            'student_mode' => $space?->student_mode,
            'trigger_content' => $this->triggerContent,
            'status' => 'open',
        ]);

        AlertFired::dispatch($alert->load('student'));

        if ($this->flag->severity === 'critical') {
            Mail::to($teacher->email)
                ->send(new SafetyAlertMail($alert->load(['student', 'session.space'])));
        }
    }
}
