<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_spaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('district_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('subject')->nullable();
            $table->string('grade_level')->nullable();
            $table->string('cover_image')->nullable();
            $table->text('system_prompt')->nullable();
            // MySQL/MariaDB: JSON columns cannot use DEFAULT; defaults set in LearningSpace model.
            $table->json('goals')->nullable();
            $table->json('restrictions')->nullable();
            $table->json('allowed_tools')->nullable();
            $table->string('atlaas_tone')->default('encouraging'); // encouraging|socratic|direct|playful
            $table->string('language', 10)->default('en');
            $table->integer('max_messages')->nullable();
            $table->boolean('require_teacher_present')->default(false);
            $table->boolean('allow_session_restart')->default(true);
            $table->boolean('is_published')->default(false);
            $table->boolean('is_public')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->string('join_code', 8)->unique();
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['district_id', 'is_archived']);
            $table->index(['teacher_id', 'is_archived']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_spaces');
    }
};
