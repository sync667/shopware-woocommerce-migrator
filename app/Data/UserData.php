<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

class UserData extends Data
{
    public function __construct(
        #[Required, Max(255)]
        public string $name,

        #[Required, Email, Max(255)]
        public string $email,

        public ?string $email_verified_at = null,

        /** @var array<string> */
        public array $roles = [],

        /** @var array<string> */
        public array $permissions = [],
    ) {}
}
