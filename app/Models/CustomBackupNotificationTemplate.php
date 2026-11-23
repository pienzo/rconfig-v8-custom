<?php

namespace App\Models;

/** Global administrator-defined backup notification text, never executable. */
final class CustomBackupNotificationTemplate extends BaseModel
{
    protected $fillable = [
        'channel', 'event_type', 'title', 'body', 'updated_by_user_id',
    ];
}
