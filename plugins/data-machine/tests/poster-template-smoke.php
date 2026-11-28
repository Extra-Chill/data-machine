<?php
/**
 * Smoke test for the core PosterTemplate (#3614).
 *
 * Asserts:
 *  - contract: implements TemplateInterface, id `poster`, only `title` required
 *  - registered on the core datamachine/image_generation/templates filter
 *  - landscape_16_9 and print_tabloid presets ship in core
 *  - field normalization (trim, list filtering, non-scalar rejection)
 *  - background treatments (#3623): default stripes unchanged, every
 *    value renders at preset size on print_tabloid_300 and
 *    landscape_16_9 (footer and hero modes), cover-fit math, and a bad
 *    background image path falls back to stripes without error
 *  - background_strength (#3628): normalization, bold ink stronger than
 *    subtle, bold roughly doubles the subtle ink and thickens strokes,
 *    and bold/subtle render different bytes
 *  - hero portrait title autoscale (#3628): the widest wrapped line of a
 *    short two-word title fills at least 80% of the text width on both
 *    print presets, never below the base size, and the fitted posters
 *    keep the QR at its guaranteed minimum (no content/QR overlap)
 *  - layout planning: content clears the banner, slack is distributed,
 *    banner-less layouts, landscape columns never overlap
 *  - hero QR controls (#3631): fit order keeps a 5-step numbered list
 *    over QR size, qr_quiet_modules tightens the card, an outside label
 *    sits above the card bounds, qr_card_color and list_style normalize
 *    with safe fallbacks, numbered lists measure and render
 *  - header co-brand logos (#3631): equal heights beside the brand logo,
 *    overwide clusters scale down together, the hero title width clears
 *    the cluster
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
$helpers_source = (string) file_get_contents( $plugin_root . '/inc/Abilities/Media/Templates/TemplateHelpers.php' );
$assert( 'QR codes resolve through a shared filter', str_contains( $helpers_source, "'datamachine/image_generation/qr_image_path'" ) && str_contains( $tmpl_source, 'TemplateHelpers::qr_image_path' ) );
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
require_once $plugin_root . '/inc/Abilities/Media/Templates/TemplateHelpers.php';
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
$assert( 'normalize fills every field', 27 === count( $norm ) && '' === $norm['qr_url'] );
$assert( 'scheme defaults to dark', 'dark' === $norm['scheme'] );
$assert( 'unknown scheme falls back to dark', 'dark' === PosterTemplate::normalize_fields( array( 'scheme' => 'neon' ) )['scheme'] );
$assert( 'scheme is case-insensitive', 'accent' === PosterTemplate::normalize_fields( array( 'scheme' => 'Accent' ) )['scheme'] );
$assert( 'qr_emphasis defaults to footer', 'footer' === $norm['qr_emphasis'] );
$assert( 'qr_emphasis accepts hero (case-insensitive)', 'hero' === PosterTemplate::normalize_fields( array( 'qr_emphasis' => 'Hero' ) )['qr_emphasis'] );
$assert( 'unknown qr_emphasis falls back to footer', 'footer' === PosterTemplate::normalize_fields( array( 'qr_emphasis' => 'giant' ) )['qr_emphasis'] );

// Hero QR controls and list style (#3631): normalization fallbacks.
$assert( 'qr_quiet_modules defaults to 4', 4 === $norm['qr_quiet_modules'] && 4 === PosterTemplate::HERO_QUIET_MODULES );
$assert(
	'qr_quiet_modules is clamped to 1-4',
	1 === PosterTemplate::normalize_fields( array( 'qr_quiet_modules' => 0 ) )['qr_quiet_modules']
		&& 4 === PosterTemplate::normalize_fields( array( 'qr_quiet_modules' => 9 ) )['qr_quiet_modules']
		&& 2 === PosterTemplate::normalize_fields( array( 'qr_quiet_modules' => '2' ) )['qr_quiet_modules']
);
$assert( 'non-numeric qr_quiet_modules falls back to 4', 4 === PosterTemplate::normalize_fields( array( 'qr_quiet_modules' => 'roomy' ) )['qr_quiet_modules'] );
$assert( 'qr_label_position defaults to card', 'card' === $norm['qr_label_position'] && 'card' === PosterTemplate::QR_LABEL_POSITIONS[0] );
$assert( 'unknown qr_label_position falls back to card', 'card' === PosterTemplate::normalize_fields( array( 'qr_label_position' => 'under' ) )['qr_label_position'] );
$assert( 'qr_label_position accepts outside (case-insensitive)', 'outside' === PosterTemplate::normalize_fields( array( 'qr_label_position' => 'Outside' ) )['qr_label_position'] );
$assert( 'qr_card_color defaults to white', '#ffffff' === $norm['qr_card_color'] );
$assert( 'qr_card_color normalizes a hex color', '#f5f0e6' === PosterTemplate::normalize_fields( array( 'qr_card_color' => 'F5F0E6' ) )['qr_card_color'] );
$assert( 'invalid qr_card_color falls back to white', '#ffffff' === PosterTemplate::normalize_fields( array( 'qr_card_color' => 'cream' ) )['qr_card_color'] );
$assert( 'list_style defaults to rows', 'rows' === $norm['list_style'] && 'rows' === PosterTemplate::LIST_STYLES[0] );
$assert( 'unknown list_style falls back to rows', 'rows' === PosterTemplate::normalize_fields( array( 'list_style' => 'bullets' ) )['list_style'] );
$assert( 'list_style accepts numbered (case-insensitive)', 'numbered' === PosterTemplate::normalize_fields( array( 'list_style' => 'Numbered' ) )['list_style'] );
$assert( 'header_logos keeps only string paths', array( '/tmp/h.png' ) === PosterTemplate::normalize_fields( array( 'header_logos' => array( '/tmp/h.png', '', 3 ) ) )['header_logos'] );
$assert( 'get_fields declares the new QR, list and header-logo fields', isset( $fields['qr_quiet_modules'], $fields['qr_label_position'], $fields['qr_card_color'], $fields['list_style'], $fields['header_logos'] ) );
$assert( 'qr_card_color field documents the light requirement', str_contains( (string) $fields['qr_card_color']['label'], 'light' ) );
$assert( 'background defaults to stripes', 'stripes' === $norm['background'] && 'stripes' === PosterTemplate::BACKGROUNDS[0] );
$assert( 'unknown background falls back to stripes', 'stripes' === PosterTemplate::normalize_fields( array( 'background' => 'plaid' ) )['background'] );
$assert( 'background is case-insensitive', 'halftone' === PosterTemplate::normalize_fields( array( 'background' => 'Halftone' ) )['background'] );
$assert(
	'background constant lists every treatment',
	array( 'stripes', 'none', 'grid', 'dots', 'halftone', 'crosshatch', 'waves', 'gradient', 'glow', 'image' ) === PosterTemplate::BACKGROUNDS
);
$bg_listed = true;
foreach ( PosterTemplate::BACKGROUNDS as $bg_value ) {
	$bg_listed = $bg_listed && str_contains( (string) $fields['background']['label'], $bg_value );
}
$assert( 'get_fields lists every background value', $bg_listed );
$assert( 'get_fields declares background_image and background_overlay', isset( $fields['background_image'], $fields['background_tint'], $fields['background_overlay'] ) );
$assert( 'background_overlay defaults to 0.55', 0.55 === $norm['background_overlay'] );
$assert(
	'background_overlay is clamped to 0..1',
	1.0 === PosterTemplate::normalize_fields( array( 'background_overlay' => 7 ) )['background_overlay']
		&& 0.0 === PosterTemplate::normalize_fields( array( 'background_overlay' => -3 ) )['background_overlay']
);
$assert( 'non-numeric background_overlay falls back to the default', 0.55 === PosterTemplate::normalize_fields( array( 'background_overlay' => 'lots' ) )['background_overlay'] );
$assert( 'background_tint normalizes a hex color', '#aabbcc' === PosterTemplate::normalize_fields( array( 'background_tint' => 'AABBCC' ) )['background_tint'] );
$assert( 'invalid background_tint is dropped', '' === PosterTemplate::normalize_fields( array( 'background_tint' => 'red' ) )['background_tint'] );

// Background strength (#3628): subtle | normal (default) | bold.
$assert( 'background_strength defaults to normal', 'normal' === $norm['background_strength'] && 'normal' === PosterTemplate::BACKGROUND_STRENGTHS[0] );
$assert( 'unknown background_strength falls back to normal', 'normal' === PosterTemplate::normalize_fields( array( 'background_strength' => 'shouty' ) )['background_strength'] );
$assert( 'background_strength is case-insensitive', 'bold' === PosterTemplate::normalize_fields( array( 'background_strength' => 'Bold' ) )['background_strength'] );
$assert( 'get_fields declares background_strength', isset( $fields['background_strength'] ) );
$assert(
	'background_strength constant lists every value',
	array( 'normal', 'subtle', 'bold' ) === PosterTemplate::BACKGROUND_STRENGTHS
);

$ink_subtle = PosterTemplate::background_ink( 'subtle' );
$ink_normal = PosterTemplate::background_ink( 'normal' );
$ink_bold   = PosterTemplate::background_ink( 'bold' );
$assert(
	'bold ink alpha is stronger than subtle',
	$ink_bold['stripe_alpha'] < $ink_subtle['stripe_alpha'] && $ink_bold['pattern_alpha'] < $ink_subtle['pattern_alpha']
);
$assert(
	'bold roughly doubles the subtle ink opacity',
	( 127 - $ink_bold['stripe_alpha'] ) >= 2 * ( 127 - $ink_subtle['stripe_alpha'] )
		&& ( 127 - $ink_bold['pattern_alpha'] ) >= 2 * ( 127 - $ink_subtle['pattern_alpha'] )
);
$assert( 'bold thickens strokes by 1.5x', $ink_bold['weight'] >= 1.5 * $ink_subtle['weight'] );
$assert(
	'normal sits between subtle and bold',
	$ink_normal['stripe_alpha'] <= $ink_subtle['stripe_alpha'] && $ink_normal['stripe_alpha'] >= $ink_bold['stripe_alpha']
		&& $ink_normal['weight'] >= $ink_subtle['weight'] && $ink_normal['weight'] <= $ink_bold['weight']
);
$assert( 'unknown strength resolves to the normal ink', PosterTemplate::background_ink( 'shouty' ) === $ink_normal );

// Pure cover-fit math: scale to fill, center-crop the overflow.
$assert(
	'cover-fit crops a wide source horizontally',
	array( 'src_x' => 150, 'src_y' => 0, 'src_w' => 100, 'src_h' => 100 ) === GDRenderer::cover_crop( 400, 100, 100, 100 )
);
$assert(
	'cover-fit crops a tall source vertically',
	array( 'src_x' => 0, 'src_y' => 150, 'src_w' => 100, 'src_h' => 100 ) === GDRenderer::cover_crop( 100, 400, 100, 100 )
);
$assert(
	'cover-fit leaves a matching aspect uncropped',
	array( 'src_x' => 0, 'src_y' => 0, 'src_w' => 800, 'src_h' => 600 ) === GDRenderer::cover_crop( 800, 600, 400, 300 )
);
$assert(
	'cover-fit crops a wide source for a portrait destination',
	array( 'src_x' => 333, 'src_y' => 0, 'src_w' => 333, 'src_h' => 500 ) === GDRenderer::cover_crop( 1000, 500, 200, 300 )
);

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

// Numbered rows reserve the step number height, so fit planning matches.
$two_numbered = PosterTemplate::list_height(
	PosterTemplate::normalize_fields(
		array(
			'list_items' => array( 'a', 'b' ),
			'list_style' => 'numbered',
		)
	),
	$unit
);
$assert( 'numbered rows reserve the step number height', $two_numbered > $two );

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
// Background treatments (#3623).
// -------------------------------------------------------------------------

// Small GD fixture for background: image (cover-fit source).
$bg_fixture = tempnam( sys_get_temp_dir(), 'poster-bg' ) . '.png';
$bg_img     = imagecreatetruecolor( 64, 48 );
imagefill( $bg_img, 0, 0, imagecolorallocate( $bg_img, 40, 80, 160 ) );
imagepng( $bg_img, $bg_fixture );
unset( $bg_img );

// Every background value renders at preset size, footer mode.
foreach ( PosterTemplate::BACKGROUNDS as $bg_value ) {
	foreach ( array( 'print_tabloid_300', 'landscape_16_9' ) as $preset ) {
		$dims = PlatformPresets::dimensions( $preset );
		$info = $render( $ad + array( 'background' => $bg_value, 'background_image' => $bg_fixture ), $preset );
		$assert( "background {$bg_value} / {$preset}: renders at preset size", null !== $info && $dims['width'] === $info[0] && $dims['height'] === $info[1] );
	}
}

// Hero mode honors the background too.
$GLOBALS['poster_smoke_qr'] = static function ( $value, $url, $size ) {
	$img = imagecreatetruecolor( max( 1, (int) $size ), max( 1, (int) $size ) );
	imagefill( $img, 0, 0, imagecolorallocate( $img, 0, 0, 0 ) );
	$path = tempnam( sys_get_temp_dir(), 'poster-qr' );
	imagepng( $img, $path );
	return $path;
};
foreach ( array( 'grid', 'gradient', 'image' ) as $bg_value ) {
	foreach ( array( 'print_tabloid_300', 'landscape_16_9' ) as $preset ) {
		$dims = PlatformPresets::dimensions( $preset );
		$info = $render( $ad + array( 'background' => $bg_value, 'background_image' => $bg_fixture, 'qr_emphasis' => 'hero' ), $preset );
		$assert( "background {$bg_value} / {$preset}: hero renders at preset size", null !== $info && $dims['width'] === $info[0] && $dims['height'] === $info[1] );
	}
}
$GLOBALS['poster_smoke_qr'] = null;

// Byte-level checks on the small portrait preset.
$hash = static function ( array $data ) use ( $template ): string {
	$paths = $template->render( $data, new GDRenderer(), array( 'preset' => 'instagram_feed_portrait' ) );
	$path  = $paths[0] ?? '';
	$sha   = ( '' !== $path && file_exists( $path ) ) ? (string) sha1_file( $path ) : '';
	if ( '' !== $path ) {
		wp_delete_file( $path );
	}
	return $sha;
};

$default_hash = $hash( $ad );
$assert( 'default output is byte-identical to explicit stripes', '' !== $default_hash && $hash( $ad + array( 'background' => 'stripes' ) ) === $default_hash );
$assert( 'background none renders a different (flat) canvas', '' !== $hash( $ad + array( 'background' => 'none' ) ) && $hash( $ad + array( 'background' => 'none' ) ) !== $default_hash );
$assert( 'background_tint recolors the pattern ink', '' !== $hash( $ad + array( 'background_tint' => '#ff8800' ) ) && $hash( $ad + array( 'background_tint' => '#ff8800' ) ) !== $default_hash );
$assert(
	'a bad background image path falls back to stripes without error',
	$hash( $ad + array( 'background' => 'image', 'background_image' => '/nope/missing.png' ) ) === $default_hash
);
$assert(
	'an unsupported background image type falls back to stripes',
	$hash( $ad + array( 'background' => 'image', 'background_image' => __FILE__ ) ) === $default_hash
);
$assert(
	'a readable background image renders a different canvas',
	'' !== $hash( $ad + array( 'background' => 'image', 'background_image' => $bg_fixture ) )
		&& $hash( $ad + array( 'background' => 'image', 'background_image' => $bg_fixture ) ) !== $default_hash
);
$assert(
	'a fully opaque overlay still renders',
	'' !== $hash( $ad + array( 'background' => 'image', 'background_image' => $bg_fixture, 'background_overlay' => 1 ) )
);
wp_delete_file( $bg_fixture );

// Background strength changes the rendered ink for stripes and finer patterns.
$assert( 'default output is byte-identical to explicit normal strength', $hash( $ad + array( 'background_strength' => 'normal' ) ) === $default_hash );
$bold_hash   = $hash( $ad + array( 'background_strength' => 'bold' ) );
$subtle_hash = $hash( $ad + array( 'background_strength' => 'subtle' ) );
$assert( 'bold strength renders different bytes than subtle', '' !== $bold_hash && '' !== $subtle_hash && $bold_hash !== $subtle_hash );
$assert(
	'strength also scales the finer patterns',
	$hash( $ad + array( 'background' => 'grid', 'background_strength' => 'bold' ) ) !== $hash( $ad + array( 'background' => 'grid', 'background_strength' => 'subtle' ) )
);

// -------------------------------------------------------------------------
// QR-hero layout (#3621).
// -------------------------------------------------------------------------

$hero_card = PosterTemplate::hero_card( 1485, 150 );
$assert( 'hero card quiet zone is at least 4 modules', $hero_card['pad'] >= (int) ceil( 1485 * 4 / 33 ) );
$assert( 'hero card wraps QR plus quiet zone', $hero_card['width'] === 1485 + 2 * $hero_card['pad'] );

// qr_quiet_modules drives the card padding (#3631).
$tight_card = PosterTemplate::hero_card( 1485, 150, 2 );
$assert( 'quiet=2 gives a smaller card than quiet=4', $tight_card['pad'] < $hero_card['pad'] && $tight_card['width'] < $hero_card['width'] && $tight_card['height'] < $hero_card['height'] );
$assert( 'hero_card clamps quiet modules to 1-4', PosterTemplate::hero_card( 1485, 150, 9 ) === $hero_card && PosterTemplate::hero_card( 1485, 150, 0 ) === PosterTemplate::hero_card( 1485, 150, 1 ) );
$tight_h = 1485 + 2 * $tight_card['pad'];
$assert( 'label-less card is exactly QR plus quiet zone (no bottom padding)', PosterTemplate::hero_card( 1485, 0, 2 )['height'] === $tight_h );

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

// -------------------------------------------------------------------------
// Hero portrait title autoscale (#3628).
// -------------------------------------------------------------------------

// The issue #3628 example: a short two-word title that wraps to two lines
// and previously filled under half the text width on the print presets.
foreach ( array( 'print_tabloid_300', 'print_pocket_flyer' ) as $preset ) {
	$dims   = PlatformPresets::dimensions( $preset );
	$u      = $dims['width'] / 1080;
	$margin = (int) round( 80 * $u );
	$text_w = $dims['width'] - 2 * $margin;
	$base   = (int) round( 84 * $u );

	$measure = new GDRenderer();
	$measure->create_canvas( $preset );
	$measure->register_font( 'heading', '' );

	$size   = PosterTemplate::hero_title_size( $measure, 'COSTUME CONTEST', $base, $text_w );
	$widest = 0;
	foreach ( $measure->wrap_text( 'COSTUME CONTEST', $size, 'heading', $text_w ) as $line ) {
		$widest = max( $widest, $measure->measure_text_width( $line, $size, 'heading' ) );
	}

	$assert( "{$preset}: hero title never shrinks below the base size", $size >= $base );
	$assert( "{$preset}: hero title growth is capped", $size <= (int) round( $base * 2.2 ) );
	$assert(
		"{$preset}: hero title fills at least 80% of the text width",
		$widest >= (int) round( 0.8 * $text_w ) && $widest <= $text_w
	);
}

// A title that already fills its column keeps its current size.
$measure = new GDRenderer();
$measure->create_canvas( 'print_pocket_flyer' );
$measure->register_font( 'heading', '' );
$assert( 'hero title at a tiny width stays at the base size', 10 === PosterTemplate::hero_title_size( $measure, 'COSTUME CONTEST', 10, 1 ) );

// End to end: the grown title still never overlaps the QR card — the fit
// loop shrinks it back before the hero QR gives up its guaranteed minimum
// across the print preset matrix.
$costume_data               = array( 'title' => 'COSTUME CONTEST' ) + $hero_data;
$GLOBALS['poster_smoke_qr'] = static function ( $value, $url, $size ) use ( &$qr_sizes ) {
	$qr_sizes[] = (int) $size;
	$img        = imagecreatetruecolor( max( 1, (int) $size ), max( 1, (int) $size ) );
	imagefill( $img, 0, 0, imagecolorallocate( $img, 0, 0, 0 ) );
	$path = tempnam( sys_get_temp_dir(), 'poster-qr' );
	imagepng( $img, $path );
	return $path;
};
foreach ( array( 'print_tabloid_300' => 3300, 'print_pocket_flyer' => 1275, 'print_tabloid' => 1650 ) as $preset => $w ) {
	$dims     = PlatformPresets::dimensions( $preset );
	$qr_sizes = array();
	$info     = $render( $costume_data, $preset );
	$final    = $qr_sizes ? (int) end( $qr_sizes ) : 0;
	$assert( "{$preset}: COSTUME CONTEST hero renders at preset size", null !== $info && $dims['width'] === $info[0] && $dims['height'] === $info[1] );
	$assert( "{$preset}: COSTUME CONTEST hero keeps the QR minimum (no overlap)", $final >= (int) round( $w * PosterTemplate::HERO_QR_MIN_RATIO ) );
}
$GLOBALS['poster_smoke_qr'] = null;

// -------------------------------------------------------------------------
// Hero fit order, quiet zone, outside label, numbered list (#3631).
// -------------------------------------------------------------------------

$measure = new GDRenderer();
$measure->register_font( 'heading', '' );
$measure->register_font( 'body', '' );

$steps      = array( 'Open your camera', 'Scan the code', 'Pick something nearby', 'Mark yourself going', 'Get reminded' );
$how_raw    = array(
	'title'             => 'HOW IT WORKS',
	'kicker'            => 'ACME PRESENTS',
	'qr_url'            => 'https://example.com/go/',
	'qr_label'          => 'SCAN TO START',
	'qr_emphasis'       => 'hero',
	'qr_quiet_modules'  => 2,
	'qr_label_position' => 'outside',
	'list_heading'      => 'FIVE STEPS',
	'list_style'        => 'numbered',
	'list_items'        => $steps,
);
$how_fields = PosterTemplate::normalize_fields( $how_raw );

// Fit order: the QR shrinks toward its minimum before any list row drops,
// so a 5-step numbered list survives on the print_tabloid_300 hero.
$fit = $template->plan_hero_fit( $measure, $how_fields, 3300, 5100, 0 );
$assert( 'print_tabloid_300 hero keeps all 5 numbered steps', 5 === count( $fit['fields']['list_items'] ) );
$assert( 'print_tabloid_300 hero QR stays at least its minimum ratio', $fit['qr_size'] >= (int) round( 3300 * PosterTemplate::HERO_QR_MIN_RATIO ) );
$assert( '5-step hero plan fits without dropping any field', $fit['plan']['fits'] && array() === $fit['dropped_fields'] );

// Outside label: drawn above the card, and the tight card is exactly the
// QR plus its quiet zone — no extra bottom padding.
$assert( 'outside label has a measured height', $fit['label_height'] > 0 );
$assert( 'outside label sits above the card bounds', $fit['label_top'] + $fit['label_height'] <= $fit['card_top'] );
$assert( 'tight hero card is exactly QR plus quiet zone', $fit['card']['height'] === $fit['qr_size'] + 2 * $fit['card']['pad'] );

// On-card label (default position) still reserves its height inside the card.
$card_fit = $template->plan_hero_fit( $measure, PosterTemplate::normalize_fields( array( 'qr_label_position' => 'card' ) + $how_raw ), 3300, 5100, 0 );
$assert( 'on-card label reserves height inside the card', 0 === $card_fit['label_height'] && $card_fit['card']['height'] > $card_fit['qr_size'] + 2 * $card_fit['card']['pad'] );

// End to end: the numbered 5-step hero renders at preset size with no
// dropped fields.
$GLOBALS['poster_smoke_qr'] = static function ( $value, $url, $size ) {
	$img = imagecreatetruecolor( max( 1, (int) $size ), max( 1, (int) $size ) );
	imagefill( $img, 0, 0, imagecolorallocate( $img, 0, 0, 0 ) );
	$path = tempnam( sys_get_temp_dir(), 'poster-qr' );
	imagepng( $img, $path );
	return $path;
};

$paths = $template->render( $how_raw, new GDRenderer(), array( 'preset' => 'print_tabloid_300' ) );
$info  = isset( $paths[0] ) && file_exists( $paths[0] ) ? getimagesize( $paths[0] ) : false;
if ( isset( $paths[0] ) ) {
	wp_delete_file( $paths[0] );
}
$assert( 'numbered 5-step hero renders at preset size', is_array( $info ) && 3300 === $info[0] && 5100 === $info[1] );
$assert( 'numbered 5-step hero drops no fields', array() === $template->get_dropped_fields() );
$GLOBALS['poster_smoke_qr'] = null;

// -------------------------------------------------------------------------
// Header co-brand logos (#3631).
// -------------------------------------------------------------------------

$wide_logo   = tempnam( sys_get_temp_dir(), 'poster-logo-a' ) . '.png';
$square_logo = tempnam( sys_get_temp_dir(), 'poster-logo-b' ) . '.png';
$logo_img    = imagecreatetruecolor( 200, 50 );
imagefill( $logo_img, 0, 0, imagecolorallocate( $logo_img, 200, 40, 40 ) );
imagepng( $logo_img, $wide_logo );
$logo_img = imagecreatetruecolor( 80, 80 );
imagefill( $logo_img, 0, 0, imagecolorallocate( $logo_img, 40, 40, 200 ) );
imagepng( $logo_img, $square_logo );
unset( $logo_img );

$cluster = PosterTemplate::header_logo_cluster( array( $wide_logo, $square_logo ), 150, 24, 5000 );
$assert(
	'header cluster renders both logos at equal height',
	2 === count( $cluster['logos'] ) && 150 === $cluster['logos'][0]['height'] && $cluster['logos'][0]['height'] === $cluster['logos'][1]['height']
);
$assert( 'header cluster preserves each aspect ratio', 600 === $cluster['logos'][0]['width'] && 150 === $cluster['logos'][1]['width'] );
$assert( 'header cluster width sums logos and gap', 600 + 24 + 150 === $cluster['width'] );

$narrow = PosterTemplate::header_logo_cluster( array( $wide_logo, $square_logo ), 150, 24, 300 );
$assert(
	'overwide cluster scales every logo down together at equal heights',
	$narrow['width'] <= 300 && $narrow['logos'][0]['height'] === $narrow['logos'][1]['height'] && $narrow['logos'][0]['height'] < 150
);
$assert( 'unreadable logo paths are skipped', 0 === PosterTemplate::header_logo_cluster( array( '/nope/missing.png' ), 150, 24, 300 )['width'] );

// The hero title's available width shrinks to clear the logo cluster.
$fit_logos = $template->plan_hero_fit( $measure, $how_fields, 3300, 5100, $cluster['width'] );
$assert( 'hero title width clears the logo cluster', $fit_logos['title_w'] <= $fit_logos['max_w'] - $cluster['width'] );

// End to end: header logos render on standard portrait and hero layouts.
$GLOBALS['poster_smoke_qr'] = static function ( $value, $url, $size ) {
	$img = imagecreatetruecolor( max( 1, (int) $size ), max( 1, (int) $size ) );
	imagefill( $img, 0, 0, imagecolorallocate( $img, 0, 0, 0 ) );
	$path = tempnam( sys_get_temp_dir(), 'poster-qr' );
	imagepng( $img, $path );
	return $path;
};
$assert( 'portrait renders with header logos', null !== $render( $ad + array( 'header_logos' => array( $wide_logo, $square_logo ) ), 'instagram_feed_portrait' ) );
$assert( 'hero renders with header logos', null !== $render( $hero_data + array( 'header_logos' => array( $wide_logo, $square_logo ) ), 'print_pocket_flyer' ) );
$assert( 'landscape renders with header logos', null !== $render( $ad + array( 'header_logos' => array( $wide_logo, $square_logo ) ), 'landscape_16_9' ) );
$GLOBALS['poster_smoke_qr'] = null;
wp_delete_file( $wide_logo );
wp_delete_file( $square_logo );

// Display font role: titles draw in fonts.display (falling back to heading);
// every other heading-font element keeps the heading font.
$poster_src = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Abilities/Media/Templates/PosterTemplate.php' );
$assert( 'display font registers with a heading fallback', str_contains( $poster_src, "register_font( 'display', (string) ( \$tokens['fonts']['display'] ?? \$tokens['fonts']['heading'] ?? '' ) )" ) );
$assert( 'every title draw and measure uses the display font', 0 === preg_match( "/\\\$fields\\['title'\\], \\\$title_size[^;]*'heading'/", $poster_src ) && 4 <= preg_match_all( "/\\\$fields\\['title'\\], \\\$title_size[^;]*'display'/", $poster_src ) );
$assert( 'list rows keep the heading font', str_contains( $poster_src, "\$colors['text'], 'heading' );" ) );
$assert( 'brand tokens declare a display font slot', array_key_exists( 'display', ( new ReflectionClassConstant( \DataMachine\Abilities\Media\BrandTokens::class, 'DEFAULTS' ) )->getValue()['fonts'] ?? array() ) );

$finish();
