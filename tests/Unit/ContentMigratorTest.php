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

    public function test_preserves_legacy_font_tag_with_colors(): void
    {
        $html = '<p>This is <font color="#FF0000">RED text</font> inline</p>';
        $result = $this->contentMigrator->processHtmlContent($html);
        $this->assertStringContainsString('<font color="#FF0000">RED text</font>', $result);
    }

    public function test_preserves_center_tag(): void
    {
        $html = '<center><p>Centered paragraph</p></center>';
        $result = $this->contentMigrator->processHtmlContent($html);
        $this->assertStringContainsString('<center>', $result);
        $this->assertStringContainsString('Centered paragraph', $result);
    }

    public function test_preserves_modern_html5_inline_tags(): void
    {
        $html = '<p>Press <kbd>Ctrl</kbd>+<kbd>S</kbd> to <mark>save</mark>.</p>'
            .'<p>Was <del>broken</del> now <ins>fixed</ins>.</p>';

        $result = $this->contentMigrator->processHtmlContent($html);

        foreach (['<kbd>Ctrl</kbd>', '<mark>save</mark>', '<del>broken</del>', '<ins>fixed</ins>'] as $needle) {
            $this->assertStringContainsString($needle, $result);
        }
    }

    public function test_preserves_details_summary(): void
    {
        $html = '<details><summary>Click me</summary><p>Hidden body</p></details>';
        $result = $this->contentMigrator->processHtmlContent($html);
        $this->assertStringContainsString('<details>', $result);
        $this->assertStringContainsString('<summary>Click me</summary>', $result);
        $this->assertStringContainsString('Hidden body', $result);
    }

    public function test_preserves_inline_styles_class_id_data_attrs(): void
    {
        $html = '<p id="p1" class="lead" style="font-size: 18px;" data-track="yes">styled</p>';
        $result = $this->contentMigrator->processHtmlContent($html);
        $this->assertStringContainsString('id="p1"', $result);
        $this->assertStringContainsString('class="lead"', $result);
        $this->assertStringContainsString('style="font-size: 18px;"', $result);
        $this->assertStringContainsString('data-track="yes"', $result);
    }

    public function test_preserves_nested_inline_styles(): void
    {
        $html = '<div style="background: #eee;"><span style="color: red;">red on grey</span></div>';
        $result = $this->contentMigrator->processHtmlContent($html);
        $this->assertStringContainsString('style="background: #eee;"', $result);
        $this->assertStringContainsString('style="color: red;"', $result);
    }

    public function test_preserves_button_text_even_though_form_is_inert(): void
    {
        // Buttons in product descriptions usually carry literal copy ("Buy",
        // "Download"). Stripping the element nukes that copy. We keep the
        // element (it just won't fire anything because on* attrs are scrubbed).
        $html = '<button data-action="buy" class="btn" onclick="track()">Buy</button>';
        $result = $this->contentMigrator->processHtmlContent($html);
        $this->assertStringContainsString('Buy', $result);
        $this->assertStringContainsString('<button', $result);
        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringContainsString('data-action="buy"', $result);
    }

    public function test_strips_style_and_meta_link_base_noscript_tags(): void
    {
        $html = '<style>body { display: none }</style>'
            .'<link rel="stylesheet" href="/nope.css">'
            .'<meta http-equiv="refresh" content="0;url=evil">'
            .'<base href="https://evil.example.com">'
            .'<noscript>fallback</noscript>'
            .'<p>survivor</p>';

        $result = $this->contentMigrator->processHtmlContent($html);

        foreach (['<style', '<link', '<meta', '<base', '<noscript'] as $needle) {
            $this->assertStringNotContainsString($needle, $result);
        }
        $this->assertStringContainsString('survivor', $result);
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
