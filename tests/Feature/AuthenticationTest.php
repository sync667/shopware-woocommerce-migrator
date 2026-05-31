<?php

namespace Tests\Feature;

use App\Models\AccessToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders(): void
    {
        $this->withoutVite();

        $response = $this->get('/login');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page->component('Login'));
    }

    public function test_validate_token_creates_session_for_valid_token(): void
    {
        $accessToken = AccessToken::generate('Test token');

        $response = $this->postJson('/auth/validate', [
            'token' => $accessToken->token,
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure(['message', 'session_expires_at']);

        $response->assertSessionHas('authenticated', true);
    }

    public function test_validate_token_marks_token_as_used(): void
    {
        $accessToken = AccessToken::generate('Test token');
        $this->assertNull($accessToken->last_used_at);

        $this->postJson('/auth/validate', ['token' => $accessToken->token])->assertOk();

        $this->assertNotNull($accessToken->fresh()->last_used_at);
    }

    public function test_validate_token_rejects_invalid_token(): void
    {
        $response = $this->postJson('/auth/validate', [
            'token' => 'nonexistent-token',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => 'Invalid or expired access token',
            ]);
    }

    public function test_validate_token_rejects_expired_token(): void
    {
        $accessToken = AccessToken::create([
            'token' => 'expired-token',
            'name' => 'Expired',
            'expires_at' => now()->subHour(),
        ]);

        $response = $this->postJson('/auth/validate', [
            'token' => $accessToken->token,
        ]);

        $response->assertStatus(401);
    }

    public function test_validate_token_requires_token_field(): void
    {
        $response = $this->postJson('/auth/validate', []);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'The token field is required.');
    }

    public function test_logout_destroys_session(): void
    {
        $response = $this->actsAsAuthenticated()
            ->postJson('/auth/logout');

        $response->assertOk()
            ->assertJson(['success' => true]);

        $response->assertSessionMissing('authenticated');
    }

    public function test_protected_api_returns_401_without_session(): void
    {
        // Route model binding runs before ValidateAccessToken in the web middleware stack,
        // so we need a real migration record for the 401 to surface (otherwise we'd get 404).
        $migration = \App\Models\MigrationRun::create([
            'name' => 'M',
            'settings' => ['shopware' => [], 'woocommerce' => [], 'wordpress' => []],
            'status' => 'running',
            'is_dry_run' => false,
        ]);

        $response = $this->getJson("/api/migrations/{$migration->id}/status");

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => 'Authentication required',
            ]);
    }

    public function test_protected_web_redirects_to_login_without_session(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
