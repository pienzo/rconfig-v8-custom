<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_native_backup_outcomes', function (Blueprint $table) {
            $table->id();
            // No cascade to config retention: the outcome is a historical fact.
            $table->integer('source_config_id')->unique();
            $table->integer('device_id');
            $table->string('device_name', 255);
            $table->string('event_type', 32);
            $table->string('filename', 255)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->dateTime('occurred_at_utc');
            $table->index(['occurred_at_utc', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_native_backup_outcomes');
    }
};
