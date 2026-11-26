<?php

namespace App\Models;

/** Temporary diagnostic state, never a scheduled check run. */
class LocalBackupPresenceManualSession extends BaseModel
{
    public $incrementing = false;
    public $timestamps = false;
    protected $keyType = 'string';
    protected $guarded = ['*'];
    protected $hidden = ['continuation', 'result', 'dispatch_not_before'];

    protected function casts(): array
    {
        return [
            'requested_at' => 'integer',
            'expires_at' => 'integer',
            'next_observation_at' => 'integer',
            'dispatch_not_before' => 'integer',
            'continuation' => 'array',
            'result' => 'array',
        ];
    }
}
