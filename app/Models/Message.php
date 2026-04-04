<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends BaseModel
{
    protected $fillable = [
        'session_id', 'district_id', 'role', 'content',
        'flagged', 'flag_reason', 'flag_category', 'tokens',
    ];

    protected function casts(): array
    {
        return ['flagged' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('district', function ($query) {
            if (auth()->check()) {
                $query->where('messages.district_id', auth()->user()->district_id);
            }
        });
    }

    public function session(): BelongsTo { return $this->belongsTo(StudentSession::class, 'session_id'); }
}
