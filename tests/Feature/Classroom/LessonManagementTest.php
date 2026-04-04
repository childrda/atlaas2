<?php

namespace Tests\Feature\Classroom;

use App\Models\ClassroomLesson;
use App\Models\LessonAgent;
use App\Models\LessonScene;
use App\Models\District;
use App\Models\User;
use App\Services\Classroom\AgentArchetypes;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LessonManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return array{0: District, 1: User}
     */
    private function districtAndTeacher(): array
    {
        $district = District::create([
            'name' => 'T ISD',
            'slug' => 't-isd-'.uniqid(),
            'primary_color' => '#111111',
            'accent_color' => '#222222',
        ]);
        $teacher = User::create([
            'district_id' => $district->id,
            'name' => 'Teacher',
            'email' => 't'.uniqid().'@x.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $teacher->assignRole('teacher');

        return [$district, $teacher];
    }

    public function test_teacher_can_patch_lesson_and_agent_addendum_is_sanitized(): void
    {
        [$district, $teacher] = $this->districtAndTeacher();

        $lesson = ClassroomLesson::create([
            'district_id' => $district->id,
            'teacher_id' => $teacher->id,
            'title' => 'Original',
            'generation_status' => 'completed',
            'status' => 'draft',
            'language' => 'en',
            'source_type' => 'topic',
        ]);

        $agent = LessonAgent::create([
            'lesson_id' => $lesson->id,
            'district_id' => $district->id,
            'role' => 'teacher',
            'display_name' => 'Coach',
            'archetype' => 'teacher',
            'avatar_emoji' => '👩‍🏫',
            'color_hex' => '#1E3A5F',
            'persona_text' => 'Test',
            'allowed_actions' => AgentArchetypes::ROLE_ACTIONS['teacher'],
            'priority' => 10,
            'sequence_order' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($teacher)
            ->patch(route('teacher.lessons.update', $lesson), [
                'title' => 'Updated title',
                'status' => 'draft',
            ])
            ->assertRedirect();

        $lesson->refresh();
        $this->assertSame('Updated title', $lesson->title);

        $this->actingAs($teacher)
            ->patch(route('teacher.lessons.agents.update', [$lesson, $agent]), [
                'system_prompt_addendum' => '<script>evil()</script>Stay on topic.',
                'display_name' => 'Coach',
                'is_active' => true,
            ])
            ->assertRedirect();

        $agent->refresh();
        $this->assertStringNotContainsString('<script>', $agent->system_prompt_addendum ?? '');
        $this->assertStringContainsString('Stay on topic', $agent->system_prompt_addendum ?? '');
    }

    public function test_teacher_can_download_html_export(): void
    {
        [$district, $teacher] = $this->districtAndTeacher();

        $lesson = ClassroomLesson::create([
            'district_id' => $district->id,
            'teacher_id' => $teacher->id,
            'title' => 'Export Me',
            'generation_status' => 'completed',
            'status' => 'draft',
            'language' => 'en',
            'source_type' => 'topic',
        ]);

        LessonScene::create([
            'lesson_id' => $lesson->id,
            'district_id' => $district->id,
            'sequence_order' => 0,
            'scene_type' => 'slide',
            'title' => 'Intro',
            'estimated_duration_seconds' => 120,
            'content' => ['elements' => []],
            'generation_status' => 'ready',
        ]);

        $this->actingAs($teacher)
            ->get(route('teacher.lessons.export', $lesson).'?format=html')
            ->assertOk()
            ->assertHeader('content-disposition')
            ->assertSee('Export Me', false);
    }

    public function test_teacher_can_reorder_scenes(): void
    {
        [$district, $teacher] = $this->districtAndTeacher();

        $lesson = ClassroomLesson::create([
            'district_id' => $district->id,
            'teacher_id' => $teacher->id,
            'title' => 'Reorder',
            'generation_status' => 'completed',
            'status' => 'draft',
            'language' => 'en',
            'source_type' => 'topic',
        ]);

        $a = LessonScene::create([
            'lesson_id' => $lesson->id,
            'district_id' => $district->id,
            'sequence_order' => 0,
            'scene_type' => 'slide',
            'title' => 'First',
            'estimated_duration_seconds' => 120,
            'content' => ['elements' => []],
            'generation_status' => 'ready',
        ]);
        $b = LessonScene::create([
            'lesson_id' => $lesson->id,
            'district_id' => $district->id,
            'sequence_order' => 1,
            'scene_type' => 'slide',
            'title' => 'Second',
            'estimated_duration_seconds' => 120,
            'content' => ['elements' => []],
            'generation_status' => 'ready',
        ]);

        $this->actingAs($teacher)
            ->patch(route('teacher.lessons.scenes.reorder', $lesson), [
                'scene_ids' => [$b->id, $a->id],
            ])
            ->assertRedirect();

        $this->assertSame(0, $b->fresh()->sequence_order);
        $this->assertSame(1, $a->fresh()->sequence_order);
    }
}
