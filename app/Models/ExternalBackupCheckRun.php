<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One scheduled occurrence per rule, protected by a database unique index.
 * Successful completion is not implemented by this metadata model.
 */
class ExternalBackupCheckRun extends BaseModel
{
    protected $fillable = [
        'rule_id',
        'device_id',
        'scheduled_at_utc',
        'deadline_at_utc',
        'status',
        'reason',
        'first_observed_at_utc',
        'last_observed_at_utc',
        'matched_filename',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at_utc' => 'datetime',
            'deadline_at_utc' => 'datetime',
            'first_observed_at_utc' => 'datetime',
            'last_observed_at_utc' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ExternalBackupRule::class, 'rule_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(ExternalBackupFileReceipt::class, 'check_run_id');
    }
}
