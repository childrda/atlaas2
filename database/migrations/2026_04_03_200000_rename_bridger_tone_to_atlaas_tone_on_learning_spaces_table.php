<?php

/*
| Up: rename legacy DB column `bridger_tone` (historic codename) to `atlaas_tone`.
| Down: restores the original column name for rollback — not a product string; do not “rebrand” it.
*/

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('learning_spaces')) {
            return;
        }

        if (Schema::hasColumn('learning_spaces', 'bridger_tone') && ! Schema::hasColumn('learning_spaces', 'atlaas_tone')) {
            Schema::table('learning_spaces', function (Blueprint $table) {
                $table->renameColumn('bridger_tone', 'atlaas_tone');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('learning_spaces')) {
            return;
        }

        if (Schema::hasColumn('learning_spaces', 'atlaas_tone') && ! Schema::hasColumn('learning_spaces', 'bridger_tone')) {
            Schema::table('learning_spaces', function (Blueprint $table) {
                $table->renameColumn('atlaas_tone', 'bridger_tone');
            });
        }
    }
};
