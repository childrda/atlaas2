<?php

namespace App\Events;

use App\Models\ClassroomLesson;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LessonGenerationCompleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ClassroomLesson $lesson,
    ) {}

    public function broadcastAs(): string
    {
        return 'lesson.generation.completed';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('compass.'.$this->lesson->teacher_id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'lesson_id' => $this->lesson->id,
            'title' => $this->lesson->title,
            'generation_status' => $this->lesson->generation_status,
            'timestamp' => now()->toISOString(),
        ];
    }
}
