<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpaceLibraryItem extends BaseModel
{
    protected $fillable = [
        'space_id', 'title', 'description', 'subject',
        'grade_band', 'tags', 'download_count', 'rating', 'rating_count',
        'district_approved', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'district_approved' => 'boolean',
            'published_at' => 'datetime',
            'rating' => 'decimal:2',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(LearningSpace::class, 'space_id');
    }
}
