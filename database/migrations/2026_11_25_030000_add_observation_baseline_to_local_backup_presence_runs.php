<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_backup_presence_runs', function (Blueprint $table) {
            $table->string('directory_identity', 64)->nullable();
            $table->json('stability_baseline')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('local_backup_presence_runs', function (Blueprint $table) {
            $table->dropColumn(['directory_identity', 'stability_baseline']);
        });
    }
};
