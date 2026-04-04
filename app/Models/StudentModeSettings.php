<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentModeSettings extends BaseModel
{
    protected $fillable = [
        'district_id', 'school_id',
        'teacher_session_enabled', 'lms_help_enabled', 'open_tutor_enabled',
        'crisis_counselor_name', 'crisis_counselor_email', 'crisis_response_template',
    ];

    protected function casts(): array
    {
        return [
            'teacher_session_enabled' => 'boolean',
            'lms_help_enabled' => 'boolean',
            'open_tutor_enabled' => 'boolean',
        ];
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
