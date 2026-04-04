<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentSession extends BaseModel
{
    protected $fillable = [
        'district_id', 'student_id', 'space_id', 'status',
        'message_count', 'tokens_used', 'student_summary', 'teacher_summary',
        'started_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('district', function ($query) {
            if (auth()->check()) {
                $query->where('student_sessions.district_id', auth()->user()->district_id);
            }
        });
    }

    public function student(): BelongsTo { return $this->belongsTo(User::class, 'student_id'); }

    public function space(): BelongsTo { return $this->belongsTo(LearningSpace::class, 'space_id'); }

    public function messages(): HasMany { return $this->hasMany(Message::class, 'session_id'); }
}
