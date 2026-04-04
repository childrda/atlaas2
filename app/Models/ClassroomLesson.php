<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassroomLesson extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'district_id', 'teacher_id', 'space_id', 'title', 'subject',
        'grade_level', 'language', 'source_type', 'source_text',
        'source_file_path', 'generation_job_id', 'generation_status',
        'generation_progress', 'outline', 'agent_mode', 'status', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'generation_progress' => 'array',
            'outline' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('district', function ($query) {
            if (auth()->check()) {
                $query->where('classroom_lessons.district_id', auth()->user()->district_id);
            }
        });
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(LessonScene::class, 'lesson_id')->orderBy('sequence_order');
    }

    public function agents(): HasMany
    {
        return $this->hasMany(LessonAgent::class, 'lesson_id')->orderBy('sequence_order');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassroomSession::class, 'lesson_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(LearningSpace::class, 'space_id');
    }

    public function isGenerationComplete(): bool
    {
        return $this->generation_status === 'completed';
    }
}
