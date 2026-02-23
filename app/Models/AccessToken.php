<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AccessToken extends Model
{
    protected $fillable = [
        'token',
        'name',
        'last_used_at',
        'expires_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Generate a new random token
     */
    public static function generate(?string $name = null, ?int $expiresInDays = null): self
    {
        return self::create([
            'token' => Str::random(64),
            'name' => $name ?? 'Generated at '.now()->toDateTimeString(),
            'expires_at' => $expiresInDays ? now()->addDays($expiresInDays) : null,
        ]);
    }

    /**
     * Check if token is valid (not expired)
     */
    public function isValid(): bool
    {
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Mark token as used
     */
    public function markAsUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }
}
