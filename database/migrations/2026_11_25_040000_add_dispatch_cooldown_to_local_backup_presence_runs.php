<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_backup_presence_runs', function (Blueprint $table) {
            $table->dateTime('dispatch_not_before_utc')->nullable();
            $table->index(['status', 'dispatch_not_before_utc', 'scheduled_at_utc'], 'local_presence_dispatch_idx');
        });
    }

    public function down(): void
    {
        Schema::table('local_backup_presence_runs', function (Blueprint $table) {
            $table->dropIndex('local_presence_dispatch_idx');
            $table->dropColumn('dispatch_not_before_utc');
        });
    }
};
