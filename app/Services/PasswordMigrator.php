<?php

namespace App\Services;

use Illuminate\Support\Str;

class PasswordMigrator
{
    public function migrate(string $shopwareHash, int $wpVersion): array
    {
        // WordPress >= 6.8 supports bcrypt natively
        if ($wpVersion >= 68) {
            return [
                'password' => $shopwareHash,
                'requires_reset' => false,
            ];
        }

        // WordPress < 6.8: bcrypt incompatible with phpass, set random password
        return [
            'password' => bcrypt(Str::random(32)),
            'requires_reset' => true,
        ];
    }
}
