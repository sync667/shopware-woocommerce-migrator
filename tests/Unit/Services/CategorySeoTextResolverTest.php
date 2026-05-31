<?php

namespace Tests\Unit\Services;

use App\Services\CategorySeoTextResolver;
use App\Services\ShopwareDB;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class CategorySeoTextResolverTest extends TestCase
{
    private const LANGUAGE_HEX = '2fbb5fe2e29a4d70aa5854ce7ce3e20b';

    private const CMS_PAGE_HEX = 'aaaaaaaaaaaa4d70aa5854ce7ce3e20b';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('migration.category_seo.custom_field_key', 'custom_seo_text_below');
    }

    public function test_custom_field_value_wins_over_cms_slot_text(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->never())->method('select');

        $category = (object) [
            'cms_page_id' => self::CMS_PAGE_HEX,
            'translation_custom_fields' => json_encode([
                'custom_seo_text_below' => 'From custom field',
            ]),
        ];

        $resolver = new CategorySeoTextResolver($db);

        $this->assertSame('From custom field', $resolver->resolve($category));
    }

    public function test_falls_back_to_cms_slot_text_when_custom_field_absent(): void
    {
        $db = $this->makeDbMock();
        $db->method('select')->willReturn([
            (object) ['config' => json_encode(['content' => ['value' => 'From CMS slot']])],
        ]);

        $category = (object) [
            'cms_page_id' => self::CMS_PAGE_HEX,
            'translation_custom_fields' => null,
        ];

        $resolver = new CategorySeoTextResolver($db);

        $this->assertSame('From CMS slot', $resolver->resolve($category));
    }

    public function test_falls_back_to_cms_slot_text_when_custom_field_empty(): void
    {
        $db = $this->makeDbMock();
        $db->method('select')->willReturn([
            (object) ['config' => json_encode(['content' => ['value' => 'Slot text']])],
        ]);

        $category = (object) [
            'cms_page_id' => self::CMS_PAGE_HEX,
            'translation_custom_fields' => json_encode(['custom_seo_text_below' => '   ']),
        ];

        $resolver = new CategorySeoTextResolver($db);

        $this->assertSame('Slot text', $resolver->resolve($category));
    }

    public function test_returns_empty_when_both_sources_empty(): void
    {
        $db = $this->makeDbMock();
        $db->method('select')->willReturn([]);

        $category = (object) [
            'cms_page_id' => self::CMS_PAGE_HEX,
            'translation_custom_fields' => null,
        ];

        $resolver = new CategorySeoTextResolver($db);

        $this->assertSame('', $resolver->resolve($category));
    }

    public function test_returns_empty_when_no_cms_page_and_no_custom_field(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->never())->method('select');

        $category = (object) [
            'cms_page_id' => '',
            'translation_custom_fields' => null,
        ];

        $resolver = new CategorySeoTextResolver($db);

        $this->assertSame('', $resolver->resolve($category));
    }

    public function test_picks_last_text_slot_when_multiple_present(): void
    {
        $db = $this->makeDbMock();
        $db->method('select')->willReturn([
            (object) ['config' => json_encode(['content' => ['value' => 'First slot']])],
            (object) ['config' => json_encode(['content' => ['value' => 'Middle slot']])],
            (object) ['config' => json_encode(['content' => ['value' => 'Last slot']])],
        ]);

        $category = (object) [
            'cms_page_id' => self::CMS_PAGE_HEX,
            'translation_custom_fields' => null,
        ];

        $resolver = new CategorySeoTextResolver($db);

        $this->assertSame('Last slot', $resolver->resolve($category));
    }

    public function test_malformed_custom_fields_json_does_not_throw(): void
    {
        $db = $this->makeDbMock();
        $db->method('select')->willReturn([
            (object) ['config' => json_encode(['content' => ['value' => 'Fallback slot']])],
        ]);

        $category = (object) [
            'cms_page_id' => self::CMS_PAGE_HEX,
            'translation_custom_fields' => '{not-valid-json',
        ];

        $resolver = new CategorySeoTextResolver($db);

        $this->assertSame('Fallback slot', $resolver->resolve($category));
    }

    public function test_returns_empty_when_cms_query_throws(): void
    {
        $db = $this->makeDbMock();
        $db->method('select')->willThrowException(new \RuntimeException('connection lost'));

        $category = (object) [
            'cms_page_id' => self::CMS_PAGE_HEX,
            'translation_custom_fields' => null,
        ];

        $resolver = new CategorySeoTextResolver($db);

        $this->assertSame('', $resolver->resolve($category));
    }

    public function test_ignores_slot_rows_with_invalid_config_json(): void
    {
        $db = $this->makeDbMock();
        $db->method('select')->willReturn([
            (object) ['config' => 'not-json'],
            (object) ['config' => json_encode(['content' => ['value' => 'Valid slot']])],
            (object) ['config' => json_encode(['content' => ['value' => '  ']])],
        ]);

        $category = (object) [
            'cms_page_id' => self::CMS_PAGE_HEX,
            'translation_custom_fields' => null,
        ];

        $resolver = new CategorySeoTextResolver($db);

        $this->assertSame('Valid slot', $resolver->resolve($category));
    }

    private function makeDbMock(): MockObject&ShopwareDB
    {
        $db = $this->getMockBuilder(ShopwareDB::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['select', 'languageIdBin'])
            ->getMock();

        $db->method('languageIdBin')->willReturn(hex2bin(self::LANGUAGE_HEX));

        return $db;
    }
}
