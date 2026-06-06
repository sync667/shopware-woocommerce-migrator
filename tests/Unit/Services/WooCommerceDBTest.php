<?php

namespace Tests\Unit\Services;

use App\Services\WooCommerceDB;
use PHPUnit\Framework\TestCase;

class WooCommerceDBTest extends TestCase
{
    public function test_is_configured_requires_host_database_and_username(): void
    {
        $this->assertFalse((new WooCommerceDB([]))->isConfigured());
        $this->assertFalse((new WooCommerceDB(['db_host' => 'x']))->isConfigured());
        $this->assertFalse((new WooCommerceDB(['db_host' => 'x', 'db_database' => 'y']))->isConfigured());

        $this->assertTrue((new WooCommerceDB([
            'db_host' => 'x', 'db_database' => 'y', 'db_username' => 'z',
        ]))->isConfigured());
    }

    public function test_default_table_prefix_is_wp(): void
    {
        $db = new WooCommerceDB([]);
        $this->assertSame('wp_', $db->prefix());
        $this->assertSame('wp_posts', $db->table('posts'));
        $this->assertSame('wp_postmeta', $db->table('postmeta'));
    }

    public function test_custom_prefix_is_used_when_valid(): void
    {
        $db = new WooCommerceDB(['table_prefix' => 'staging_']);
        $this->assertSame('staging_', $db->prefix());
        $this->assertSame('staging_posts', $db->table('posts'));
    }

    public function test_invalid_prefix_falls_back_to_wp(): void
    {
        // Defends against SQL injection — prefix is interpolated, not bound.
        $db = new WooCommerceDB(['table_prefix' => 'wp_; DROP TABLE--']);
        $this->assertSame('wp_', $db->prefix());
    }

    public function test_upsert_post_meta_short_circuits_on_empty_input(): void
    {
        $db = new WooCommerceDB([]);
        $this->assertSame(0, $db->upsertPostMeta('_anything', []));
    }

    public function test_upsert_term_meta_short_circuits_on_empty_input(): void
    {
        $db = new WooCommerceDB([]);
        $this->assertSame(0, $db->upsertTermMeta('_anything', []));
    }

    public function test_set_exact_post_dates_short_circuits_on_empty_input(): void
    {
        $db = new WooCommerceDB([]);
        $this->assertSame(0, $db->setExactPostDates([]));
    }

    public function test_renumber_order_noops_when_from_equals_to(): void
    {
        // No connection ever opened — proves the guard short-circuits before SQL.
        $db = new WooCommerceDB([]);
        $db->renumberOrder(123, 123);
        $this->assertTrue(true);
    }
}
