<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('student_sessions')->cascadeOnDelete();
            $table->foreignUuid('district_id')->constrained()->cascadeOnDelete();
            $table->string('role'); // user|assistant|system|teacher_inject
            $table->text('content');
            $table->boolean('flagged')->default(false);
            $table->string('flag_reason')->nullable();
            $table->string('flag_category')->nullable();
            $table->integer('tokens')->default(0);
            $table->timestamps();
            $table->index(['session_id', 'created_at']);
            $table->index(['district_id', 'flagged']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
