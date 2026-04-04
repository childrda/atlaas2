<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safety_alerts', function (Blueprint $table) {
            $table->foreignUuid('classroom_session_id')
                ->nullable()
                ->after('session_id')
                ->constrained('classroom_sessions')
                ->nullOnDelete();
        });

        Schema::table('safety_alerts', function (Blueprint $table) {
            $table->dropForeign(['session_id']);
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE safety_alerts MODIFY session_id CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE safety_alerts ALTER COLUMN session_id DROP NOT NULL');
        }

        Schema::table('safety_alerts', function (Blueprint $table) {
            $table->foreign('session_id')->references('id')->on('student_sessions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('safety_alerts')->whereNotNull('classroom_session_id')->delete();

        Schema::table('safety_alerts', function (Blueprint $table) {
            $table->dropForeign(['classroom_session_id']);
            $table->dropColumn('classroom_session_id');
        });

        Schema::table('safety_alerts', function (Blueprint $table) {
            $table->dropForeign(['session_id']);
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE safety_alerts MODIFY session_id CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE safety_alerts ALTER COLUMN session_id SET NOT NULL');
        }

        Schema::table('safety_alerts', function (Blueprint $table) {
            $table->foreign('session_id')->references('id')->on('student_sessions')->cascadeOnDelete();
        });
    }
};
