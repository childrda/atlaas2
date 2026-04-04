<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LessonScene extends BaseModel
{
    protected $fillable = [
        'lesson_id', 'district_id', 'sequence_order', 'scene_type',
        'title', 'learning_objective', 'estimated_duration_seconds',
        'outline_data', 'content', 'actions', 'generation_status', 'generation_error',
    ];

    protected function casts(): array
    {
        return [
            'outline_data' => 'array',
            'content' => 'array',
            'actions' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('district', function ($query) {
            if (auth()->check()) {
                $query->where('lesson_scenes.district_id', auth()->user()->district_id);
            }
        });
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(ClassroomLesson::class, 'lesson_id');
    }

    public function quizAttempts(): HasMany
    {
        return $this->hasMany(LessonQuizAttempt::class, 'scene_id');
    }
}
