<?php
/**
 * Smoke test: event descriptions never nest block-level HTML inside paragraphs (#4006).
 *
 * Run: php tests/event-description-blocks-smoke.php
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	// Minimal serialize_blocks() stub: enough to round-trip the stubbed converter output.
	function serialize_blocks( $blocks ) {
		$out = array();
		foreach ( $blocks as $block ) {
			$name  = preg_replace( '#^core/#', '', $block['blockName'] );
			$out[] = '<!-- wp:' . $name . ' -->' . "\n" . $block['innerHTML'] . "\n" . '<!-- /wp:' . $name . ' -->';
		}
		return implode( "\n\n", $out );
	}

	$GLOBALS['h2b_calls'] = array();
}

namespace DataMachineEvents\Core {
	class AffiliateRedirectShape {}
	class Event_Post_Type { const EVENT_DETAILS_BLOCK_NAME = 'data-machine-events/event-details'; }
	class Event_Type_Taxonomy {}
	class TextNormalization {}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Steps/Upsert/Events/EventBlockContentBuilder.php';

	use DataMachineEvents\Steps\Upsert\Events\EventBlockContentBuilder;

	$failures = 0;
	$assert   = static function ( $ok, $msg ) use ( &$failures ) {
		if ( ! $ok ) {
			fwrite( STDERR, 'FAIL: ' . $msg . PHP_EOL );
			++$failures;
			return;
		}
		echo 'ok - ' . $msg . PHP_EOL;
	};

	$builder = new EventBlockContentBuilder();
	$method  = new ReflectionMethod( EventBlockContentBuilder::class, 'generate_description_blocks' );
	$method->setAccessible( true );
	$gen = static function ( $html ) use ( $builder, $method ) {
		return $method->invoke( $builder, $html );
	};

	// No block-level element may ever appear inside a <p>.
	$no_nested_block_in_p = static function ( $out ) {
		return 0 === preg_match( '#<p[^>]*>(?:(?!</p>).)*<(?:figure|h[1-6]|ul|ol|div|blockquote|table|pre|hr)[\s>/]#is', $out );
	};

	$heading = "<h2>Lineup</h2>\n\n<p>Doors at 7.</p>";
	$list    = "<p>Bring:</p>\n<ul>\n<li>ID</li>\n\n<li>Earplugs</li>\n</ul>";
	$figure  = '<figure class="wp-block-image size-large"><img src="https://example.com/a.jpg" alt=""/><figcaption>Live</figcaption></figure><p>Great show.</p>';
	$buttons = '<div class="wp-block-buttons"><div class="wp-block-button"><a class="wp-block-button__link" href="https://example.com/t">Tickets</a></div></div>';
	$img     = '<img src="https://example.com/b.jpg" alt=""/>';

	// ---- Converter missing: fallback to core/html, never <p>. ----
	$assert( ! function_exists( 'html_to_blocks_convert' ), 'converter is absent for fallback phase' );
	foreach ( array( 'heading' => $heading, 'list' => $list, 'figure' => $figure, 'buttons' => $buttons, 'standalone img' => $img ) as $label => $html ) {
		$out = $gen( $html );
		$assert( 0 === strpos( $out, '<!-- wp:html -->' ) && false !== strpos( $out, '<!-- /wp:html -->' ), "fallback wraps $label in wp:html" );
		$assert( false !== strpos( $out, $html ), "fallback preserves $label markup intact" );
		$assert( false === strpos( $out, 'wp:paragraph' ) && $no_nested_block_in_p( $out ), "fallback does not paragraph-wrap $label" );
	}

	// ---- Pre-blocked content passes through unchanged (trimmed). ----
	$blocked = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Hi</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Text</p>\n<!-- /wp:paragraph -->";
	$assert( $blocked === $gen( "\n  " . $blocked . "\n\n" ), 'pre-blocked content passes through unchanged' );

	// ---- Plain text with blank lines keeps paragraph behaviour. ----
	$plain = $gen( "First line.\n\nSecond <strong>bold</strong> line.\n\n\nThird." );
	$assert( 3 === substr_count( $plain, '<!-- wp:paragraph -->' ), 'plain text splits into three paragraphs' );
	$assert( false !== strpos( $plain, "<p>Second <strong>bold</strong> line.</p>" ), 'inline HTML kept inside paragraph' );
	$inline = $gen( '<p>One <a href="https://x.test">link</a></p><p>Two <img src="https://x.test/i.png" alt=""/></p>' );
	$assert( 2 === substr_count( $inline, '<!-- wp:paragraph -->' ) && false === strpos( $inline, 'wp:html' ), 'inline-only <p> HTML (incl. inline img) stays paragraphs' );
	$assert( '' === $gen( "  \n " ), 'empty description yields no blocks' );

	// ---- Converter present: block-level HTML is delegated to it. ----
	// Declared conditionally so PHP does not hoist it ahead of the fallback phase.
	if ( ! function_exists( 'html_to_blocks_convert' ) ) {
		function html_to_blocks_convert( $html ) {
			$GLOBALS['h2b_calls'][] = $html;
			$blocks = array();
			if ( preg_match_all( '#<(h[1-6]|ul|ol|figure|div|p)\b[^>]*>.*?</\1>#is', $html, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $match ) {
					$map      = array( 'ul' => 'list', 'ol' => 'list', 'figure' => 'image', 'div' => 'buttons', 'p' => 'paragraph' );
					$tag      = strtolower( $match[1] );
					$blocks[] = array(
						'blockName' => 'core/' . ( $map[ $tag ] ?? 'heading' ),
						'innerHTML' => $match[0],
					);
				}
			}
			return $blocks;
		}
	}

	$cases = array(
		'heading' => array( $heading, 'wp:heading' ),
		'list'    => array( $list, 'wp:list' ),
		'figure'  => array( $figure, 'wp:image' ),
		'buttons' => array( $buttons, 'wp:buttons' ),
	);
	foreach ( $cases as $label => list( $html, $expected ) ) {
		$GLOBALS['h2b_calls'] = array();
		$out = $gen( $html );
		$assert( array( $html ) === $GLOBALS['h2b_calls'], "converter receives whole $label HTML unsplit" );
		$assert( false !== strpos( $out, '<!-- ' . $expected . ' -->' ), "$label converted to $expected" );
		$assert( $no_nested_block_in_p( $out ), "$label output has no block-level HTML nested in <p>" );
	}

	// Regression for event 714188: figure must not land inside a paragraph.
	$out = $gen( $figure );
	$assert( false === strpos( $out, '<p><figure' ) && false === strpos( $out, '<p class="wp-block-paragraph"><figure' ), '714188 regression: no <p><figure>' );

	// Converter returning nothing falls back to wp:html.
	$GLOBALS['h2b_calls'] = array();
	$out = $gen( '<hr/>' );
	$assert( '<!-- wp:html -->' . "\n<hr/>\n" . '<!-- /wp:html -->' === $out, 'empty converter result falls back to wp:html' );

	// Plain text never touches the converter.
	$GLOBALS['h2b_calls'] = array();
	$gen( "Just text.\n\nMore text." );
	$assert( array() === $GLOBALS['h2b_calls'], 'plain text bypasses converter' );

	if ( $failures > 0 ) {
		fwrite( STDERR, "event-description-blocks-smoke: $failures failure(s)\n" );
		exit( 1 );
	}
	echo "event-description-blocks-smoke: ok\n";
}
