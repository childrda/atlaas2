<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafetyAlert extends BaseModel
{
    protected $fillable = [
        'district_id', 'school_id', 'session_id', 'classroom_session_id', 'student_id', 'teacher_id',
        'severity', 'category', 'alert_type', 'student_mode', 'counselor_notified', 'counselor_notified_at',
        'trigger_content', 'status',
        'reviewed_by', 'reviewer_notes', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'trigger_content' => 'encrypted',
            'reviewed_at' => 'datetime',
            'counselor_notified' => 'boolean',
            'counselor_notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('district', function ($query) {
            if (auth()->check()) {
                $query->where('safety_alerts.district_id', auth()->user()->district_id);
            }
        });
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(StudentSession::class, 'session_id');
    }

    public function classroomSession(): BelongsTo
    {
        return $this->belongsTo(ClassroomSession::class, 'classroom_session_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }
}
