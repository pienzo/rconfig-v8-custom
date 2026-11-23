<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_backup_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->string('event_type', 32);
            $table->string('channel', 16);
            $table->string('destination_key', 64);
            $table->string('status', 16)->default('pending');
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'event_type', 'channel', 'destination_key'], 'custom_backup_delivery_identity_unique');
            $table->index(['status', 'id'], 'custom_backup_delivery_pending_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_backup_notification_deliveries');
    }
};
