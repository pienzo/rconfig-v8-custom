<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger row for a verified, uniquely consumed file receipt.
 *
 * No safe-open adapter exists yet, so application code must not create these
 * receipts until verified_file_token construction has been designed and tested.
 */
class ExternalBackupFileReceipt extends BaseModel
{
    protected $fillable = [
        'check_run_id',
        'device_id',
        'verified_file_token',
        'accepted_at_utc',
    ];
    protected $hidden = [
        'verified_file_token',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at_utc' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ExternalBackupCheckRun::class, 'check_run_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
