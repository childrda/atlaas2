<?php

namespace Tests\Feature\Classroom;

use App\Models\ClassroomLesson;
use App\Models\ClassroomSession;
use App\Models\LessonScene;
use App\Models\District;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClassroomAdvanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return array{0: District, 1: User, 2: User}
     */
    private function fixtures(): array
    {
        $district = District::create([
            'name' => 'S ISD',
            'slug' => 's-isd-'.uniqid(),
            'primary_color' => '#111111',
            'accent_color' => '#222222',
        ]);
        $teacher = User::create([
            'district_id' => $district->id,
            'name' => 'Teacher',
            'email' => 'teach'.uniqid().'@x.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $teacher->assignRole('teacher');

        $student = User::create([
            'district_id' => $district->id,
            'name' => 'Student',
            'email' => 'stu'.uniqid().'@x.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $student->assignRole('student');

        return [$district, $teacher, $student];
    }

    public function test_student_can_advance_to_next_scene(): void
    {
        [$district, $teacher, $student] = $this->fixtures();

        $lesson = ClassroomLesson::create([
            'district_id' => $district->id,
            'teacher_id' => $teacher->id,
            'title' => 'L',
            'generation_status' => 'completed',
            'status' => 'published',
            'language' => 'en',
            'source_type' => 'topic',
        ]);

        $s1 = LessonScene::create([
            'lesson_id' => $lesson->id,
            'district_id' => $district->id,
            'sequence_order' => 0,
            'scene_type' => 'slide',
            'title' => 'One',
            'estimated_duration_seconds' => 120,
            'content' => ['elements' => []],
            'generation_status' => 'ready',
        ]);
        $s2 = LessonScene::create([
            'lesson_id' => $lesson->id,
            'district_id' => $district->id,
            'sequence_order' => 1,
            'scene_type' => 'slide',
            'title' => 'Two',
            'estimated_duration_seconds' => 120,
            'content' => ['elements' => []],
            'generation_status' => 'ready',
        ]);

        $session = ClassroomSession::create([
            'district_id' => $district->id,
            'lesson_id' => $lesson->id,
            'student_id' => $student->id,
            'current_scene_id' => $s1->id,
            'director_state' => [
                'turn_count' => 0,
                'rounds_without_input' => 0,
                'agents_spoken' => [],
                'whiteboard_ledger' => [],
            ],
            'whiteboard_elements' => [],
            'whiteboard_open' => false,
            'status' => 'active',
        ]);

        $this->actingAs($student)
            ->postJson(route('student.classroom.advance', $session))
            ->assertOk()
            ->assertJsonPath('current_scene_id', $s2->id)
            ->assertJsonPath('lesson_complete', false);

        $this->assertSame($s2->id, $session->fresh()->current_scene_id);
    }

    public function test_other_student_cannot_advance_session(): void
    {
        [$district, $teacher, $student] = $this->fixtures();

        $intruder = User::create([
            'district_id' => $district->id,
            'name' => 'Other',
            'email' => 'oth'.uniqid().'@x.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $intruder->assignRole('student');

        $lesson = ClassroomLesson::create([
            'district_id' => $district->id,
            'teacher_id' => $teacher->id,
            'title' => 'L',
            'generation_status' => 'completed',
            'status' => 'published',
            'language' => 'en',
            'source_type' => 'topic',
        ]);

        $s1 = LessonScene::create([
            'lesson_id' => $lesson->id,
            'district_id' => $district->id,
            'sequence_order' => 0,
            'scene_type' => 'slide',
            'title' => 'One',
            'estimated_duration_seconds' => 120,
            'content' => ['elements' => []],
            'generation_status' => 'ready',
        ]);

        $session = ClassroomSession::create([
            'district_id' => $district->id,
            'lesson_id' => $lesson->id,
            'student_id' => $student->id,
            'current_scene_id' => $s1->id,
            'director_state' => [
                'turn_count' => 0,
                'rounds_without_input' => 0,
                'agents_spoken' => [],
                'whiteboard_ledger' => [],
            ],
            'whiteboard_elements' => [],
            'whiteboard_open' => false,
            'status' => 'active',
        ]);

        $this->actingAs($intruder)
            ->postJson(route('student.classroom.advance', $session))
            ->assertForbidden();
    }
}
