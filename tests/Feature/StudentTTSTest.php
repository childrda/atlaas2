<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\LearningSpace;
use App\Models\StudentSession;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StudentTTSTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_speak_returns_404_when_tts_disabled(): void
    {
        config(['services.tts.enabled' => false]);

        [$student, $session] = $this->studentWithSession();

        $this->actingAs($student)
            ->postJson(route('student.sessions.speak', $session), ['text' => 'Hello'])
            ->assertNotFound();
    }

    public function test_speak_returns_audio_when_tts_enabled_and_kokoro_ok(): void
    {
        config([
            'services.tts.enabled' => true,
            'services.tts.url' => 'http://kokoro.test',
        ]);

        Http::fake([
            'http://kokoro.test/v1/audio/speech' => Http::response("\x00fake-mp3", 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        [$student, $session] = $this->studentWithSession();

        $this->actingAs($student)
            ->postJson(route('student.sessions.speak', $session), ['text' => 'Hello ATLAAS'])
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg');
    }

    public function test_speak_returns_403_for_other_students_session(): void
    {
        config(['services.tts.enabled' => true]);

        Http::fake([
            '*' => Http::response("\x00x", 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $district = District::create([
            'name' => 'TTS Intruder ISD',
            'slug' => 'tts-intruder-isd',
        ]);

        $teacher = User::create([
            'district_id' => $district->id,
            'name' => 'Teacher',
            'email' => 'teacher-tts-intruder@test.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $teacher->assignRole('teacher');

        $owner = $this->makeStudent('owner-tts@test.test', $district->id);
        $intruder = $this->makeStudent('intruder-tts@test.test', $district->id);

        $space = LearningSpace::withoutGlobalScopes()->create([
            'district_id' => $district->id,
            'teacher_id' => $teacher->id,
            'title' => 'Shared',
            'is_published' => true,
        ]);

        $session = StudentSession::withoutGlobalScopes()->create([
            'district_id' => $district->id,
            'student_id' => $owner->id,
            'space_id' => $space->id,
            'status' => 'active',
        ]);

        $this->actingAs($intruder)
            ->postJson(route('student.sessions.speak', $session), ['text' => 'Hello'])
            ->assertForbidden();
    }

    public function test_speak_returns_403_when_session_not_active_or_completed(): void
    {
        config(['services.tts.enabled' => true]);

        [$student, $session] = $this->studentWithSession();
        $session->update(['status' => 'flagged']);

        $this->actingAs($student)
            ->postJson(route('student.sessions.speak', $session), ['text' => 'Hello'])
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: StudentSession}
     */
    private function studentWithSession(): array
    {
        $district = District::create([
            'name' => 'TTS Test ISD',
            'slug' => 'tts-test-isd',
        ]);

        $teacher = User::create([
            'district_id' => $district->id,
            'name' => 'Teacher',
            'email' => 'teacher-tts@test.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $teacher->assignRole('teacher');

        $student = $this->makeStudent('student-tts@test.test', $district->id);

        $space = LearningSpace::withoutGlobalScopes()->create([
            'district_id' => $district->id,
            'teacher_id' => $teacher->id,
            'title' => 'TTS Space',
            'is_published' => true,
        ]);

        $session = StudentSession::withoutGlobalScopes()->create([
            'district_id' => $district->id,
            'student_id' => $student->id,
            'space_id' => $space->id,
            'status' => 'active',
        ]);

        return [$student, $session];
    }

    private function makeStudent(string $email, ?string $districtId = null): User
    {
        if ($districtId === null) {
            $districtId = District::create([
                'name' => 'Other ISD',
                'slug' => 'other-tts-'.md5($email),
            ])->id;
        }

        $student = User::create([
            'district_id' => $districtId,
            'name' => 'Student',
            'email' => $email,
            'password' => Hash::make('password'),
            'is_active' => true,
            'preferred_language' => 'en',
            'grade_level' => '5',
        ]);
        $student->assignRole('student');

        return $student;
    }
}
