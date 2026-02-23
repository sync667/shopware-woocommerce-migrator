<?php

namespace Tests\Unit;

use App\Services\ContentMigrator;
use App\Services\ImageMigrator;
use Mockery;
use PHPUnit\Framework\TestCase;

class ContentMigratorTest extends TestCase
{
    protected ContentMigrator $contentMigrator;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock ImageMigrator
        $imageMigrator = Mockery::mock(ImageMigrator::class);
        $imageMigrator->shouldReceive('migrateFromUrl')
            ->andReturn(123);
        $imageMigrator->shouldReceive('getWordPressMediaUrl')
            ->with(123)
            ->andReturn('https://wordpress.test/wp-content/uploads/2026/02/test-image.jpg');

        $this->contentMigrator = new ContentMigrator($imageMigrator);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_preserves_basic_html_formatting(): void
    {
        $html = '<p>This is a <strong>bold</strong> and <em>italic</em> text.</p>';

        $result = $this->contentMigrator->processHtmlContent($html);

        $this->assertStringContainsString('<strong>bold</strong>', $result);
        $this->assertStringContainsString('<em>italic</em>', $result);
    }

    public function test_preserves_tables(): void
    {
        $html = '
            <table>
                <thead>
                    <tr>
                        <th>Header 1</th>
                        <th>Header 2</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Cell 1</td>
                        <td>Cell 2</td>
                    </tr>
                    <tr>
                        <td>Cell 3</td>
                        <td>Cell 4</td>
                    </tr>
                </tbody>
            </table>
        ';

        $result = $this->contentMigrator->processHtmlContent($html);

        $this->assertStringContainsString('<table>', $result);
        $this->assertStringContainsString('<thead>', $result);
        $this->assertStringContainsString('<tbody>', $result);
        $this->assertStringContainsString('<th>Header 1</th>', $result);
        $this->assertStringContainsString('<td>Cell 1</td>', $result);
    }

    public function test_preserves_lists(): void
    {
        $html = '
            <ul>
                <li>Item 1</li>
                <li>Item 2</li>
                <li>Item 3</li>
            </ul>
            <ol>
                <li>First</li>
                <li>Second</li>
            </ol>
        ';

        $result = $this->contentMigrator->processHtmlContent($html);

        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<ol>', $result);
        $this->assertStringContainsString('<li>Item 1</li>', $result);
        $this->assertStringContainsString('<li>First</li>', $result);
    }

    public function test_preserves_headings(): void
    {
        $html = '
            <h1>Heading 1</h1>
            <h2>Heading 2</h2>
            <h3>Heading 3</h3>
        ';

        $result = $this->contentMigrator->processHtmlContent($html);

        $this->assertStringContainsString('<h1>Heading 1</h1>', $result);
        $this->assertStringContainsString('<h2>Heading 2</h2>', $result);
        $this->assertStringContainsString('<h3>Heading 3</h3>', $result);
    }

    public function test_removes_dangerous_attributes(): void
    {
        $html = '<p onclick="alert(\'xss\')">Click me</p><a href="javascript:alert(\'xss\')">Link</a>';

        $result = $this->contentMigrator->processHtmlContent($html);

        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function test_removes_script_tags(): void
    {
        $html = '<p>Safe content</p><script>alert("xss")</script>';

        $result = $this->contentMigrator->processHtmlContent($html);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringContainsString('Safe content', $result);
    }

    public function test_preserves_links(): void
    {
        $html = '<p>Check out <a href="https://example.com">this link</a></p>';

        $result = $this->contentMigrator->processHtmlContent($html);

        $this->assertStringContainsString('<a href="https://example.com">this link</a>', $result);
    }

    public function test_preserves_divs_and_spans(): void
    {
        $html = '<div class="container"><span class="highlight">Important</span></div>';

        $result = $this->contentMigrator->processHtmlContent($html);

        $this->assertStringContainsString('<div', $result);
        $this->assertStringContainsString('<span', $result);
        $this->assertStringContainsString('highlight', $result);
    }

    public function test_extract_plain_text(): void
    {
        $html = '<p>This is <strong>some</strong> <em>HTML</em> content with <a href="#">links</a>.</p>';

        $text = $this->contentMigrator->extractPlainText($html, 50);

        $this->assertEquals('This is some HTML content with links.', $text);
    }

    public function test_extract_plain_text_with_length_limit(): void
    {
        $html = '<p>'.str_repeat('Lorem ipsum dolor sit amet ', 20).'</p>';

        $text = $this->contentMigrator->extractPlainText($html, 50);

        $this->assertLessThanOrEqual(54, strlen($text)); // 50 + "..."
        $this->assertStringEndsWith('...', $text);
    }

    public function test_has_rich_content_detects_tables(): void
    {
        $html = '<table><tr><td>Cell</td></tr></table>';

        $this->assertTrue($this->contentMigrator->hasRichContent($html));
    }

    public function test_has_rich_content_detects_lists(): void
    {
        $html = '<ul><li>Item</li></ul>';

        $this->assertTrue($this->contentMigrator->hasRichContent($html));
    }

    public function test_has_rich_content_returns_false_for_simple_text(): void
    {
        $html = '<p>Simple <strong>text</strong> with no rich content.</p>';

        $this->assertFalse($this->contentMigrator->hasRichContent($html));
    }

    public function test_handles_empty_content(): void
    {
        $result = $this->contentMigrator->processHtmlContent('');

        $this->assertEquals('', $result);
    }

    public function test_handles_malformed_html(): void
    {
        $html = '<p>Unclosed paragraph<div>Nested div</p></div>';

        // Should not throw exception and should produce some output
        $result = $this->contentMigrator->processHtmlContent($html);

        $this->assertNotEmpty($result);
        $this->assertStringContainsString('Unclosed paragraph', $result);
    }

    public function test_preserves_complex_nested_structure(): void
    {
        $html = '
            <div class="product-description">
                <h2>Product Features</h2>
                <ul>
                    <li>Feature 1</li>
                    <li>Feature 2</li>
                </ul>
                <h3>Specifications</h3>
                <table>
                    <tr>
                        <th>Spec</th>
                        <th>Value</th>
                    </tr>
                    <tr>
                        <td>Weight</td>
                        <td>1kg</td>
                    </tr>
                </table>
                <p>Additional <strong>information</strong> here.</p>
            </div>
        ';

        $result = $this->contentMigrator->processHtmlContent($html);

        $this->assertStringContainsString('<h2>Product Features</h2>', $result);
        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<table>', $result);
        $this->assertStringContainsString('<strong>information</strong>', $result);
    }
}
