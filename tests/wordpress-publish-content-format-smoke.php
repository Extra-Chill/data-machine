<?php
/**
 * Pure-PHP smoke test for WordPress publish content-format handling.
 *
 * Run with: php tests/wordpress-publish-content-format-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core {
    class PluginSettings {

        public static function get( string $key, array $default = array() ): array {
            unset( $key );
            return $default;
        }
    }
}

namespace {
    if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', __DIR__ . '/' );
    }

    $failed = 0;
    $total  = 0;

    function assert_publish_format( string $name, bool $condition ): void {
        global $failed, $total;
        ++$total;
        if ( $condition ) {
            echo "  PASS: {$name}\n";
            return;
        }
        echo "  FAIL: {$name}\n";
        ++$failed;
    }

    class WP_Error {

        private string $code;
        private string $message;

        public function __construct( string $code = '', string $message = '', $data = null ) {
            unset( $data );
            $this->code    = $code;
            $this->message = $message;
        }

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message(): string {
            return $this->message;
        }

        public function get_error_data() {
            return null;
        }
    }

    $GLOBALS['__publish_format_filters']     = array();
    $GLOBALS['__publish_format_posts']       = array();
    $GLOBALS['__publish_format_meta']        = array();
    $GLOBALS['__publish_format_next_id']     = 100;
    $GLOBALS['__publish_format_conversions'] = array();

    function doing_action( string $hook ): bool {
        unset( $hook );
        return false;
    }

    function did_action( string $hook ): int {
        unset( $hook );
        return 1;
    }

    function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $GLOBALS['__publish_format_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
    }

    function apply_filters( string $hook, $value, ...$args ) {
        if ( empty( $GLOBALS['__publish_format_filters'][ $hook ] ) ) {
            return $value;
        }

        ksort( $GLOBALS['__publish_format_filters'][ $hook ] );
        foreach ( $GLOBALS['__publish_format_filters'][ $hook ] as $callbacks ) {
            foreach ( $callbacks as $registered_callback ) {
                list( $callback, $accepted_args ) = $registered_callback;
                $value                            = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
            }
        }
        return $value;
    }

    function sanitize_key( $key ): string {
        return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
    }

    function sanitize_text_field( $value ): string {
        return trim( (string) $value );
    }

    function wp_unslash( $value ) {
        return $value;
    }

    function wp_strip_all_tags( $value ): string {
        return strip_tags( (string) $value );
    }

    function wp_filter_post_kses( $content ): string {
        return (string) $content;
    }

    function esc_url( $url ): string {
        $sanitized = filter_var( (string) $url, FILTER_SANITIZE_URL );
        return is_string( $sanitized ) ? $sanitized : '';
    }

    function esc_url_raw( $url ): string {
        return esc_url( $url );
    }

    function esc_html( $text ): string {
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
    }

    function __( $text, $domain = 'default' ) {
        unset( $domain );
        return $text;
    }

    function is_wp_error( $thing ): bool {
        return $thing instanceof WP_Error;
    }

    function get_current_user_id(): int {
        return 0;
    }

    function get_users( array $args = array() ): array {
        unset( $args );
        return array( 1 );
    }

    function wp_insert_post( array $post_data, bool $wp_error = false ) {
        unset( $wp_error );
        $id = $GLOBALS['__publish_format_next_id']++;

        $post_data['ID']                          = $id;
        $GLOBALS['__publish_format_posts'][ $id ] = (object) $post_data;

        return $id;
    }

    function get_permalink( int $post_id ): string {
        return "https://example.test/?p={$post_id}";
    }

    function update_post_meta( int $post_id, string $key, $value ): void {
        $GLOBALS['__publish_format_meta'][ $post_id ][ $key ] = $value;
    }

    function bfb_convert( string $content, string $from, string $to ) {
        $GLOBALS['__publish_format_conversions'][] = array( $from, $to, $content );

        if ( $from === $to ) {
            return $content;
        }

        if ( 'html' === $from && 'blocks' === $to ) {
            return "<!-- wp:paragraph -->\n{$content}\n<!-- /wp:paragraph -->";
        }

        if ( 'markdown' === $from && 'blocks' === $to ) {
            $html = preg_replace( '/^# (.+)$/m', '<h1>$1</h1>', $content );
            $html = preg_replace( '/\*\*([^*]+)\*\* \[([^\]]+)\]\(([^)]+)\)/', '<strong>$1</strong> <a href="$3">$2</a>', $html );
            return "<!-- wp:paragraph -->\n{$html}\n<!-- /wp:paragraph -->";
        }

        return new WP_Error( 'unsupported', "Unsupported {$from} to {$to}." );
    }

    function bfb_normalize( string $content, string $format ) {
        if ( 'blocks' === $format && str_contains( $content, '<!-- wp:' ) && ! str_contains( $content, '<!-- /wp:' ) ) {
            return new WP_Error( 'bfb_blocks_unclosed_comment', 'Serialized block markup contains an unclosed block comment.' );
        }

        return str_replace( array( "\r\n", "\r" ), "\n", $content );
    }

    function published_post_content( array $result ): string {
        $post_id = isset( $result['post_id'] ) ? (int) $result['post_id'] : 0;
        $post    = $GLOBALS['__publish_format_posts'][ $post_id ] ?? null;

        return is_object( $post ) && isset( $post->post_content ) ? (string) $post->post_content : '';
    }

    add_filter(
        'datamachine_post_content_format',
        static function ( string $format, string $post_type ): string {
            return 'wiki' === $post_type ? 'markdown' : $format;
        },
        10,
        2
    );

    require_once dirname( __DIR__ ) . '/inc/Core/Content/ContentFormat.php';
    require_once dirname( __DIR__ ) . '/inc/Core/WordPress/PostTracking.php';
    require_once dirname( __DIR__ ) . '/inc/Core/WordPress/WordPressSettingsResolver.php';
    require_once dirname( __DIR__ ) . '/inc/Core/WordPress/WordPressPublishHelper.php';
    require_once dirname( __DIR__ ) . '/inc/Abilities/Publish/PublishWordPressAbility.php';

    $ability = new \DataMachine\Abilities\Publish\PublishWordPressAbility();

    $html_result = $ability->execute(
        array(
            'title'      => 'HTML post',
            'content'    => '<p>Hello HTML.</p>',
            'post_type'  => 'post',
            'source_url' => 'https://example.test/source',
        )
    );
    $html_content = published_post_content( $html_result );
    assert_publish_format( 'default-html-publish-succeeds', true === ( $html_result['success'] ?? false ) );
    assert_publish_format( 'default-html-converts-to-block-storage', false !== strpos( $html_content, '<!-- wp:paragraph -->' ) );
    assert_publish_format( 'default-html-attribution-converted-with-content', false !== strpos( $html_content, '<strong>Source:</strong>' ) );

    $explicit_html_result = $ability->execute(
        array(
            'title'          => 'Explicit HTML post',
            'content'        => '<p>Explicit HTML.</p>',
            'content_format' => 'html',
            'post_type'      => 'post',
        )
    );
    $explicit_html_content = published_post_content( $explicit_html_result );
    assert_publish_format( 'explicit-html-publish-succeeds', true === ( $explicit_html_result['success'] ?? false ) );
    assert_publish_format( 'explicit-html-converts-to-block-storage', false !== strpos( $explicit_html_content, '<!-- wp:paragraph -->' ) );

    $markdown_result = $ability->execute(
        array(
            'title'          => 'Markdown post',
            'content'        => "# Markdown\n\nBody.",
            'content_format' => 'markdown',
            'post_type'      => 'post',
            'source_url'     => 'https://example.test/markdown',
        )
    );
    $markdown_content = published_post_content( $markdown_result );
    assert_publish_format( 'markdown-source-publish-succeeds', true === ( $markdown_result['success'] ?? false ) );
    assert_publish_format( 'markdown-source-converts-to-block-storage', false !== strpos( $markdown_content, '<!-- wp:paragraph -->' ) );
    assert_publish_format( 'markdown-source-attribution-starts-as-markdown', false !== strpos( $markdown_content, '<strong>Source:</strong>' ) );

    $blocks_result = $ability->execute(
        array(
            'title'          => 'Blocks post',
            'content'        => "<!-- wp:paragraph -->\n<p>Block content.</p>\n<!-- /wp:paragraph -->",
            'content_format' => 'blocks',
            'post_type'      => 'post',
            'source_url'     => 'https://example.test/blocks',
        )
    );
    $blocks_content = published_post_content( $blocks_result );
    assert_publish_format( 'blocks-source-publish-succeeds', true === ( $blocks_result['success'] ?? false ) );
    assert_publish_format( 'blocks-source-stays-block-storage', false !== strpos( $blocks_content, '<!-- wp:paragraph -->' ) );
    assert_publish_format( 'blocks-source-attribution-stays-blocks', false !== strpos( $blocks_content, 'https://example.test/blocks' ) );

    $wiki_result = $ability->execute(
        array(
            'title'          => 'Markdown wiki',
            'content'        => "# Wiki\n\nStored as markdown.",
            'content_format' => 'markdown',
            'post_type'      => 'wiki',
            'source_url'     => 'https://example.test/wiki',
        )
    );
    $wiki_content = published_post_content( $wiki_result );
    assert_publish_format( 'markdown-backed-post-type-publish-succeeds', true === ( $wiki_result['success'] ?? false ) );
    assert_publish_format( 'markdown-backed-post-type-stores-markdown', false === strpos( $wiki_content, '<!-- wp:' ) );
    assert_publish_format( 'markdown-backed-attribution-stays-markdown', false !== strpos( $wiki_content, '**Source:** [https://example.test/wiki](https://example.test/wiki)' ) );

    $ability_source = file_get_contents( dirname( __DIR__ ) . '/inc/Abilities/Publish/PublishWordPressAbility.php' );
    $handler_source = file_get_contents( dirname( __DIR__ ) . '/inc/Core/Steps/Publish/Handlers/WordPress/WordPress.php' );
    assert_publish_format( 'ability-schema-exposes-content-format', false !== strpos( $ability_source, "'content_format'" ) );
    assert_publish_format( 'handler-tool-schema-exposes-content-format', false !== strpos( $handler_source, "'content_format'" ) );
    assert_publish_format( 'handler-tool-no-longer-demands-html-only', false === strpos( $handler_source, 'content in HTML format' ) );

    echo "\nWordPress publish content-format smoke: {$total} assertions, {$failed} failures.\n";

    exit( min( 1, $failed ) );
}
