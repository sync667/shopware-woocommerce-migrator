<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        // For API requests, return null (will return 401 JSON response)
        if ($request->expectsJson()) {
            return null;
        }

        // For web requests, redirect to dashboard
        // This app uses API-based auth (Sanctum) without traditional login pages
        return route('dashboard');
    }
}
