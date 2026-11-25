<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Disabled-by-default local presence rule. A future domain service must validate
 * mappings, task/device linkage and all limits before activation.
 */
class LocalBackupPresenceRule extends BaseModel
{
    protected $fillable = [
        'task_id',
        'device_id',
        'directory_alias',
        'relative_directory',
        'pattern_mode',
        'filename_pattern',
        'minimum_bytes',
        'maximum_age_seconds',
        'quiet_seconds',
        'deadline_seconds',
        'max_entries',
        'max_matches',
        'max_scan_millis',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'minimum_bytes' => 'integer',
            'maximum_age_seconds' => 'integer',
            'quiet_seconds' => 'integer',
            'deadline_seconds' => 'integer',
            'max_entries' => 'integer',
            'max_matches' => 'integer',
            'max_scan_millis' => 'integer',
            'activated_at_utc' => 'datetime',
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
        return $this->hasMany(LocalBackupPresenceRun::class, 'rule_id');
    }
}
