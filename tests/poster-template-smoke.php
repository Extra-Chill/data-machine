<?php
/**
 * Smoke test for the core PosterTemplate (#3614).
 *
 * Asserts:
 *  - contract: implements TemplateInterface, id `poster`, only `title` required
 *  - registered on the core datamachine/image_generation/templates filter
 *  - landscape_16_9 and print_tabloid presets ship in core
 *  - field normalization (trim, list filtering, non-scalar rejection)
 *  - layout planning: content clears the banner, slack is distributed,
 *    banner-less layouts, landscape columns never overlap
 *  - renders portrait and landscape PNGs at preset size, with and without
 *    a QR provider; provider-supplied QR files are consumed and deleted
 *  - missing title fails gracefully (empty result, no fatal)
 *  - the template names no domain concept or vendor
 *
 * Run with: php tests/poster-template-smoke.php
 *
 * @package DataMachine\Tests
 */

declare( strict_types = 1 );

$failed = 0;
$total  = 0;

$assert = static function ( string $name, bool $condition ) use ( &$failed, &$total ): void {
	++$total;
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		return;
	}
	echo "  FAIL: {$name}\n";
	++$failed;
};

$finish = static function () use ( &$failed, &$total ): void {
	if ( $failed > 0 ) {
		fwrite( fopen( 'php://stderr', 'w' ), "\nposter-template-smoke: {$failed}/{$total} assertions failed\n" );
		exit( 1 );
	}
	echo "\nAll {$total} poster-template assertions passed.\n";
};

$plugin_root = dirname( __DIR__ );

// -------------------------------------------------------------------------
// Source-string assertions.
// -------------------------------------------------------------------------

$provider    = (string) file_get_contents( $plugin_root . '/inc/Core/Bootstrap/HostIntegrationServiceProvider.php' );
$presets_src = (string) file_get_contents( $plugin_root . '/inc/Abilities/Media/PlatformPresets.php' );
$tmpl_source = (string) file_get_contents( $plugin_root . '/inc/Abilities/Media/Templates/PosterTemplate.php' );

$assert(
	'poster is registered on the core template filter',
	str_contains( $provider, "'datamachine/image_generation/templates'" )
		&& str_contains( $provider, "\$templates['poster']" )
		&& str_contains( $provider, 'PosterTemplate::class' )
);
$assert( 'core ships the landscape_16_9 preset', str_contains( $presets_src, "'landscape_16_9'" ) );
$assert( 'core ships the print_tabloid preset', str_contains( $presets_src, "'print_tabloid'" ) );
$assert( 'core ships the print_tabloid_300 preset', str_contains( $presets_src, "'print_tabloid_300'" ) );
$assert( 'core ships the print_pocket_flyer preset', str_contains( $presets_src, "'print_pocket_flyer'" ) );
$assert( 'QR codes resolve through a filter', str_contains( $tmpl_source, "'datamachine/image_generation/qr_image_path'" ) );
$assert(
	'template names no domain concept or vendor',
	! preg_match( '/event|venue|rsvp|extrachill|extra chill|ticket/i', $tmpl_source )
);

// -------------------------------------------------------------------------
// Minimal WordPress stubs.
// -------------------------------------------------------------------------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}

$GLOBALS['poster_smoke_qr'] = null;

if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ) {}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$rest ) {
		if ( 'datamachine/image_generation/qr_image_path' === $tag && is_callable( $GLOBALS['poster_smoke_qr'] ) ) {
			return ( $GLOBALS['poster_smoke_qr'] )( $value, ...$rest );
		}
		return $value;
	}
}
if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) {
		if ( is_string( $file ) && file_exists( $file ) ) {
			@unlink( $file ); // phpcs:ignore
		}
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return $default;
	}
}
if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory() {
		return '/tmp/datamachine-no-theme';
	}
}

require_once $plugin_root . '/inc/Abilities/Media/PlatformPresets.php';
require_once $plugin_root . '/inc/Abilities/Media/TemplateInterface.php';
require_once $plugin_root . '/inc/Abilities/Media/BrandTokens.php';
require_once $plugin_root . '/inc/Abilities/Media/GDRenderer.php';
require_once $plugin_root . '/inc/Abilities/Media/Templates/PosterTemplate.php';

