<?php
/**
 * Tests for WordPressPublishHelper source attribution.
 *
 * @package DataMachine\Tests\Unit\Core\WordPress
 */

namespace DataMachine\Tests\Unit\Core\WordPress;

use DataMachine\Core\WordPress\WordPressPublishHelper;
use WP_UnitTestCase;

class WordPressPublishHelperTest extends WP_UnitTestCase {

    public function test_appends_block_attribution_when_blocks_present(): void {
        $content = "<!-- wp:paragraph -->\n<p>Content</p>\n<!-- /wp:paragraph -->";

        $result = WordPressPublishHelper::applySourceAttribution(
            $content,
            'https://example.com',
            ['link_handling' => 'append']
        );

        $expected_append = "\n\n<!-- wp:paragraph -->\n<p><strong>Source:</strong> <a href=\"https://example.com\">https://example.com</a></p>\n<!-- /wp:paragraph -->";

        $this->assertSame($content . $expected_append, $result);
    }

    public function test_appends_html_attribution_when_no_blocks_present(): void {
        $content = '<p>Content</p>';

        $result = WordPressPublishHelper::applySourceAttribution(
            $content,
            'https://example.com',
            ['link_handling' => 'append']
        );

        $expected_append = "\n\n<p><strong>Source:</strong> <a href=\"https://example.com\">https://example.com</a></p>";

        $this->assertSame($content . $expected_append, $result);
    }

    public function test_skips_attribution_when_url_is_invalid(): void {
        $content = '<p>Content</p>';

        $result = WordPressPublishHelper::applySourceAttribution(
            $content,
            'not-a-url',
            ['link_handling' => 'append']
        );

        $this->assertSame($content, $result);
    }
}
