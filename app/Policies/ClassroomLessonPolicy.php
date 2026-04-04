<?php

namespace App\Policies;

use App\Models\ClassroomLesson;
use App\Models\User;

class ClassroomLessonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['teacher', 'school_admin', 'district_admin']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['teacher', 'school_admin', 'district_admin']);
    }

    public function view(User $user, ClassroomLesson $lesson): bool
    {
        if ($lesson->district_id !== $user->district_id) {
            return false;
        }

        return $lesson->teacher_id === $user->id
            || $user->hasRole(['school_admin', 'district_admin']);
    }

    public function update(User $user, ClassroomLesson $lesson): bool
    {
        return $this->view($user, $lesson);
    }

    public function delete(User $user, ClassroomLesson $lesson): bool
    {
        return $this->update($user, $lesson);
    }
}
