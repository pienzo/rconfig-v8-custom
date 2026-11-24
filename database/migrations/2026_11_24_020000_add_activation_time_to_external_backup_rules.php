<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_backup_rules', function (Blueprint $table) {
            $table->dateTime('activated_at_utc')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('external_backup_rules', function (Blueprint $table) {
            $table->dropColumn('activated_at_utc');
        });
    }
};
