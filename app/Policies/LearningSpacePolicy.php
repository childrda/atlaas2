<?php

namespace App\Policies;

use App\Models\LearningSpace;
use App\Models\User;

class LearningSpacePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['teacher', 'school_admin', 'district_admin']);
    }

    public function view(User $user, LearningSpace $space): bool
    {
        return $space->teacher_id === $user->id
            || $user->hasRole(['school_admin', 'district_admin']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['teacher', 'school_admin', 'district_admin']);
    }

    public function update(User $user, LearningSpace $space): bool
    {
        return $space->teacher_id === $user->id
            || $user->hasRole(['school_admin', 'district_admin']);
    }

    public function delete(User $user, LearningSpace $space): bool
    {
        return $this->update($user, $space);
    }
}
