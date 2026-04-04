<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassroomMessage extends BaseModel
{
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'session_id', 'district_id', 'sender_type', 'agent_id',
        'content_text', 'actions_json', 'flagged', 'flag_reason',
    ];

    protected function casts(): array
    {
        return [
            'actions_json' => 'array',
            'flagged' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ClassroomMessage $m) {
            $m->created_at ??= now();
        });

        static::addGlobalScope('district', function ($query) {
            if (auth()->check()) {
                $query->where('classroom_messages.district_id', auth()->user()->district_id);
            }
        });
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassroomSession::class, 'session_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(LessonAgent::class, 'agent_id');
    }
}
