<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_backup_presence_manual_sessions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('rule_id')->unique();
            $table->unsignedBigInteger('requested_at');
            $table->unsignedBigInteger('expires_at')->index();
            $table->unsignedBigInteger('next_observation_at')->nullable();
            $table->enum('status', ['queued', 'pending', 'present', 'absent', 'error'])->default('queued');
            $table->json('continuation')->nullable();
            $table->json('result')->nullable();
            $table->foreign('rule_id')->references('id')->on('local_backup_presence_rules')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_backup_presence_manual_sessions');
    }
};
