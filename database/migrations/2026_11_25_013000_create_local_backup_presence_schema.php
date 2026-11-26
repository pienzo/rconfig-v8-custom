<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A separate metadata-presence ledger: never a verified-file receipt.
 * Core task/device primary keys are signed INTEGERs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_backup_presence_rules', function (Blueprint $table) {
            $table->id();
            $table->integer('task_id');
            $table->integer('device_id');
            $table->string('directory_alias', 64);
            $table->string('relative_directory', 4095)->default('');
            $table->string('pattern_mode', 8);
            $table->string('filename_pattern', 255);
            $table->unsignedBigInteger('minimum_bytes')->default(1);
            $table->unsignedInteger('maximum_age_seconds')->nullable()->default(86400);
            $table->unsignedSmallInteger('quiet_seconds')->nullable()->default(30);
            $table->unsignedInteger('deadline_seconds')->default(3600);
            $table->unsignedSmallInteger('max_entries')->default(1000);
            $table->unsignedSmallInteger('max_matches')->default(100);
            $table->unsignedSmallInteger('max_scan_millis')->default(2000);
            $table->boolean('enabled')->default(false);
            $table->dateTime('activated_at_utc')->nullable();
            $table->timestamps();

            $table->foreign('task_id')->references('id')->on('tasks')->restrictOnDelete();
            $table->foreign('device_id')->references('id')->on('devices')->restrictOnDelete();
            $table->unique('task_id', 'local_presence_one_rule_per_task');
            $table->unique(['id', 'device_id'], 'local_presence_rule_device_unique');
        });

        Schema::create('local_backup_presence_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rule_id');
            $table->integer('device_id');
            $table->dateTime('scheduled_at_utc');
            $table->dateTime('deadline_at_utc');
            $table->enum('status', ['pending', 'present', 'absent', 'error'])->default('pending');
            $table->string('reason', 64)->nullable();
            $table->dateTime('first_observed_at_utc')->nullable();
            $table->dateTime('last_observed_at_utc')->nullable();
            $table->string('matched_filename', 255)->nullable();
            $table->timestamps();

            $table->foreign(['rule_id', 'device_id'], 'local_presence_run_rule_device_fk')
                ->references(['id', 'device_id'])->on('local_backup_presence_rules')
                ->restrictOnDelete();
            $table->unique(['rule_id', 'scheduled_at_utc'], 'local_presence_occurrence_unique');
            $table->index(['device_id', 'status', 'scheduled_at_utc'], 'local_presence_device_status_idx');
        });

        // Core uses non-strict SQL mode: invalid ENUM input can become the
        // special empty ENUM value. Enforce the allowed domain independently
        // of connection SQL mode on supported MySQL/MariaDB versions.
        DB::statement("ALTER TABLE local_backup_presence_runs ADD CONSTRAINT local_presence_valid_status CHECK (status IN ('pending', 'present', 'absent', 'error'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('local_backup_presence_runs');
        Schema::dropIfExists('local_backup_presence_rules');
    }
};
