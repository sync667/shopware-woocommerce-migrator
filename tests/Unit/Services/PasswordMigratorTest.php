<?php

namespace Tests\Unit\Services;

use App\Services\PasswordMigrator;
use Tests\TestCase;

class PasswordMigratorTest extends TestCase
{
    private PasswordMigrator $migrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrator = new PasswordMigrator;
    }

    public function test_direct_migration_for_wp_68_plus(): void
    {
        $hash = '$2y$13$somehashedpassword';
        $result = $this->migrator->migrate($hash, 68);

        $this->assertEquals($hash, $result['password']);
        $this->assertFalse($result['requires_reset']);
    }

    public function test_requires_reset_for_older_wp(): void
    {
        $hash = '$2y$13$somehashedpassword';
        $result = $this->migrator->migrate($hash, 65);

        $this->assertNotEquals($hash, $result['password']);
        $this->assertTrue($result['requires_reset']);
    }
}
