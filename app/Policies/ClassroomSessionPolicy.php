<?php

namespace App\Policies;

use App\Models\ClassroomSession;
use App\Models\User;

class ClassroomSessionPolicy
{
    public function view(User $user, ClassroomSession $session): bool
    {
        if ($session->district_id !== $user->district_id) {
            return false;
        }

        if ($session->student_id === $user->id) {
            return true;
        }

        $session->loadMissing('lesson');

        if ($session->lesson->teacher_id === $user->id) {
            return true;
        }

        return $user->hasRole(['school_admin', 'district_admin']);
    }

    public function update(User $user, ClassroomSession $session): bool
    {
        return $session->district_id === $user->district_id
            && $session->student_id === $user->id;
    }
}
