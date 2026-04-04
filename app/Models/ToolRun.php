<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolRun extends BaseModel
{
    protected $fillable = [
        'teacher_id', 'tool_id', 'inputs', 'output', 'tokens_used',
    ];

    protected function casts(): array
    {
        return ['inputs' => 'array'];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('district', function ($query) {
            if (auth()->check()) {
                $query->whereHas('teacher', fn ($q) => $q->where('users.district_id', auth()->user()->district_id));
            }
        });
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(TeacherTool::class, 'tool_id');
    }
}
