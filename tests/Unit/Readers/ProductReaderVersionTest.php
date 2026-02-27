<?php

namespace Tests\Unit\Readers;

use App\Services\ShopwareDB;
use App\Shopware\Readers\ProductReader;
use PHPUnit\Framework\TestCase;

class ProductReaderVersionTest extends TestCase
{
    /**
     * Test that the ProductReader generates correct SQL type expression for Shopware 6.7+.
     * On 6.7+, it should use the `product.type` column.
     */
    public function test_product_type_expression_uses_type_column_on_67(): void
    {
        $db = $this->createMock(ShopwareDB::class);
        $db->method('isAtLeast')->willReturnCallback(fn (string $v) => version_compare('6.7', $v, '>='));
        $db->method('languageIdBin')->willReturn(hex2bin('0fa91ce3e96a4bc2be4bd9ce752c3425'));
        $db->method('liveVersionIdBin')->willReturn(hex2bin('0fa91ce3e96a4bc2be4bd9ce752c3425'));

        $reader = new ProductReader($db);

        // Use reflection to call the protected method
        $reflection = new \ReflectionMethod($reader, 'productTypeExpression');
        $result = $reflection->invoke($reader);

        // On 6.7+, should reference p.type = 'digital'
        $this->assertStringContainsString("p.type = 'digital'", $result);
        $this->assertStringNotContainsString('JSON_CONTAINS', $result);
    }

    /**
     * Test that the ProductReader generates correct SQL type expression for Shopware 6.5/6.6.
     * On older versions, it should use JSON_CONTAINS on the `product.states` column.
     */
    public function test_product_type_expression_uses_states_on_65(): void
    {
        $db = $this->createMock(ShopwareDB::class);
        $db->method('isAtLeast')->willReturnCallback(fn (string $v) => version_compare('6.5', $v, '>='));
        $db->method('languageIdBin')->willReturn(hex2bin('0fa91ce3e96a4bc2be4bd9ce752c3425'));
        $db->method('liveVersionIdBin')->willReturn(hex2bin('0fa91ce3e96a4bc2be4bd9ce752c3425'));

        $reader = new ProductReader($db);

        $reflection = new \ReflectionMethod($reader, 'productTypeExpression');
        $result = $reflection->invoke($reader);

        // On 6.5/6.6, should use JSON_CONTAINS for is-download
        $this->assertStringContainsString('JSON_CONTAINS', $result);
        $this->assertStringContainsString('is-download', $result);
        $this->assertStringContainsString('JSON_QUOTE', $result);
        $this->assertStringNotContainsString("p.type = 'digital'", $result);
    }

    /**
     * Test that the ProductReader generates correct SQL type expression for Shopware 6.6.
     */
    public function test_product_type_expression_uses_states_on_66(): void
    {
        $db = $this->createMock(ShopwareDB::class);
        $db->method('isAtLeast')->willReturnCallback(fn (string $v) => version_compare('6.6', $v, '>='));
        $db->method('languageIdBin')->willReturn(hex2bin('0fa91ce3e96a4bc2be4bd9ce752c3425'));
        $db->method('liveVersionIdBin')->willReturn(hex2bin('0fa91ce3e96a4bc2be4bd9ce752c3425'));

        $reader = new ProductReader($db);

        $reflection = new \ReflectionMethod($reader, 'productTypeExpression');
        $result = $reflection->invoke($reader);

        // 6.6 is below 6.7, so should still use JSON_CONTAINS
        $this->assertStringContainsString('JSON_CONTAINS', $result);
    }

    /**
     * Test that version-unaware DB (null version) falls back to the states-based expression.
     */
    public function test_product_type_expression_fallback_when_version_null(): void
    {
        $db = $this->createMock(ShopwareDB::class);
        $db->method('isAtLeast')->willReturn(false);
        $db->method('languageIdBin')->willReturn(hex2bin('0fa91ce3e96a4bc2be4bd9ce752c3425'));
        $db->method('liveVersionIdBin')->willReturn(hex2bin('0fa91ce3e96a4bc2be4bd9ce752c3425'));

        $reader = new ProductReader($db);

        $reflection = new \ReflectionMethod($reader, 'productTypeExpression');
        $result = $reflection->invoke($reader);

        // When version is unknown/null, isAtLeast returns false, so should use JSON_CONTAINS
        $this->assertStringContainsString('JSON_CONTAINS', $result);
    }
}
