<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhiteboardSnapshot extends BaseModel
{
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'session_id', 'scene_id', 'elements',
    ];

    protected function casts(): array
    {
        return [
            'elements' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WhiteboardSnapshot $m) {
            $m->created_at ??= now();
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
