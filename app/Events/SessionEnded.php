<?php

namespace App\Events;

use App\Models\StudentSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SessionEnded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public StudentSession $session) {}

    public function broadcastAs(): string
    {
        return 'session.ended';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $this->session->loadMissing('space');

        return [
            new PrivateChannel('compass.'.$this->session->space->teacher_id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->session->loadMissing('space');

        return [
            'session_id' => $this->session->id,
            'student_id' => $this->session->student_id,
            'ended_at' => now()->toISOString(),
            'message_count' => $this->session->message_count,
        ];
    }
}
