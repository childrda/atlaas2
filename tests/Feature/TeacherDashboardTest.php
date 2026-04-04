<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\LearningSpace;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeacherDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_teacher_dashboard_active_spaces_matches_my_spaces_query(): void
    {
        $district = District::create([
            'name' => 'Test ISD',
            'slug' => 'test-isd-dash',
            'primary_color' => '#111111',
            'accent_color' => '#222222',
        ]);

        $teacher = User::create([
            'district_id' => $district->id,
            'name' => 'Ms. Test',
            'email' => 'teacher-dash@test.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $teacher->assignRole('teacher');

        LearningSpace::withoutGlobalScope('district')->create([
            'district_id' => $district->id,
            'teacher_id' => $teacher->id,
            'title' => 'The Water Cycle',
            'subject' => 'Science',
            'is_archived' => false,
        ]);

        $this->actingAs($teacher)
            ->get(route('teacher.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Teacher/Dashboard')
                ->where('activeSpacesCount', 1)
                ->where('activeStudentsCount', 0)
                ->where('openAlertsCount', 0));
    }
}
