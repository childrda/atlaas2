<?php

namespace App\Events;

use App\Models\ClassroomSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClassroomWhiteboardUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ClassroomSession $session) {}

    public function broadcastAs(): string
    {
        return 'whiteboard.updated';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('classroom.'.$this->session->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->session->refresh();

        return [
            'elements' => $this->session->whiteboard_elements ?? [],
            'open' => (bool) $this->session->whiteboard_open,
        ];
    }
}
