<?php

namespace App\Http\Middleware;

use App\Models\AccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateAccessToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if user has active session
        if (! $request->session()->get('authenticated')) {
            return $this->unauthorized($request, 'Authentication required');
        }

        // Check if session is expired (24 hours)
        $authenticatedAt = $request->session()->get('authenticated_at');
        if ($authenticatedAt && now()->diffInHours($authenticatedAt) > 24) {
            $request->session()->invalidate();

            return $this->unauthorized($request, 'Session expired. Please login again.');
        }

        return $next($request);
    }

    /**
     * Return unauthorized response
     */
    protected function unauthorized(Request $request, string $message): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'error' => $message,
            ], 401);
        }

        return redirect()->route('login')->with('error', $message);
    }
}
