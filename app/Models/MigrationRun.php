<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MigrationRun extends Model
{
    protected $table = 'migration_runs';

    protected $fillable = [
        'name',
        'status',
        'is_dry_run',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'is_dry_run' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function entities(): HasMany
    {
        return $this->hasMany(MigrationEntity::class, 'migration_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MigrationLog::class, 'migration_id');
    }

    public function markRunning(): void
    {
        $this->update(['status' => 'running', 'started_at' => now()]);
    }

    public function markCompleted(): void
    {
        $this->update(['status' => 'completed', 'finished_at' => now()]);
    }

    public function markFailed(): void
    {
        $this->update(['status' => 'failed', 'finished_at' => now()]);
    }

    public function markPaused(): void
    {
        $this->update(['status' => 'paused']);
    }
}
