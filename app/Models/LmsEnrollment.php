<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsEnrollment extends BaseModel
{
    protected $table = 'lms_enrollments';

    protected $fillable = [
        'district_id', 'student_id', 'lms_provider', 'lms_course_id',
        'course_name', 'course_subject', 'grade_level', 'teacher_name',
        'enrollment_date', 'end_date', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'enrollment_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }
}
