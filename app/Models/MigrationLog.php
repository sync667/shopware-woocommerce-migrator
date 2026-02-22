<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'migration_id',
        'entity_type',
        'shopware_id',
        'level',
        'message',
        'context',
        'created_at',
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function migration(): BelongsTo
    {
        return $this->belongsTo(MigrationRun::class, 'migration_id');
    }
}
