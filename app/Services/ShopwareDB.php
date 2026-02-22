<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ShopwareDB
{
    protected string $languageId;
    protected string $liveVersionId;

    public function __construct()
    {
        $this->languageId = config('shopware.language_id');
        $this->liveVersionId = config('shopware.live_version_id');
    }

    public function connection(): \Illuminate\Database\Connection
    {
        return DB::connection('shopware');
    }

    public function select(string $query, array $bindings = []): array
    {
        return $this->connection()->select($query, $bindings);
    }

    public function languageId(): string
    {
        return $this->languageId;
    }

    public function languageIdBin(): string
    {
        return hex2bin($this->languageId);
    }

    public function liveVersionId(): string
    {
        return $this->liveVersionId;
    }

    public function liveVersionIdBin(): string
    {
        return hex2bin($this->liveVersionId);
    }

    public function baseUrl(): string
    {
        return rtrim(config('shopware.base_url'), '/');
    }
}