use DataMachine\Abilities\Media\GDRenderer;
use DataMachine\Abilities\Media\PlatformPresets;
use DataMachine\Abilities\Media\TemplateInterface;
use DataMachine\Abilities\Media\Templates\PosterTemplate;

// -------------------------------------------------------------------------
// Contract + pure helpers.
// -------------------------------------------------------------------------

$template = new PosterTemplate();
$fields   = $template->get_fields();
$required = array_keys( array_filter( $fields, static fn( $f ) => ! empty( $f['required'] ) ) );

$assert( 'instance is a TemplateInterface', $template instanceof TemplateInterface );
$assert( 'get_id() returns poster', 'poster' === $template->get_id() );
$assert( 'only title is required', array( 'title' ) === $required );
$assert( 'default preset exists', null !== PlatformPresets::get( $template->get_default_preset() ) );
$assert( 'landscape_16_9 is 1920x1080', array( 'width' => 1920, 'height' => 1080 ) === PlatformPresets::dimensions( 'landscape_16_9' ) );
$assert( 'print_tabloid is 1650x2550', array( 'width' => 1650, 'height' => 2550 ) === PlatformPresets::dimensions( 'print_tabloid' ) );
$assert( 'print_tabloid_300 is 3300x5100', array( 'width' => 3300, 'height' => 5100 ) === PlatformPresets::dimensions( 'print_tabloid_300' ) );
$assert( 'print_pocket_flyer is 1275x1650', array( 'width' => 1275, 'height' => 1650 ) === PlatformPresets::dimensions( 'print_pocket_flyer' ) );

$norm = PosterTemplate::normalize_fields(
	array(
		'title'         => '  Never Miss The Show  ',
		'detail_lines'  => array( 'Find a show tonight', '', '  ', array( 'nested' ), 42 ),
		'banner_text'   => array( 'not', 'scalar' ),
		'cobrand_logos' => array( '/tmp/a.png', '', 7 ),
	)
);
$assert( 'normalize trims strings', 'Never Miss The Show' === $norm['title'] );
$assert( 'normalize keeps only non-empty scalar detail lines', array( 'Find a show tonight', '42' ) === $norm['detail_lines'] );
$assert( 'normalize rejects non-scalar strings', '' === $norm['banner_text'] );
$assert( 'normalize keeps only string logo paths', array( '/tmp/a.png' ) === $norm['cobrand_logos'] );
$assert( 'normalize fills every field', 17 === count( $norm ) && '' === $norm['qr_url'] );
$assert( 'scheme defaults to dark', 'dark' === $norm['scheme'] );
$assert( 'unknown scheme falls back to dark', 'dark' === PosterTemplate::normalize_fields( array( 'scheme' => 'neon' ) )['scheme'] );
$assert( 'scheme is case-insensitive', 'accent' === PosterTemplate::normalize_fields( array( 'scheme' => 'Accent' ) )['scheme'] );
$assert( 'qr_emphasis defaults to footer', 'footer' === $norm['qr_emphasis'] );
$assert( 'qr_emphasis accepts hero (case-insensitive)', 'hero' === PosterTemplate::normalize_fields( array( 'qr_emphasis' => 'Hero' ) )['qr_emphasis'] );
$assert( 'unknown qr_emphasis falls back to footer', 'footer' === PosterTemplate::normalize_fields( array( 'qr_emphasis' => 'giant' ) )['qr_emphasis'] );

$list = PosterTemplate::normalize_fields(
	array(
		'list_items' => array(
			'Plain string row',
			array( 'primary' => 'Band', 'secondary' => 'Venue', 'meta' => 'FRI 8PM' ),
			array( 'secondary' => 'no primary' ),
			array( 'primary' => array( 'bad' ) ),
			null,
		),
	)
)['list_items'];
$assert( 'list rows normalize strings and objects, drop rows without a primary', 2 === count( $list ) );
$assert( 'string row becomes a primary-only row', array( 'primary' => 'Plain string row', 'secondary' => '', 'meta' => '' ) === $list[0] );
$assert( 'object row keeps primary, secondary and meta', array( 'primary' => 'Band', 'secondary' => 'Venue', 'meta' => 'FRI 8PM' ) === $list[1] );

