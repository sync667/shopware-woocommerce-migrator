<?php

namespace Tests\Unit\Services;

use App\Services\ShopwareDB;
use App\Services\ShopwareVersionDetector;
use Tests\TestCase;

class ShopwareVersionDetectorTest extends TestCase
{
    public function test_detects_version_67_when_product_type_exists(): void
    {
        $db = $this->createMockDb([
            // columnExists('product', 'type') => true
            ['product', 'type', true],
            // columnExists('product', 'type') => true (called again in detect())
            ['product', 'type', true],
            ['payment_method', 'technical_name', true],
            ['shipping_method', 'technical_name', true],
            ['product', 'states', false],
            ['product', 'canonical_product_version_id', true],
        ]);

        $detector = new ShopwareVersionDetector($db);
        $this->assertEquals('6.7', $detector->detectMajorVersion());
    }

    public function test_detects_version_66_when_technical_name_exists(): void
    {
        $db = $this->createMockDb([
            // columnExists('product', 'type') => false
            ['product', 'type', false],
            // columnExists('payment_method', 'technical_name') => true
            ['payment_method', 'technical_name', true],
            // detect() full scan
            ['product', 'type', false],
            ['payment_method', 'technical_name', true],
            ['shipping_method', 'technical_name', true],
            ['product', 'states', true],
            ['product', 'canonical_product_version_id', true],
        ]);

        $detector = new ShopwareVersionDetector($db);
        $this->assertEquals('6.6', $detector->detectMajorVersion());
    }

    public function test_detects_version_65_as_fallback(): void
    {
        $db = $this->createMockDb([
            // columnExists('product', 'type') => false
            ['product', 'type', false],
            // columnExists('payment_method', 'technical_name') => false
            ['payment_method', 'technical_name', false],
            // tableExists('product') => true
        ], tableExists: true);

        $detector = new ShopwareVersionDetector($db);
        $this->assertEquals('6.5', $detector->detectMajorVersion());
    }

    public function test_returns_unknown_when_no_product_table(): void
    {
        $db = $this->createMockDb([
            ['product', 'type', false],
            ['payment_method', 'technical_name', false],
        ], tableExists: false);

        $detector = new ShopwareVersionDetector($db);
        $this->assertEquals('unknown', $detector->detectMajorVersion());
    }

    public function test_detect_returns_structured_report(): void
    {
        $db = $this->createMockDb([
            // detectMajorVersion calls
            ['product', 'type', true],
            // detect() feature checks
            ['product', 'type', true],
            ['payment_method', 'technical_name', true],
            ['shipping_method', 'technical_name', true],
            ['product', 'states', false],
            ['product', 'canonical_product_version_id', true],
        ]);

        $detector = new ShopwareVersionDetector($db);
        $result = $detector->detect();

        $this->assertEquals('6.7', $result['version']);
        $this->assertTrue($result['features']['product_type_column']);
        $this->assertTrue($result['features']['payment_technical_name']);
        $this->assertTrue($result['features']['shipping_technical_name']);
        $this->assertFalse($result['features']['product_states_column']);
        $this->assertTrue($result['features']['canonical_product_version_id']);
        $this->assertEmpty($result['warnings']);
    }

    public function test_detect_includes_warnings_for_65(): void
    {
        $db = $this->createMockDb([
            // detectMajorVersion calls
            ['product', 'type', false],
            ['payment_method', 'technical_name', false],
            // detect() feature checks
            ['product', 'type', false],
            ['payment_method', 'technical_name', false],
            ['shipping_method', 'technical_name', false],
            ['product', 'states', true],
            ['product', 'canonical_product_version_id', false],
        ], tableExists: true);

        $detector = new ShopwareVersionDetector($db);
        $result = $detector->detect();

        $this->assertEquals('6.5', $result['version']);
        $this->assertFalse($result['features']['product_type_column']);
        $this->assertFalse($result['features']['payment_technical_name']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_returns_unknown_on_exception(): void
    {
        $db = $this->createMock(ShopwareDB::class);
        $db->method('select')->willThrowException(new \RuntimeException('Connection failed'));

        $detector = new ShopwareVersionDetector($db);
        $this->assertEquals('unknown', $detector->detectMajorVersion());
    }

    public function test_shopware_db_version_helpers(): void
    {
        $db67 = new ShopwareDB(['shopware_version' => '6.7']);
        $this->assertEquals('6.7', $db67->shopwareVersion());
        $this->assertTrue($db67->isAtLeast('6.5'));
        $this->assertTrue($db67->isAtLeast('6.6'));
        $this->assertTrue($db67->isAtLeast('6.7'));
        $this->assertFalse($db67->isAtLeast('6.8'));

        $db65 = new ShopwareDB(['shopware_version' => '6.5']);
        $this->assertEquals('6.5', $db65->shopwareVersion());
        $this->assertTrue($db65->isAtLeast('6.5'));
        $this->assertFalse($db65->isAtLeast('6.6'));

        $dbNull = new ShopwareDB([]);
        $this->assertNull($dbNull->shopwareVersion());
        $this->assertFalse($dbNull->isAtLeast('6.5'));
    }

    /**
     * Create a mock ShopwareDB that returns expected results for information_schema queries.
     */
    private function createMockDb(array $columnChecks, bool $tableExists = true): ShopwareDB
    {
        $mock = $this->createMock(ShopwareDB::class);

        $mock->method('select')->willReturnCallback(
            function (string $query, array $bindings) use ($columnChecks, $tableExists) {
                if (str_contains($query, 'information_schema.COLUMNS')) {
                    $table = $bindings[0];
                    $column = $bindings[1];

                    foreach ($columnChecks as $check) {
                        if ($check[0] === $table && $check[1] === $column) {
                            $cnt = $check[2] ? 1 : 0;

                            return [(object) ['cnt' => $cnt]];
                        }
                    }

                    return [(object) ['cnt' => 0]];
                }

                if (str_contains($query, 'information_schema.TABLES')) {
                    return [(object) ['cnt' => $tableExists ? 1 : 0]];
                }

                return [];
            }
        );

        return $mock;
    }
}
