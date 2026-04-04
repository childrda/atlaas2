<?php

namespace Database\Seeders;

use App\Models\TeacherTool;
use Illuminate\Database\Seeder;

class BuiltInToolsSeeder extends Seeder
{
    public function run(): void
    {
        $tools = [
            [
                'name' => 'Lesson Planner',
                'slug' => 'lesson-planner',
                'icon' => 'book-open',
                'category' => 'lesson_plan',
                'description' => 'Generate a complete lesson plan in minutes.',
                'system_prompt_template' => <<<'PROMPT'
You are an expert K-12 curriculum designer.
Create a detailed lesson plan for: Subject: {{subject}}, Grade: {{grade_level}},
Objective: {{objective}}, Duration: {{duration}} minutes.
If accommodations are provided below, integrate them into materials, instruction, and assessment.
Accommodations: {{accommodations}}

Format with sections: Learning Objective, Materials, Hook (5 min),
Direct Instruction, Guided Practice, Independent Practice, Closure, Assessment.
PROMPT,
                'input_schema' => [
                    ['name' => 'subject', 'label' => 'Subject', 'type' => 'text', 'required' => true, 'placeholder' => 'e.g. Grade 4 Science'],
                    ['name' => 'grade_level', 'label' => 'Grade Level', 'type' => 'text', 'required' => true, 'placeholder' => 'e.g. Grade 4'],
                    ['name' => 'objective', 'label' => 'Learning Objective', 'type' => 'textarea', 'required' => true, 'placeholder' => 'Students will be able to...'],
                    ['name' => 'duration', 'label' => 'Duration (minutes)', 'type' => 'number', 'required' => true, 'placeholder' => '60'],
                    ['name' => 'accommodations', 'label' => 'Accommodations', 'type' => 'textarea', 'required' => false, 'placeholder' => 'Any IEP, ELL, or other needs (optional)...'],
                ],
            ],
            [
                'name' => 'Rubric Builder',
                'slug' => 'rubric-builder',
                'icon' => 'table',
                'category' => 'rubric',
                'description' => 'Create a detailed grading rubric for any assignment.',
                'system_prompt_template' => 'Create a detailed rubric for: Assignment: {{assignment_type}}, Grade: {{grade_level}}, Subject: {{subject}}. Include {{criteria_count}} criteria and {{performance_levels}} performance levels. Format as a markdown table. Be specific about observable behaviors at each level.',
                'input_schema' => [
                    ['name' => 'assignment_type', 'label' => 'Assignment Type', 'type' => 'text', 'required' => true, 'placeholder' => 'e.g. Persuasive Essay'],
                    ['name' => 'subject', 'label' => 'Subject', 'type' => 'text', 'required' => true],
                    ['name' => 'grade_level', 'label' => 'Grade Level', 'type' => 'text', 'required' => true],
                    ['name' => 'criteria_count', 'label' => 'Number of Criteria', 'type' => 'number', 'required' => true, 'placeholder' => '4'],
                    ['name' => 'performance_levels', 'label' => 'Performance Levels', 'type' => 'number', 'required' => true, 'placeholder' => '4'],
                ],
            ],
            [
                'name' => 'Assessment Generator',
                'slug' => 'assessment-generator',
                'icon' => 'clipboard-check',
                'category' => 'assessment',
                'description' => 'Generate quizzes and assessments with answer keys.',
                'system_prompt_template' => 'Create a {{difficulty}} level assessment on: {{topic}} for Grade {{grade_level}}. Include {{question_count}} questions of these types: {{question_types}}. Include a complete answer key at the end.',
                'input_schema' => [
                    ['name' => 'topic', 'label' => 'Topic', 'type' => 'text', 'required' => true],
                    ['name' => 'grade_level', 'label' => 'Grade Level', 'type' => 'text', 'required' => true],
                    ['name' => 'question_count', 'label' => 'Number of Questions', 'type' => 'number', 'required' => true, 'placeholder' => '10'],
                    ['name' => 'difficulty', 'label' => 'Difficulty', 'type' => 'select', 'required' => true, 'options' => ['Easy', 'Medium', 'Hard', 'Mixed']],
                    ['name' => 'question_types', 'label' => 'Question Types', 'type' => 'checkbox_group', 'required' => true, 'options' => ['Multiple choice', 'Short answer', 'True/false', 'Essay']],
                ],
            ],
            [
                'name' => 'Differentiation Helper',
                'slug' => 'differentiation-helper',
                'icon' => 'users',
                'category' => 'differentiation',
                'description' => 'Adapt any lesson or activity for diverse learners.',
                'system_prompt_template' => "Adapt this lesson or activity for students with these needs: {{student_needs}}. Provide one adapted version per need. Keep the same learning objective.\n\nOriginal activity:\n{{activity_text}}",
                'input_schema' => [
                    ['name' => 'activity_text', 'label' => 'Original Activity', 'type' => 'textarea', 'required' => true, 'placeholder' => 'Paste your lesson or activity here...'],
                    ['name' => 'student_needs', 'label' => 'Student Needs', 'type' => 'checkbox_group', 'required' => true, 'options' => ['English Language Learners (ELL)', 'IEP supports', 'Gifted/Advanced', '504 accommodations']],
                ],
            ],
            [
                'name' => 'Parent Communication Drafter',
                'slug' => 'parent-comms',
                'icon' => 'mail',
                'category' => 'parent_comm',
                'description' => 'Draft professional parent and guardian emails quickly.',
                'system_prompt_template' => 'Draft a {{tone}} email to a parent/guardian. Situation: {{situation_type}}. Context: {{context}}. Be professional, clear, and actionable. Include a subject line.',
                'input_schema' => [
                    ['name' => 'situation_type', 'label' => 'Situation', 'type' => 'select', 'required' => true, 'options' => ['Academic concern', 'Positive recognition', 'Progress update', 'Behavior concern', 'Meeting request']],
                    ['name' => 'context', 'label' => 'Context', 'type' => 'textarea', 'required' => true, 'placeholder' => 'Brief description (avoid student full name if possible)...'],
                    ['name' => 'tone', 'label' => 'Tone', 'type' => 'select', 'required' => true, 'options' => ['Warm and supportive', 'Professional', 'Urgent']],
                ],
            ],
            [
                'name' => 'Feedback Generator',
                'slug' => 'feedback-generator',
                'icon' => 'message-square',
                'category' => 'feedback',
                'description' => 'Generate specific, growth-oriented feedback on student work.',
                'system_prompt_template' => "Write growth-oriented feedback for a Grade {{grade_level}} student. Assignment goals: {{assignment_goals}}.\n\nStudent work:\n{{student_work}}\n\nCelebrate strengths and identify 1-2 clear, specific areas for improvement. Be encouraging.",
                'input_schema' => [
                    ['name' => 'grade_level', 'label' => 'Grade Level', 'type' => 'text', 'required' => true],
                    ['name' => 'assignment_goals', 'label' => 'Assignment Goals', 'type' => 'textarea', 'required' => true, 'placeholder' => 'What was the student trying to accomplish?'],
                    ['name' => 'student_work', 'label' => 'Student Work', 'type' => 'textarea', 'required' => true, 'placeholder' => 'Paste student work here...'],
                ],
            ],
            [
                'name' => 'IEP Accommodation Suggester',
                'slug' => 'iep-accommodations',
                'icon' => 'heart-handshake',
                'category' => 'differentiation',
                'description' => 'Get specific accommodation suggestions by disability category.',
                'system_prompt_template' => 'Suggest 5-8 practical classroom accommodations for a student with {{disability_category}} in a {{subject}} class, Grade {{grade_level}}. Activity type: {{activity_type}}. Each accommodation should be specific and immediately implementable by a classroom teacher.',
                'input_schema' => [
                    ['name' => 'disability_category', 'label' => 'Category', 'type' => 'select', 'required' => true, 'options' => ['ADHD', 'Dyslexia', 'Autism Spectrum', 'Hearing Impairment', 'Visual Impairment', 'Anxiety', 'Processing Disorder', 'Other']],
                    ['name' => 'subject', 'label' => 'Subject', 'type' => 'text', 'required' => true],
                    ['name' => 'grade_level', 'label' => 'Grade Level', 'type' => 'text', 'required' => true],
                    ['name' => 'activity_type', 'label' => 'Activity Type', 'type' => 'text', 'required' => true, 'placeholder' => 'e.g. written test, group discussion, lab activity'],
                ],
            ],
        ];

        foreach ($tools as $tool) {
            TeacherTool::query()->updateOrCreate(
                ['slug' => $tool['slug']],
                array_merge($tool, [
                    'is_built_in' => true,
                    'is_active' => true,
                    'district_id' => null,
                    'created_by' => null,
                ])
            );
        }
    }
}
