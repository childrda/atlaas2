<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonAgent extends BaseModel
{
    protected $fillable = [
        'lesson_id', 'district_id', 'role', 'display_name', 'archetype',
        'avatar_emoji', 'color_hex', 'persona_text', 'allowed_actions',
        'priority', 'sequence_order', 'is_active', 'system_prompt_addendum',
    ];

    protected function casts(): array
    {
        return [
            'allowed_actions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('district', function ($query) {
            if (auth()->check()) {
                $query->where('lesson_agents.district_id', auth()->user()->district_id);
            }
        });
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(ClassroomLesson::class, 'lesson_id');
    }

    /**
     * @return list<string>
     */
    public function effectiveActions(string $sceneType): array
    {
        $slideOnly = ['spotlight', 'laser', 'play_video'];
        $all = $this->allowed_actions ?? [];
        if ($sceneType !== 'slide') {
            $all = array_values(array_filter($all, fn ($a) => ! in_array($a, $slideOnly, true)));
        }

        return $all;
    }
}
