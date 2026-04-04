<?php

namespace App\Events;

use App\Models\StudentSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public StudentSession $session,
        public string $messagePreview,
    ) {}

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $this->session->loadMissing(['student', 'space']);

        return [
            new PrivateChannel('compass.'.$this->session->space->teacher_id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->session->loadMissing(['student', 'space']);

        return [
            'session_id' => $this->session->id,
            'student_id' => $this->session->student_id,
            'student_name' => $this->session->student->name,
            'message_count' => $this->session->message_count,
            'last_message' => $this->messagePreview,
            'timestamp' => now()->toISOString(),
        ];
    }
}
