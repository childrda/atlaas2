<?php

namespace App\Models;

use App\Helpers\JoinCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class LearningSpace extends BaseModel
{
    use Searchable, SoftDeletes;

    /**
     * Not sent to student browsers (Inertia). Session chat loads a minimal space column list.
     *
     * @var list<string>
     */
    public const HIDDEN_FROM_STUDENT_CLIENT = [
        'system_prompt',
        'restrictions',
        'allowed_tools',
        'join_code',
    ];

    protected $fillable = [
        'district_id', 'student_mode', 'multi_agent_classroom_enabled', 'teacher_id', 'classroom_id', 'title', 'description',
        'subject', 'grade_level', 'cover_image', 'system_prompt', 'goals',
        'restrictions', 'allowed_tools', 'atlaas_tone', 'language',
        'max_messages', 'require_teacher_present', 'allow_session_restart',
        'is_published', 'is_public', 'is_archived', 'join_code', 'opens_at', 'closes_at',
    ];

    protected function casts(): array
    {
        return [
            'goals' => 'array',
            'restrictions' => 'array',
            'allowed_tools' => 'array',
            'is_published' => 'boolean',
            'is_public' => 'boolean',
            'is_archived' => 'boolean',
            'require_teacher_present' => 'boolean',
            'allow_session_restart' => 'boolean',
            'multi_agent_classroom_enabled' => 'boolean',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LearningSpace $space) {
            if (empty($space->join_code)) {
                $space->join_code = JoinCode::generate('learning_spaces');
            }
            $space->goals ??= [];
            $space->restrictions ??= [];
            $space->allowed_tools ??= [];
        });

        static::addGlobalScope('district', function ($query) {
            if (auth()->check()) {
                $query->where('learning_spaces.district_id', auth()->user()->district_id);
            }
        });
    }

    /**
     * Teacher portal list: spaces owned by the teacher and not archived (district global scope still applies).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTeacherPortal(Builder $query, string $teacherId): Builder
    {
        return $query->where('teacher_id', $teacherId)->where('is_archived', false);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(StudentSession::class, 'space_id');
    }

    public function classroomLessons(): HasMany
    {
        return $this->hasMany(ClassroomLesson::class, 'space_id');
    }

    public function libraryItem(): HasOne
    {
        return $this->hasOne(SpaceLibraryItem::class, 'space_id');
    }

    public function shouldBeSearchable(): bool
    {
        if (! $this->is_published || ! $this->is_public || $this->is_archived) {
            return false;
        }

        return $this->libraryItem()
            ->whereNotNull('published_at')
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'description' => $this->description ?? '',
            'subject' => $this->subject ?? '',
            'is_public' => $this->is_public,
            'is_published' => $this->is_published,
            'is_archived' => $this->is_archived,
        ];
    }
}
