<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('district_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('space_id')->constrained('learning_spaces')->cascadeOnDelete();
            $table->string('status')->default('active'); // active|completed|flagged|abandoned
            $table->integer('message_count')->default(0);
            $table->integer('tokens_used')->default(0);
            $table->text('student_summary')->nullable();
            $table->text('teacher_summary')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['space_id', 'status']);
            $table->index(['student_id', 'started_at']);
            $table->index(['district_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_sessions');
    }
};
