<?php

namespace Tests\Unit\Transformers;

use App\Shopware\Transformers\CategoryTransformer;
use PHPUnit\Framework\TestCase;

class CategoryTransformerTest extends TestCase
{
    public function test_transforms_category(): void
    {
        $transformer = new CategoryTransformer;

        $category = (object) [
            'name' => 'Electronics',
            'description' => 'All electronics',
            'sort_order' => 5,
        ];

        $result = $transformer->transform($category);

        $this->assertEquals('Electronics', $result['name']);
        $this->assertEquals('All electronics', $result['description']);
        $this->assertEquals(5, $result['menu_order']);
        $this->assertArrayNotHasKey('parent', $result);
    }

    public function test_transforms_child_category(): void
    {
        $transformer = new CategoryTransformer;

        $category = (object) [
            'name' => 'Laptops',
            'description' => '',
            'sort_order' => 2,
        ];

        $result = $transformer->transform($category, 42);

        $this->assertEquals(42, $result['parent']);
    }
}