$tok = array( 'dark_background' => '#1a1a1a', 'dark_text' => '#e5e5e5', 'dark_accent' => '#6ab312', 'background' => '#ffffff', 'text_primary' => '#000000', 'accent' => '#53940b' );
$dark_p   = PosterTemplate::palette( $tok, 'dark' );
$accent_p = PosterTemplate::palette( $tok, 'accent' );
$light_p  = PosterTemplate::palette( $tok, 'light' );
$assert( 'dark palette: brand dark background, accent banner', '#1a1a1a' === $dark_p['background'] && '#6ab312' === $dark_p['banner'] );
$assert( 'accent palette: accent canvas, dark banner with accent text', '#6ab312' === $accent_p['background'] && '#1a1a1a' === $accent_p['banner'] && '#6ab312' === $accent_p['banner_text'] );
$assert( 'light palette: brand background and type', '#ffffff' === $light_p['background'] && '#000000' === $light_p['text'] );
$assert( 'each palette contrasts text with background', $dark_p['text'] !== $dark_p['background'] && $accent_p['text'] !== $accent_p['background'] && $light_p['text'] !== $light_p['background'] );

$unit = static fn( float $n ): int => (int) round( $n );
$assert( 'list height is zero without rows', 0 === PosterTemplate::list_height( PosterTemplate::normalize_fields( array() ), $unit ) );
$two  = PosterTemplate::list_height( PosterTemplate::normalize_fields( array( 'list_items' => array( 'a', 'b' ) ) ), $unit );
$four = PosterTemplate::list_height( PosterTemplate::normalize_fields( array( 'list_items' => array( 'a', 'b', 'c', 'd' ) ) ), $unit );
$assert( 'list height grows per row', $four === 2 * $two );

$assert( 'default kicker uses brand text', 'ACME PRESENTS' === PosterTemplate::default_kicker( 'Acme' ) );
$assert( 'default kicker is empty without a brand', '' === PosterTemplate::default_kicker( '  ' ) );

foreach ( array( 'instagram_feed_portrait' => array( 1080, 1350, 792 ), 'instagram_story' => array( 1080, 1920, 1076 ), 'print_tabloid' => array( 1650, 2550, 1076 ) ) as $preset => $spec ) {
	list( $w, $h, $content ) = $spec;
	$u      = $w / 1080;
	$margin = (int) round( 80 * $u );
	$plan   = PosterTemplate::plan_layout( $h, $margin, (int) round( $content * $u ), (int) round( 120 * $u ), (int) round( 210 * $u ), (int) round( 40 * $u ) );
	$assert( "{$preset}: content clears the banner", $plan['fits'] && $plan['content_bottom'] <= $plan['banner_top'] - (int) round( 40 * $u ) );
	$assert( "{$preset}: footer ends at the bottom margin", $h - $margin === $plan['footer_bottom'] );
}

$feed  = PosterTemplate::plan_layout( 1350, 80, 792, 120, 210, 40 );
$story = PosterTemplate::plan_layout( 1920, 80, 792, 120, 210, 40 );
$assert( 'tall canvases push content down', $story['content_top'] > $feed['content_top'] );
$assert( 'oversized content reports it does not fit', ! PosterTemplate::plan_layout( 1350, 80, 1076, 120, 210, 40 )['fits'] );

$no_banner = PosterTemplate::plan_layout( 1350, 80, 792, 0, 210, 40 );
$assert( 'banner-less layout gives the space back to content', $no_banner['banner_top'] === $feed['banner_top'] + 120 && $no_banner['fits'] );

$left = PosterTemplate::landscape_left_width( 1920, 100, 260, 80 );
$assert( 'landscape left column stops before the right column', 100 + $left <= 1920 - 100 - 260 - 80 );
$assert( 'landscape left column is full width without a right column', 1720 === PosterTemplate::landscape_left_width( 1920, 100, 0, 80 ) );

