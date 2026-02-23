<?php

namespace App\Http\Controllers;

use App\Models\AccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    /**
     * Show login page
     */
    public function showLogin(): Response
    {
        return Inertia::render('Login');
    }

    /**
     * Validate access token and create session
     */
    public function validateToken(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $accessToken = AccessToken::where('token', $request->token)->first();

        if (! $accessToken || ! $accessToken->isValid()) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid or expired access token',
            ], 401);
        }

        // Mark token as used
        $accessToken->markAsUsed();

        // Create session (24 hours)
        session([
            'authenticated' => true,
            'authenticated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Authenticated successfully. Session valid for 24 hours.',
            'session_expires_at' => now()->addHours(24),
        ]);
    }

    /**
     * Logout (destroy session)
     */
    public function logout(Request $request): JsonResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }
}
