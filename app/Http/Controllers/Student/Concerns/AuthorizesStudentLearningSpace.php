<?php

namespace App\Http\Controllers\Student\Concerns;

use App\Models\LearningSpace;
use App\Models\User;

trait AuthorizesStudentLearningSpace
{
    protected function authorizeStudentLearningSpace(User $user, LearningSpace $space): void
    {
        abort_unless($space->district_id === $user->district_id, 403);
        abort_unless($space->is_published && ! $space->is_archived, 403);

        if ($space->classroom_id) {
            abort_unless(
                $space->classroom->students()->where('users.id', $user->id)->exists(),
                403
            );
        }
    }
}
