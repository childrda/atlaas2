<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends BaseModel
{
    protected $fillable = ['district_id', 'name'];

    public function district(): BelongsTo { return $this->belongsTo(District::class); }

    public function users(): HasMany { return $this->hasMany(User::class); }
}
