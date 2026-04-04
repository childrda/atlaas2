<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends BaseModel
{
    protected $fillable = [
        'name', 'slug', 'logo_url', 'primary_color',
        'accent_color', 'sso_provider', 'allow_self_registration',
    ];

    protected function casts(): array
    {
        return ['allow_self_registration' => 'boolean'];
    }

    public function schools(): HasMany { return $this->hasMany(School::class); }

    public function users(): HasMany { return $this->hasMany(User::class); }
}