// -------------------------------------------------------------------------
// Behavioral renders (skipped if GD/freetype unavailable).
// -------------------------------------------------------------------------

if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagettftext' ) ) {
	echo "\nGD/freetype unavailable — behavioral render assertions skipped.\n";
	$finish();
	return;
}

$ad = array(
	'kicker'       => 'ACME EVENTS',
	'title'        => 'NEVER MISS THE SHOW',
	'tagline'      => '32,000+ upcoming shows at 3,200+ venues.',
	'subline'      => 'Free to use.',
	'detail_lines' => array( 'FIND A SHOW TONIGHT' ),
	'accent_line'  => 'EXAMPLE.COM',
	'detail_note'  => 'Mark it going. Get reminded.',
	'banner_text'  => 'FREE. NO APP NEEDED.',
	'qr_url'       => 'https://example.com/near-me/',
	'qr_label'     => 'SCAN TO FIND A SHOW',
	'cta_text'     => 'Start at example.com',
	'footer_note'  => 'Independent and open source.',
);

$render = static function ( array $data, string $preset ) use ( $template ): ?array {
	$paths = $template->render( $data, new GDRenderer(), array( 'preset' => $preset ) );
	$path  = $paths[0] ?? '';
	if ( ! $path || ! file_exists( $path ) ) {
		return null;
	}
	$info = getimagesize( $path );
	wp_delete_file( $path );
	return is_array( $info ) ? $info : null;
};

foreach ( array( 'instagram_feed_portrait', 'instagram_story', 'print_tabloid', 'landscape_16_9' ) as $preset ) {
	$dims = PlatformPresets::dimensions( $preset );
	$info = $render( $ad, $preset );
	$assert( "{$preset}: renders a PNG at preset size (no QR provider)", null !== $info && 'image/png' === $info['mime'] && $dims['width'] === $info[0] && $dims['height'] === $info[1] );
}

// With a QR provider: the provider's temp file is drawn and then deleted.
$qr_files                   = array();
$GLOBALS['poster_smoke_qr'] = static function ( $value, $url, $size ) use ( &$qr_files ) {
	$img = imagecreatetruecolor( max( 1, (int) $size ), max( 1, (int) $size ) );
	imagefill( $img, 0, 0, imagecolorallocate( $img, 255, 255, 255 ) );
	$path = tempnam( sys_get_temp_dir(), 'poster-qr' );
	imagepng( $img, $path );
	$qr_files[] = array( $path, $url );
	return $path;
};

foreach ( array( 'instagram_feed_portrait', 'landscape_16_9' ) as $preset ) {
	$info = $render( $ad, $preset );
	$assert( "{$preset}: renders with a QR provider", null !== $info );
}
$assert( 'QR provider received the qr_url', 2 === count( $qr_files ) && 'https://example.com/near-me/' === $qr_files[0][1] );
$assert( 'QR temp files are deleted after drawing', ! file_exists( $qr_files[0][0] ) && ! file_exists( $qr_files[1][0] ) );

// Minimal poster: title only, no banner, no QR, no footer.
$GLOBALS['poster_smoke_qr'] = null;
foreach ( array( 'instagram_feed_portrait', 'landscape_16_9' ) as $preset ) {
	$assert( "{$preset}: title-only poster renders", null !== $render( array( 'title' => 'HELLO' ), $preset ) );
}

// Schemes and list rows render at every orientation.
$rows = array();
for ( $i = 1; $i <= 12; $i++ ) {
	$rows[] = array( 'primary' => "A Very Long Band Name Number {$i} That Overflows", 'secondary' => 'Some Venue', 'meta' => 'SAT 9PM' );
}
foreach ( PosterTemplate::SCHEMES as $scheme ) {
	foreach ( array( 'instagram_feed_portrait', 'instagram_story', 'landscape_16_9' ) as $preset ) {
		$info = $render( $ad + array( 'scheme' => $scheme, 'list_heading' => 'THIS WEEKEND', 'list_items' => $rows ), $preset );
		$assert( "{$scheme} / {$preset}: renders with an overlong list", null !== $info );
	}
}

