<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_backup_notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 16);
            $table->string('event_type', 64);
            $table->string('title', 255);
            $table->text('body');
            $table->integer('updated_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['channel', 'event_type'], 'custom_backup_templates_channel_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_backup_notification_templates');
    }
};
