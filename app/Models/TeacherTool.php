<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherTool extends BaseModel
{
    protected $fillable = [
        'district_id', 'created_by', 'name', 'slug', 'description',
        'icon', 'category', 'system_prompt_template', 'input_schema',
        'is_built_in', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
            'is_built_in' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ToolRun::class, 'tool_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function isAccessibleByUser(User $user): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->district_id === null) {
            return true;
        }

        return $this->district_id === $user->district_id;
    }
}
