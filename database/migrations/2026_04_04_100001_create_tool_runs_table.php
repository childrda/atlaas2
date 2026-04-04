<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('tool_id')->constrained('teacher_tools')->cascadeOnDelete();
            $table->json('inputs')->nullable();
            $table->text('output')->nullable();
            $table->integer('tokens_used')->default(0);
            $table->timestamps();
            $table->index(['teacher_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_runs');
    }
};
