<?php

namespace App\Policies;

use App\Models\Classroom;
use App\Models\User;

class ClassroomPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['teacher', 'school_admin', 'district_admin']);
    }

    public function view(User $user, Classroom $classroom): bool
    {
        return $classroom->teacher_id === $user->id
            || $user->hasRole(['school_admin', 'district_admin']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['teacher', 'school_admin', 'district_admin']);
    }

    public function update(User $user, Classroom $classroom): bool
    {
        return $classroom->teacher_id === $user->id
            || $user->hasRole(['school_admin', 'district_admin']);
    }

    public function delete(User $user, Classroom $classroom): bool
    {
        return $this->update($user, $classroom);
    }
}
