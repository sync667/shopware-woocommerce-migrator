<?php

namespace App\Console\Commands;

use App\Models\AccessToken;
use Illuminate\Console\Command;

class GenerateAccessToken extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'token:generate
                            {--expires= : Number of days until token expires (optional)}
                            {--list : List all existing tokens}
                            {--revoke= : Revoke a token by ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate an access token for the migration tool';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // List tokens
        if ($this->option('list')) {
            return $this->listTokens();
        }

        // Revoke token
        if ($revokeId = $this->option('revoke')) {
            return $this->revokeToken($revokeId);
        }

        // Generate new token
        $expires = $this->option('expires') ? (int) $this->option('expires') : null;

        $token = AccessToken::generate(null, $expires);

        $this->newLine();
        $this->line('═══════════════════════════════════════════════════════════════════');
        $this->line('<fg=green>'.$token->token.'</>');
        $this->line('═══════════════════════════════════════════════════════════════════');
        $this->newLine();
        if ($token->expires_at) {
            $this->line('⏰ Expires: <fg=yellow>'.$token->expires_at->diffForHumans().'</>');
        } else {
            $this->line('⏰ Expires: <fg=green>Never</>');
        }
        $this->newLine();
        $this->warn('⚠️  Copy this token now - it won\'t be shown again!');

        return self::SUCCESS;
    }

    /**
     * List all tokens
     */
    protected function listTokens(): int
    {
        $tokens = AccessToken::orderBy('created_at', 'desc')->get();

        if ($tokens->isEmpty()) {
            $this->warn('No tokens found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Created', 'Last Used', 'Expires', 'Status'],
            $tokens->map(fn ($token) => [
                $token->id,
                $token->created_at->diffForHumans(),
                $token->last_used_at?->diffForHumans() ?? 'Never',
                $token->expires_at?->toDateString() ?? 'Never',
                $token->isValid() ? '<fg=green>Valid</>' : '<fg=red>Expired</>',
            ])
        );

        return self::SUCCESS;
    }

    /**
     * Revoke a token
     */
    protected function revokeToken(int $id): int
    {
        $token = AccessToken::find($id);

        if (! $token) {
            $this->error('Token not found.');

            return self::FAILURE;
        }

        $token->delete();
        $this->info("✓ Token #{$id} revoked successfully.");

        return self::SUCCESS;
    }
}
