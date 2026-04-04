<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('space_library_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('space_id')->constrained('learning_spaces')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('subject')->nullable();
            $table->string('grade_band')->nullable();
            $table->json('tags')->nullable();
            $table->integer('download_count')->default(0);
            $table->decimal('rating', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->boolean('district_approved')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['subject', 'grade_band']);
            $table->index('download_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_library_items');
    }
};
