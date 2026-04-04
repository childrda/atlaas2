<?php

namespace App\Models;

use App\Traits\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasUuidPrimaryKey, HasRoles, SoftDeletes;

    protected $fillable = [
        'district_id', 'school_id', 'name', 'email', 'password',
        'avatar_url', 'external_id', 'grade_level', 'preferred_language', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'email_verified_at' => 'datetime',
        ];
    }

    public function district(): BelongsTo { return $this->belongsTo(District::class); }

    public function school(): BelongsTo { return $this->belongsTo(School::class); }
}
