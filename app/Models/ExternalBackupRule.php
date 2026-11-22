<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A disabled-by-default external upload check rule linked to an existing Core task.
 * This model does not activate a scheduler or accept filesystem paths from users.
 */
class ExternalBackupRule extends BaseModel
{
    protected $fillable = [
        'task_id',
        'device_id',
        'pattern_mode',
        'filename_pattern',
        'minimum_bytes',
        'quiet_seconds',
        'deadline_seconds',
        'clock_skew_seconds',
        'max_entries',
        'max_matches',
        'max_scan_millis',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'minimum_bytes' => 'integer',
            'quiet_seconds' => 'integer',
            'deadline_seconds' => 'integer',
            'clock_skew_seconds' => 'integer',
            'max_entries' => 'integer',
            'max_matches' => 'integer',
            'max_scan_millis' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ExternalBackupCheckRun::class, 'rule_id');
    }
}
