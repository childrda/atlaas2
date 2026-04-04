<?php

namespace App\Events;

use App\Models\ClassroomSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClassroomSessionStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ClassroomSession $session) {}

    public function broadcastAs(): string
    {
        return 'classroom.session.started';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $this->session->loadMissing(['student', 'lesson.space']);

        $teacherId = $this->session->lesson->space?->teacher_id ?? $this->session->lesson->teacher_id;

        return [
            new PrivateChannel('compass.'.$teacherId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->session->loadMissing(['student', 'lesson.space']);

        $lesson = $this->session->lesson;

        return [
            'session_id' => $this->session->id,
            'session_kind' => 'classroom',
            'student_id' => $this->session->student_id,
            'student_name' => $this->session->student->name,
            'space_id' => $lesson->space_id ?? '',
            'space_title' => $lesson->space?->title ?? $lesson->title,
            'started_at' => $this->session->started_at?->toISOString(),
            'message_count' => 0,
            'status' => $this->session->status,
            'last_message' => null,
            'last_activity_at' => $this->session->started_at?->toISOString() ?? now()->toISOString(),
        ];
    }
}
