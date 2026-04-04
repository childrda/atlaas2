<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\LearningSpace;
use App\Models\SpaceLibraryItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DiscoverDistrictApproveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_district_admin_can_approve_listing_in_their_district(): void
    {
        $district = District::create([
            'name' => 'Test ISD',
            'slug' => 'test-isd',
            'primary_color' => '#111111',
            'accent_color' => '#222222',
        ]);

        $otherDistrict = District::create([
            'name' => 'Other ISD',
            'slug' => 'other-isd',
            'primary_color' => '#111111',
            'accent_color' => '#222222',
        ]);

        $admin = User::create([
            'district_id' => $district->id,
            'name' => 'District Admin',
            'email' => 'dadmin-discover-1@test.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $admin->assignRole('district_admin');

        $foreignAdmin = User::create([
            'district_id' => $otherDistrict->id,
            'name' => 'Foreign Admin',
            'email' => 'foreign-admin-discover@test.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $foreignAdmin->assignRole('district_admin');

        $teacher = User::create([
            'district_id' => $district->id,
            'name' => 'Teacher',
            'email' => 'teacher-discover-1@test.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $teacher->assignRole('teacher');

        $space = LearningSpace::withoutGlobalScope('district')->create([
            'district_id' => $district->id,
            'teacher_id' => $teacher->id,
            'title' => 'Shared Space',
            'subject' => 'Math',
        ]);

        $item = SpaceLibraryItem::create([
            'space_id' => $space->id,
            'title' => $space->title,
            'description' => null,
            'subject' => 'Math',
            'grade_band' => '3-5',
            'tags' => null,
            'published_at' => now(),
            'district_approved' => false,
        ]);

        $this->actingAs($admin)->post(route('teacher.discover.approve', $item))
            ->assertSessionHas('success');

        $this->assertTrue($item->fresh()->district_approved);

        $this->actingAs($foreignAdmin)->post(route('teacher.discover.approve', $item))
            ->assertForbidden();
    }

    public function test_teacher_cannot_approve_listing(): void
    {
        $district = District::create([
            'name' => 'Test ISD',
            'slug' => 'test-isd-2',
            'primary_color' => '#111111',
            'accent_color' => '#222222',
        ]);

        $teacher = User::create([
            'district_id' => $district->id,
            'name' => 'Teacher',
            'email' => 'teacher-discover-2@test.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $teacher->assignRole('teacher');

        $space = LearningSpace::withoutGlobalScope('district')->create([
            'district_id' => $district->id,
            'teacher_id' => $teacher->id,
            'title' => 'My Space',
        ]);

        $item = SpaceLibraryItem::create([
            'space_id' => $space->id,
            'title' => $space->title,
            'published_at' => now(),
        ]);

        $this->actingAs($teacher)->post(route('teacher.discover.approve', $item))
            ->assertForbidden();
    }
}
