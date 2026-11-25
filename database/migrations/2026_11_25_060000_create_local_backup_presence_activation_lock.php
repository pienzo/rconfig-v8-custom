<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A persistent transaction mutex, not a cache lease. All admission
        // changes must hold this row until their database transaction commits.
        Schema::create('local_backup_presence_activation_lock', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('local_backup_presence_activation_lock')->insert(['id' => 1]);

        Schema::table('local_backup_presence_rules', function (Blueprint $table) {
            $table->index(['enabled', 'id'], 'local_presence_enabled_idx');
        });
    }

    public function down(): void
    {
        Schema::table('local_backup_presence_rules', function (Blueprint $table) {
            $table->dropIndex('local_presence_enabled_idx');
        });
        Schema::dropIfExists('local_backup_presence_activation_lock');
    }
};
