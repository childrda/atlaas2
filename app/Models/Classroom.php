<?php

namespace App\Models;

use App\Helpers\JoinCode;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Classroom extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'district_id', 'school_id', 'teacher_id',
        'name', 'subject', 'grade_level', 'join_code', 'external_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (Classroom $classroom) {
            if (empty($classroom->join_code)) {
                $classroom->join_code = JoinCode::generate('classrooms');
            }
        });

        static::addGlobalScope('district', function ($query) {
            if (auth()->check()) {
                $query->where('classrooms.district_id', auth()->user()->district_id);
            }
        });
    }

    public function district(): BelongsTo { return $this->belongsTo(District::class); }

    public function school(): BelongsTo { return $this->belongsTo(School::class); }

    public function teacher(): BelongsTo { return $this->belongsTo(User::class, 'teacher_id'); }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'classroom_student', 'classroom_id', 'student_id')
            ->withPivot('enrolled_at');
    }

    public function spaces(): HasMany { return $this->hasMany(LearningSpace::class); }
}
