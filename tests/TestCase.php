<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Establish the session that ValidateAccessToken middleware checks for.
     * Mirrors what AuthController::validateToken does on a successful login.
     */
    protected function actsAsAuthenticated(): self
    {
        return $this->withSession([
            'authenticated' => true,
            'authenticated_at' => now(),
        ]);
    }
}
