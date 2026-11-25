<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_backup_presence_rules', function (Blueprint $table) {
            $table->dateTime('scheduler_checked_minute_utc')->nullable();
            $table->string('scheduler_revision', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('local_backup_presence_rules', function (Blueprint $table) {
            $table->dropColumn(['scheduler_checked_minute_utc', 'scheduler_revision']);
        });
    }
};