$assert( 'missing title returns empty without fatal', array() === $template->render( array( 'tagline' => 'x' ), new GDRenderer() ) );

// -------------------------------------------------------------------------
// QR-hero layout (#3621).
// -------------------------------------------------------------------------

$hero_card = PosterTemplate::hero_card( 1485, 150 );
$assert( 'hero card quiet zone is at least 4 modules', $hero_card['pad'] >= (int) ceil( 1485 * 4 / 33 ) );
$assert( 'hero card wraps QR plus quiet zone', $hero_card['width'] === 1485 + 2 * $hero_card['pad'] );

$hero_plan = PosterTemplate::plan_hero_layout( 5100, 244, 1200, 367, 2400, 122 );
$assert( 'hero plan pins the card to the bottom margin', 5100 - 244 - 2400 === $hero_plan['card_top'] );
$assert( 'hero plan stacks content, banner and card without overlap', $hero_plan['fits'] && $hero_plan['content_bottom'] + 122 <= $hero_plan['banner_top'] && $hero_plan['banner_top'] + 367 + 122 <= $hero_plan['card_top'] );
$assert( 'oversized hero content reports it does not fit', ! PosterTemplate::plan_hero_layout( 1650, 94, 2000, 142, 900, 47 )['fits'] );

// The issue #3621 example: a QR-first print sign with no banner.
$hero_data = array(
	'kicker'       => 'ACME PRESENTS',
	'title'        => 'EXAMPLE CONTEST',
	'tagline'      => 'Enter your thing. Get your friends to vote.',
	'detail_lines' => array( 'Top 10 take the stage' ),
	'accent_line'  => 'SAT JAN 1 · SOMEWHERE',
	'qr_url'       => 'https://example.com/contest/?qr=1',
	'qr_label'     => 'SCAN TO ENTER & VOTE',
	'qr_emphasis'  => 'hero',
);

// Hero without a QR provider falls back to the standard layout cleanly.
$GLOBALS['poster_smoke_qr'] = null;
foreach ( array( 'print_tabloid_300', 'print_pocket_flyer', 'landscape_16_9' ) as $preset ) {
	$dims = PlatformPresets::dimensions( $preset );
	$info = $render( $hero_data, $preset );
	$assert( "{$preset}: hero renders at preset size without a QR provider", null !== $info && $dims['width'] === $info[0] && $dims['height'] === $info[1] );
}

// Hero with a QR provider: the final request matches the drawn hero size.
$qr_sizes                   = array();
$GLOBALS['poster_smoke_qr'] = static function ( $value, $url, $size ) use ( &$qr_sizes ) {
	$qr_sizes[] = (int) $size;
	$img        = imagecreatetruecolor( max( 1, (int) $size ), max( 1, (int) $size ) );
	imagefill( $img, 0, 0, imagecolorallocate( $img, 0, 0, 0 ) );
	$path = tempnam( sys_get_temp_dir(), 'poster-qr' );
	imagepng( $img, $path );
	return $path;
};

foreach ( array( 'print_tabloid_300' => 3300, 'print_pocket_flyer' => 1275 ) as $preset => $w ) {
	$qr_sizes = array();
	$info     = $render( $hero_data, $preset );
	$final    = $qr_sizes ? (int) end( $qr_sizes ) : 0;
	$assert( "{$preset}: hero renders with a QR provider", null !== $info );
	$assert( "{$preset}: hero QR is at least 45% of the canvas width", $final >= (int) round( $w * PosterTemplate::HERO_QR_MIN_RATIO ) );
}

$qr_sizes = array();
$info     = $render( $hero_data, 'landscape_16_9' );
$final    = $qr_sizes ? (int) end( $qr_sizes ) : 0;
$assert( 'landscape_16_9: hero QR is at least 40% of the canvas height', null !== $info && $final >= (int) round( 1080 * PosterTemplate::HERO_QR_MIN_RATIO_LANDSCAPE ) );
$GLOBALS['poster_smoke_qr'] = null;

$finish();
