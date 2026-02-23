<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class CancellationService
{
    public function cancel(int $migrationId): void
    {
        Cache::put($this->key($migrationId), true, now()->addDay());
    }

    public function isCancelled(int $migrationId): bool
    {
        return (bool) Cache::get($this->key($migrationId), false);
    }

    public function clear(int $migrationId): void
    {
        Cache::forget($this->key($migrationId));
    }

    private function key(int $migrationId): string
    {
        return "migration:{$migrationId}:cancelled";
    }
}
