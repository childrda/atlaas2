<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassroomSession extends BaseModel
{
    protected $fillable = [
        'district_id', 'lesson_id', 'student_id', 'current_scene_id',
        'current_scene_action_index', 'director_state', 'whiteboard_elements',
        'whiteboard_open', 'session_type', 'status', 'started_at', 'ended_at',
        'student_summary', 'teacher_summary',
    ];

    protected function casts(): array
    {
        return [
            'director_state' => 'array',
            'whiteboard_elements' => 'array',
            'whiteboard_open' => 'boolean',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('district', function ($query) {
            if (auth()->check()) {
                $query->where('classroom_sessions.district_id', auth()->user()->district_id);
            }
        });
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(ClassroomLesson::class, 'lesson_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function currentScene(): BelongsTo
    {
        return $this->belongsTo(LessonScene::class, 'current_scene_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ClassroomMessage::class, 'session_id')->orderBy('created_at');
    }

    public function quizAttempts(): HasMany
    {
        return $this->hasMany(LessonQuizAttempt::class, 'session_id');
    }

    public function getDirectorTurnCount(): int
    {
        return (int) ($this->director_state['turn_count'] ?? 0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAgentsSpokenThisRound(): array
    {
        return $this->director_state['agents_spoken'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getWhiteboardLedger(): array
    {
        return $this->director_state['whiteboard_ledger'] ?? [];
    }
}
