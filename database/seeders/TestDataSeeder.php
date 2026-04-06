<?php

namespace Database\Seeders;

use App\Models\Classroom;
use App\Models\District;
use App\Models\LearningSpace;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        $district = District::firstOrCreate(
            ['slug' => 'demo-division'],
            [
                'name' => 'Demo School Division',
                'sso_provider' => 'local',
            ]
        );

        $school = School::firstOrCreate(
            [
                'district_id' => $district->id,
                'name' => 'Riverside Elementary',
            ]
        );

        $admin = User::updateOrCreate(
            ['email' => 'admin@demo.test'],
            [
                'district_id' => $district->id,
                'school_id' => null,
                'name' => 'District Admin',
                'password' => Hash::make('password'),
            ]
        );
        $admin->assignRole('district_admin');

        $teacher = User::updateOrCreate(
            ['email' => 'teacher@demo.test'],
            [
                'district_id' => $district->id,
                'school_id' => $school->id,
                'name' => 'Ms. Taylor',
                'password' => Hash::make('password'),
            ]
        );
        $teacher->assignRole('teacher');

        $student = User::updateOrCreate(
            ['email' => 'student@demo.test'],
            [
                'district_id' => $district->id,
                'school_id' => $school->id,
                'name' => 'Alex Student',
                'password' => Hash::make('password'),
                'grade_level' => '5',
            ]
        );
        $student->assignRole('student');

        $classroom = Classroom::withoutGlobalScopes()->firstOrCreate(
            [
                'teacher_id' => $teacher->id,
                'name' => 'Grade 5 Science',
            ],
            [
                'district_id' => $district->id,
                'school_id' => $school->id,
                'subject' => 'Science',
                'grade_level' => '5',
            ]
        );

        if (! $classroom->students()->where('users.id', $student->id)->exists()) {
            $classroom->students()->attach($student->id, ['enrolled_at' => now()]);
        }

        LearningSpace::withoutGlobalScopes()->firstOrCreate(
            [
                'teacher_id' => $teacher->id,
                'title' => 'The Water Cycle',
            ],
            [
                'district_id' => $district->id,
                'classroom_id' => $classroom->id,
                'description' => 'Explore how water moves through the environment.',
                'subject' => 'Science',
                'grade_level' => '5',
                'system_prompt' => <<<'PROMPT'
You are ATLAAS, a friendly science tutor. Help the student understand the water cycle using questions and examples. Do not just give answers — guide them to discover.

If the student asks to see, show, draw, or create a picture, diagram, photo, or illustration of the water cycle (or any part of it), you MUST output at least one display tag on its own line in the same reply. The app shows licensed educational images and diagrams from that tag — never say you cannot show or create images.

Use at most two tag lines per reply. Each tag must be its own line (not inside a sentence):
[IMAGE:short search keyword]
[DIAGRAM:cycle|evaporation, condensation, precipitation, collection]
[DIAGRAM:steps|evaporation, condensation, precipitation, collection]
[FUN_FACT:one sentence]
[QUIZ:question|option A|option B|option C|correctOption] — correctOption must exactly match one of the three options.

For other messages, add tags only when they clearly help learning.
PROMPT,
                'goals' => ['Explain evaporation', 'Explain condensation', 'Describe precipitation'],
                'atlaas_tone' => 'encouraging',
                'is_published' => true,
            ]
        );
    }
}
