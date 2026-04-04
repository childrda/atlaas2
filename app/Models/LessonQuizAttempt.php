<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonQuizAttempt extends BaseModel
{
    protected $fillable = [
        'session_id', 'district_id', 'scene_id', 'question_index',
        'question_type', 'student_answer', 'is_correct', 'score',
        'max_score', 'llm_feedback', 'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'student_answer' => 'array',
            'is_correct' => 'boolean',
            'score' => 'float',
            'max_score' => 'float',
            'graded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('district', function ($query) {
            if (auth()->check()) {
                $query->where('lesson_quiz_attempts.district_id', auth()->user()->district_id);
            }
        });
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassroomSession::class, 'session_id');
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(LessonScene::class, 'scene_id');
    }
}
