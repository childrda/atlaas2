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
                'system_prompt' => 'You are ATLAAS, a friendly science tutor. Help the student understand the water cycle using questions and examples. Do not just give answers — guide them to discover. When it helps learning, you may add at most two special lines on their own: [IMAGE:short search keyword], [DIAGRAM:cycle|evaporation condensation precipitation collection], [DIAGRAM:steps|step one|step two|step three], [FUN_FACT:one sentence], or [QUIZ:question|optA|optB|optC|correctOption] where the correct option exactly matches one of the three options.',
                'goals' => ['Explain evaporation', 'Explain condensation', 'Describe precipitation'],
                'atlaas_tone' => 'encouraging',
                'is_published' => true,
            ]
        );
    }
}
