<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_spaces', function (Blueprint $table) {
            $table->string('student_mode', 32)->default('teacher_session')->after('district_id');
        });

        Schema::create('student_mode_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('district_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('school_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('teacher_session_enabled')->default(true);
            $table->boolean('lms_help_enabled')->default(false);
            $table->boolean('open_tutor_enabled')->default(false);
            $table->string('crisis_counselor_name')->nullable();
            $table->string('crisis_counselor_email')->nullable();
            $table->text('crisis_response_template')->nullable();
            $table->timestamps();

            $table->unique(['district_id', 'school_id']);
        });

        Schema::table('safety_alerts', function (Blueprint $table) {
            $table->string('alert_type', 50)->default('content')->after('severity');
            $table->string('student_mode', 32)->nullable()->after('alert_type');
            $table->boolean('counselor_notified')->default(false)->after('student_mode');
            $table->timestamp('counselor_notified_at')->nullable()->after('counselor_notified');
        });

        Schema::create('lms_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('district_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('lms_provider', 50)->default('manual');
            $table->string('lms_course_id');
            $table->string('course_name');
            $table->string('course_subject')->nullable();
            $table->string('grade_level')->nullable();
            $table->string('teacher_name')->nullable();
            $table->date('enrollment_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['district_id', 'student_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_enrollments');

        Schema::table('safety_alerts', function (Blueprint $table) {
            $table->dropColumn(['alert_type', 'student_mode', 'counselor_notified', 'counselor_notified_at']);
        });

        Schema::dropIfExists('student_mode_settings');

        Schema::table('learning_spaces', function (Blueprint $table) {
            $table->dropColumn('student_mode');
        });
    }
};
