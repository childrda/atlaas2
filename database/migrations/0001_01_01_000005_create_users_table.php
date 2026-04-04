<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('district_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('school_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable(); // null = SSO-only account
            $table->string('avatar_url')->nullable();
            $table->string('external_id')->nullable(); // Clever/Google user ID
            $table->string('grade_level')->nullable();
            $table->string('preferred_language', 10)->default('en');
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['district_id', 'email']); // scoped login lookups
            $table->index(['district_id', 'school_id']); // used heavily in Phase 2+ roster queries
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
