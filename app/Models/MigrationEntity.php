<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationEntity extends Model
{
    protected $fillable = [
        'migration_id',
        'entity_type',
        'shopware_id',
        'woo_id',
        'status',
        'error_message',
        'payload',
    ];

    protected $casts = [
        'woo_id' => 'integer',
        'payload' => 'array',
    ];

    public function migration(): BelongsTo
    {
        return $this->belongsTo(MigrationRun::class, 'migration_id');
    }
}
