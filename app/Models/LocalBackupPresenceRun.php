<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An observation occurrence, not a verified receipt. Future lifecycle code
 * must serialize claims and terminal transitions under database locks.
 */
class LocalBackupPresenceRun extends BaseModel
{
    public const PENDING = 'pending';

    public const PRESENT = 'present';

    public const ABSENT = 'absent';

    public const ERROR = 'error';

    protected $hidden = ['directory_identity', 'stability_baseline', 'dispatch_not_before_utc'];
    protected $fillable = [
        'rule_id',
        'device_id',
        'scheduled_at_utc',
        'deadline_at_utc',
    ];

    protected function casts(): array
    {
        return [
            'criteria_snapshot' => 'array',
            'stability_baseline' => 'array',
            'scheduled_at_utc' => 'datetime',
            'deadline_at_utc' => 'datetime',
            'first_observed_at_utc' => 'datetime',
            'last_observed_at_utc' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(LocalBackupPresenceRule::class, 'rule_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
