<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F02 metadata-only persistence. No check is scheduled or marked successful here.
 *
 * Core tasks/devices use signed INTEGER primary keys. The three new tables
 * have BIGINT primary keys and matching types for each foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_backup_rules', function (Blueprint $table) {
            $table->id();
            $table->integer('task_id');
            $table->integer('device_id');
            $table->string('pattern_mode', 8);
            $table->string('filename_pattern', 255);
            $table->unsignedBigInteger('minimum_bytes')->default(1);
            $table->unsignedSmallInteger('quiet_seconds')->default(30);
            $table->unsignedInteger('deadline_seconds')->default(3600);
            $table->unsignedSmallInteger('clock_skew_seconds')->default(0);
            $table->unsignedSmallInteger('max_entries')->default(1000);
            $table->unsignedSmallInteger('max_matches')->default(100);
            $table->unsignedSmallInteger('max_scan_millis')->default(2000);
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->foreign('task_id')->references('id')->on('tasks')->restrictOnDelete();
            $table->foreign('device_id')->references('id')->on('devices')->restrictOnDelete();
            $table->unique(['id', 'device_id'], 'ext_bkp_rule_id_device_unique');
            $table->index(['task_id', 'device_id'], 'ext_bkp_rule_task_device_idx');
        });

        Schema::create('external_backup_check_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rule_id');
            $table->integer('device_id');
            $table->dateTime('scheduled_at_utc');
            $table->dateTime('deadline_at_utc');
            $table->string('status', 48)->default('pending');
            $table->string('reason', 64)->nullable();
            $table->dateTime('first_observed_at_utc')->nullable();
            $table->dateTime('last_observed_at_utc')->nullable();
            $table->string('matched_filename', 255)->nullable();
            $table->timestamps();

            // The extra device_id is checked against the rule, not merely
            // trusted from an API or a future queued-job payload.
            $table->foreign(['rule_id', 'device_id'], 'ext_bkp_run_rule_device_fk')
                ->references(['id', 'device_id'])->on('external_backup_rules')
                ->restrictOnDelete();
            $table->unique(['rule_id', 'scheduled_at_utc'], 'ext_bkp_run_occurrence_unique');
            $table->unique(['id', 'device_id'], 'ext_bkp_run_id_device_unique');
            $table->index(['device_id', 'status', 'scheduled_at_utc'], 'ext_bkp_run_device_status_idx');
        });

        Schema::create('external_backup_file_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('check_run_id');
            $table->integer('device_id');
            // Opaque lower-case 64-character key; the future safe-open adapter
            // must define its trustworthy construction before ANY insert.
            // This is not a bare content hash: identical backup contents may
            // legitimately arrive on different scheduled occurrences.
            $table->char('verified_file_token', 64);
            $table->dateTime('accepted_at_utc');
            $table->timestamps();

            $table->foreign(['check_run_id', 'device_id'], 'ext_bkp_receipt_run_device_fk')
                ->references(['id', 'device_id'])->on('external_backup_check_runs')
                ->restrictOnDelete();
            $table->unique('check_run_id', 'ext_bkp_receipt_one_per_run');
            $table->unique(['device_id', 'verified_file_token'], 'ext_bkp_receipt_device_file_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_backup_file_receipts');
        Schema::dropIfExists('external_backup_check_runs');
        Schema::dropIfExists('external_backup_rules');
    }
};
