<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classroom_lessons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('district_id');
            $table->uuid('teacher_id');
            $table->uuid('space_id')->nullable();
            $table->string('title');
            $table->string('subject')->nullable();
            $table->string('grade_level')->nullable();
            $table->string('language', 10)->default('en');
            $table->enum('source_type', ['topic', 'pdf', 'standard'])->default('topic');
            $table->longText('source_text')->nullable();
            $table->string('source_file_path')->nullable();
            $table->string('generation_job_id')->nullable();
            $table->enum('generation_status', [
                'pending', 'generating_outline', 'generating_scenes',
                'generating_media', 'completed', 'failed',
            ])->default('pending');
            $table->jsonb('generation_progress')->nullable();
            $table->jsonb('outline')->nullable();
            $table->enum('agent_mode', ['default', 'custom'])->default('default');
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('district_id')->references('id')->on('districts');
            $table->foreign('teacher_id')->references('id')->on('users');
            $table->foreign('space_id')->references('id')->on('learning_spaces')->nullOnDelete();
            $table->index(['district_id', 'teacher_id', 'status']);
        });

        Schema::create('lesson_agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('lesson_id');
            $table->uuid('district_id');
            $table->enum('role', ['teacher', 'assistant', 'student']);
            $table->string('display_name');
            $table->string('archetype');
            $table->string('avatar_emoji', 32)->default('🤖');
            $table->string('color_hex', 16)->default('#1E3A5F');
            $table->text('persona_text');
            $table->jsonb('allowed_actions');
            $table->unsignedSmallInteger('priority')->default(5);
            $table->unsignedSmallInteger('sequence_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('system_prompt_addendum')->nullable();
            $table->timestamps();
            $table->foreign('lesson_id')->references('id')->on('classroom_lessons')->cascadeOnDelete();
            $table->foreign('district_id')->references('id')->on('districts');
            $table->index(['lesson_id', 'sequence_order']);
        });

        Schema::create('lesson_scenes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('lesson_id');
            $table->uuid('district_id');
            $table->unsignedSmallInteger('sequence_order');
            $table->enum('scene_type', ['slide', 'quiz', 'interactive', 'pbl', 'discussion']);
            $table->string('title');
            $table->string('learning_objective')->nullable();
            $table->unsignedInteger('estimated_duration_seconds')->default(120);
            $table->jsonb('outline_data')->nullable();
            $table->jsonb('content')->nullable();
            $table->jsonb('actions')->nullable();
            $table->enum('generation_status', ['pending', 'generating', 'ready', 'error'])->default('pending');
            $table->string('generation_error')->nullable();
            $table->timestamps();
            $table->foreign('lesson_id')->references('id')->on('classroom_lessons')->cascadeOnDelete();
            $table->foreign('district_id')->references('id')->on('districts');
            $table->index(['lesson_id', 'sequence_order']);
        });

        Schema::create('classroom_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('district_id');
            $table->uuid('lesson_id');
            $table->uuid('student_id');
            $table->uuid('current_scene_id')->nullable();
            $table->unsignedSmallInteger('current_scene_action_index')->default(0);
            $table->jsonb('director_state')->nullable();
            $table->jsonb('whiteboard_elements')->nullable();
            $table->boolean('whiteboard_open')->default(false);
            $table->enum('session_type', ['lecture', 'qa', 'discussion'])->default('lecture');
            $table->enum('status', ['active', 'completed', 'abandoned'])->default('active');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->text('student_summary')->nullable();
            $table->text('teacher_summary')->nullable();
            $table->timestamps();
            $table->foreign('district_id')->references('id')->on('districts');
            $table->foreign('lesson_id')->references('id')->on('classroom_lessons');
            $table->foreign('student_id')->references('id')->on('users');
            $table->foreign('current_scene_id')->references('id')->on('lesson_scenes')->nullOnDelete();
            $table->index(['district_id', 'lesson_id', 'status']);
            $table->index(['student_id', 'started_at']);
        });

        Schema::create('classroom_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id');
            $table->uuid('district_id');
            $table->enum('sender_type', ['student', 'agent', 'system']);
            $table->uuid('agent_id')->nullable();
            $table->text('content_text')->nullable();
            $table->jsonb('actions_json')->nullable();
            $table->boolean('flagged')->default(false);
            $table->string('flag_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('session_id')->references('id')->on('classroom_sessions')->cascadeOnDelete();
            $table->foreign('district_id')->references('id')->on('districts');
            $table->foreign('agent_id')->references('id')->on('lesson_agents')->nullOnDelete();
            $table->index(['session_id', 'created_at']);
            $table->index(['district_id', 'flagged']);
        });

        Schema::create('lesson_quiz_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id');
            $table->uuid('district_id');
            $table->uuid('scene_id');
            $table->unsignedSmallInteger('question_index');
            $table->enum('question_type', ['single', 'multiple', 'short_answer']);
            $table->jsonb('student_answer');
            $table->boolean('is_correct')->nullable();
            $table->decimal('score', 4, 2)->default(0);
            $table->decimal('max_score', 4, 2)->default(1);
            $table->text('llm_feedback')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();
            $table->foreign('session_id')->references('id')->on('classroom_sessions')->cascadeOnDelete();
            $table->foreign('district_id')->references('id')->on('districts');
            $table->foreign('scene_id')->references('id')->on('lesson_scenes')->cascadeOnDelete();
            $table->index(['session_id', 'scene_id']);
        });

        Schema::create('whiteboard_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id');
            $table->uuid('scene_id');
            $table->jsonb('elements');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('session_id')->references('id')->on('classroom_sessions')->cascadeOnDelete();
            $table->foreign('scene_id')->references('id')->on('lesson_scenes')->cascadeOnDelete();
            $table->index(['session_id', 'scene_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whiteboard_snapshots');
        Schema::dropIfExists('lesson_quiz_attempts');
        Schema::dropIfExists('classroom_messages');
        Schema::dropIfExists('classroom_sessions');
        Schema::dropIfExists('lesson_scenes');
        Schema::dropIfExists('lesson_agents');
        Schema::dropIfExists('classroom_lessons');
    }
};
