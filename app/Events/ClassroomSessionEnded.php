<?php

namespace App\Events;

use App\Models\ClassroomSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClassroomSessionEnded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ClassroomSession $session) {}

    public function broadcastAs(): string
    {
        return 'classroom.session.ended';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $this->session->loadMissing(['lesson.space']);

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
        return [
            'session_id' => $this->session->id,
            'session_kind' => 'classroom',
        ];
    }
}
