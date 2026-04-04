<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('compass.{teacherId}', function (User $user, string $teacherId) {
    if ($user->id === $teacherId) {
        return true;
    }

    if ($user->hasRole(['school_admin', 'district_admin'])) {
        $teacher = User::query()->whereKey($teacherId)->first();

        return $teacher !== null && $teacher->district_id === $user->district_id;
    }

    return false;
});

Broadcast::channel('alerts.{districtId}', function (User $user, string $districtId) {
    return $user->district_id === $districtId
        && $user->hasRole(['school_admin', 'district_admin']);
});

Broadcast::channel('classroom.{sessionId}', function (User $user, string $sessionId) {
    $session = \App\Models\ClassroomSession::query()->whereKey($sessionId)->first();

    return $session !== null
        && $session->district_id === $user->district_id
        && $session->student_id === $user->id;
});
