<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_backup_presence_manual_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('dispatch_not_before')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('local_backup_presence_manual_sessions', function (Blueprint $table) {
            $table->dropColumn('dispatch_not_before');
        });
    }
};
