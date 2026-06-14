<?php

namespace Tests\Feature\Jobs;

use App\Jobs\MigrateCustomerBatchJob;
use PHPUnit\Framework\TestCase;

class MigrateCustomerBatchJobTest extends TestCase
{
    public function test_alias_email_adds_sw_suffix_before_at(): void
    {
        $this->assertSame(
            'monika+sw_210855f6@remiza.pl',
            MigrateCustomerBatchJob::aliasEmail('monika@remiza.pl', '210855f6dc2947948583d94a6ee33edb')
        );
    }

    public function test_alias_email_handles_no_at_sign(): void
    {
        $this->assertSame(
            'broken+sw_abcdef12',
            MigrateCustomerBatchJob::aliasEmail('broken', 'abcdef1234567890')
        );
    }

    public function test_alias_email_uses_last_at_for_complex_locals(): void
    {
        $this->assertSame(
            'user+old@host+sw_12345678@remiza.pl',
            MigrateCustomerBatchJob::aliasEmail('user+old@host@remiza.pl', '12345678abcdef')
        );
    }

    public function test_alias_email_synthesizes_id_when_shopware_id_empty(): void
    {
        $result = MigrateCustomerBatchJob::aliasEmail('user@example.com', '');
        $this->assertMatchesRegularExpression('/^user\+sw_[0-9a-f]{8}@example\.com$/', $result);
    }
}
