<?php
/**
 * Composable top-to-bottom image layout. All pixel values use a 1080px base
 * width and scale with the selected preset. Colors accept BrandTokens keys or
 * hex; fonts accept heading, body, display or brand token keys.
 *
 * Fields: background (default background_dark), padding (default 64), align
 * (center|left), blocks (ordered array). Every block accepts gap_before (px).
 * Block schema:
 * - text: text, font, color, size (32), fit (false), max_size, wrap (true),
 *   line_height (1.2), align. Fit shrinks words for wrapped text, or the entire
 *   line when wrap=false; max_size optionally supplies a larger starting size.
 * - image: path or attachment_id, width (fraction <=1 or base px), max_height.
 * - qr: url, size (content-width fraction, default .5), card_color (white),
 *   quiet_modules (1..4, default 4). Provider receives final QR pixel size.
 * - row: items (text/image blocks), gap (16px); centered vertically, one line.
 * - list: items (strings or objects with text and optional color, font,
 *   marker_color overrides), style (numbered|bullets|plain), marker_color,
 *   font (heading|body|display|brand), color, size (32), line_height (1.2).
 * - divider: width (fraction or px), thickness (2), color.
 * - spacer: height (px), flex (false). Flex spacers receive spare height;
 *   otherwise spare height is distributed between blocks.
 *
 * Overflow shrinks fit text and lists proportionally to a minimum of 1px.
 * Blocks are never removed to fit. Warnings describe unresolved overflow,
 * invalid blocks and unavailable assets; skipped field paths are also exposed
 * through get_dropped_fields(), the existing ability reporting mechanism.
 *
 * @package DataMachine\Abilities\Media
 */
namespace DataMachine\Abilities\Media\Templates;

use DataMachine\Abilities\Media\BrandTokens;
use DataMachine\Abilities\Media\GDRenderer;
use DataMachine\Abilities\Media\TemplateInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StackTemplate implements TemplateInterface {
	private array $warnings = array();
	private array $dropped  = array();
	private array $tokens   = array();

	public function get_id(): string {
		return 'stack';
	}
	public function get_name(): string {
		return 'Stack';
	}
	public function get_description(): string {
		return 'An ordered vertical stack of text, images, QR codes, rows, lists, dividers and spacers.';
	}
	public function get_default_preset(): string {
		return 'instagram_feed_portrait';
	}
	public function get_warnings(): array {
		return $this->warnings;
	}
	public function get_dropped_fields(): array {
		return array_values( $this->dropped );
	}

	public function get_fields(): array {
		return array(
			'background' => array(
				'label'    => 'Background hex or brand color token (default background_dark)',
				'type'     => 'string',
				'required' => false,
			),
			'padding'    => array(
				'label'    => 'Padding in pixels at 1080px base width (default 64)',
				'type'     => 'number',
				'required' => false,
			),
			'align'      => array(
				'label'    => 'Default alignment: center or left',
				'type'     => 'string',
				'required' => false,
			),
			'blocks'     => array(
				'label'    => 'Ordered blocks; each accepts gap_before (base px). text: text/font/color/size/fit/max_size/wrap/line_height/align; image: path or attachment_id, width (fraction or px), max_height; qr: url/size (fraction)/card_color/quiet_modules; row: items (text/image), gap; list: items (strings or objects with text and optional color/font/marker_color overrides)/style (numbered|bullets|plain)/marker_color/font (heading|body|display|brand)/color/size; divider: width/thickness/color; spacer: height/flex. Pixels scale from 1080px. Fonts and colors accept brand tokens; colors also accept hex.',
				'type'     => 'array',
				'required' => true,
			),
		);
	}

	private function warn( string $key, string $message, bool $skip = true ): void {
		$this->warnings[ $key ] = $message;
		if ( $skip ) {
			$this->dropped[ $key ] = $key;
		}
	}

	private function color( GDRenderer $r, string $value ): int {
		$hex = $this->tokens['colors'][ $value ] ?? $value;
		if ( ! preg_match( '/^#?[a-f0-9]{3}(?:[a-f0-9]{3})?$/i', $hex ) ) {
			$hex = '#ffffff';
		}
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return $r->color_hex( $hex, $hex );
	}

	private function extent( float $value, int $width, float $scale ): int {
		return max( 1, min( $width, (int) round( $value <= 1 ? $value * $width : $value * $scale ) ) );
	}

	/** Measure without drawing or requesting QR files. Returned boxes are also used to draw. */
	private function measure( GDRenderer $r, array $b, int $width, float $scale, float $shrink, string $key, string $align ): array {
		$type = $b['type'] ?? '';
		$p    = array(
			'type'   => $type,
			'width'  => $width,
			'height' => 0,
			'gap'    => max( 0, (int) round( (float) ( $b['gap_before'] ?? 0 ) * $scale ) ),
			'align'  => $b['align'] ?? $align,
			'key'    => $key,
		);
		if ( 'text' === $type || 'list' === $type ) {
			$p['font']        = in_array( $b['font'] ?? 'body', array( 'heading', 'body', 'display', 'brand' ), true ) ? ( $b['font'] ?? 'body' ) : 'body';
			$p['size']        = max( 1, (int) round( (float) ( ! empty( $b['fit'] ) ? ( $b['max_size'] ?? $b['size'] ?? 32 ) : ( $b['size'] ?? 32 ) ) * $scale * ( 'list' === $type || ! empty( $b['fit'] ) ? $shrink : 1 ) ) );
			$p['line_height'] = max( 1, (float) ( $b['line_height'] ?? 1.2 ) );
			$p['color']       = $b['color'] ?? 'text_inverse';
			$p['wrap']        = $b['wrap'] ?? true;
			$p['text']        = (string) ( $b['text'] ?? '' );
			if ( 'text' === $type ) {
				if ( ! empty( $b['fit'] ) ) {
					$p['size'] = TemplateHelpers::fit_text( $r, $p['text'], $p['size'], $p['font'], $width, (bool) $p['wrap'] );
				}
				$p['height'] = $p['wrap'] ? $r->measure_text_height( $p['text'], $p['size'], $p['font'], $width, $p['line_height'] ) : (int) ceil( $p['size'] * $p['line_height'] );
			} else {
				$p['style']        = $b['style'] ?? 'numbered';
				$p['marker_color'] = $b['marker_color'] ?? 'accent';
				$p['items']        = array();
				foreach ( (array) ( $b['items'] ?? array() ) as $i => $item ) {
					if ( is_scalar( $item ) ) {
						$item = array( 'text' => (string) $item );
					} elseif ( ! is_array( $item ) || ! isset( $item['text'] ) || ! is_scalar( $item['text'] ) ) {
						$this->warn( "$key.items.$i", "$key.items.$i: list item requires scalar text; skipped." );
						continue;
					}
					$p['items'][] = array(
						'text'         => (string) $item['text'],
						'font'         => in_array( $item['font'] ?? '', array( 'heading', 'body', 'display', 'brand' ), true ) ? $item['font'] : $p['font'],
						'color'        => $item['color'] ?? $p['color'],
						'marker_color' => $item['marker_color'] ?? $p['marker_color'],
					);
				}
				// Reserve one shared gutter, wide enough for every item's font.
				$p['gutter'] = 0;
				if ( 'plain' !== $p['style'] ) {
					$p['gutter'] = $r->measure_text_width( (string) count( $p['items'] ) . '.', $p['size'], $p['font'] ) + (int) ceil( $p['size'] * .6 );
					foreach ( $p['items'] as $item ) {
						$p['gutter'] = max( $p['gutter'], $r->measure_text_width( (string) count( $p['items'] ) . '.', $p['size'], $item['font'] ) + (int) ceil( $p['size'] * .6 ) );
					}
				}
				foreach ( $p['items'] as $item ) {
					$p['height'] += $r->measure_text_height( $item['text'], $p['size'], $item['font'], max( 1, $width - $p['gutter'] ), $p['line_height'] );
				}
			}
		} elseif ( 'image' === $type ) {
			$path = $b['path'] ?? $b['attachment_id'] ?? '';
			if ( is_numeric( $path ) ) {
				$path = get_attached_file( (int) $path );
			}
			$info = is_string( $path ) && is_readable( $path ) ? getimagesize( $path ) : false;
			if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP ), true ) ) {
				$p['width'] = 0;
				$this->warn( $key, "$key: unreadable or unsupported image skipped." );
			} else {
				$p['path']   = $path;
				$p['width']  = $this->extent( (float) ( $b['width'] ?? 1 ), $width, $scale );
				$p['height'] = max( 1, (int) round( $p['width'] * $info[1] / $info[0] ) );
				$ratio       = min( 1, max( 1, (float) ( $b['max_height'] ?? $p['height'] / $scale ) * $scale ) / $p['height'] );
				$p['width']  = max( 1, (int) round( $p['width'] * $ratio ) );
				$p['height'] = max( 1, (int) round( $p['height'] * $ratio ) );
			}
		} elseif ( 'qr' === $type ) {
			$p['size'] = $this->extent( min( 1, max( .01, (float) ( $b['size'] ?? .5 ) ) ), $width, $scale );
			$p['pad']  = (int) ceil( $p['size'] * max( 1, min( 4, (int) ( $b['quiet_modules'] ?? 4 ) ) ) / 33 );
			// Keep the quiet-zone card inside the content area even at size=1.
			while ( $p['size'] > 1 && $p['size'] + 2 * $p['pad'] > $width ) {
				--$p['size'];
				$p['pad'] = (int) ceil( $p['size'] * max( 1, min( 4, (int) ( $b['quiet_modules'] ?? 4 ) ) ) / 33 );
			}
			$p['width']  = $p['size'] + 2 * $p['pad'];
			$p['height'] = $p['width'];
			$p['url']    = (string) ( $b['url'] ?? '' );
			$p['color']  = $b['card_color'] ?? '#ffffff';
		} elseif ( 'row' === $type ) {
			$p['children'] = array();
			$p['row_gap']  = max( 0, (int) round( (float) ( $b['gap'] ?? 16 ) * $scale ) );
			$used          = 0;
			foreach ( (array) ( $b['items'] ?? array() ) as $i => $item ) {
				if ( ! is_array( $item ) || ! in_array( $item['type'] ?? '', array( 'text', 'image' ), true ) ) {
					$this->warn( "$key.items.$i", "$key.items.$i: row only supports text and image." );
					continue;
				}
				$item['wrap'] = false;
				$child        = $this->measure( $r, $item, $width, $scale, $shrink, "$key.items.$i", 'left' );
				if ( 'text' === $child['type'] ) {
					$child['width'] = $r->measure_text_width( $child['text'], $child['size'], $child['font'] );
				}
				$child['x']      = $used;
				$used           += $child['width'] + $p['row_gap'];
				$p['height']     = max( $p['height'], $child['height'] );
				$p['children'][] = $child;
			}
			$p['width'] = max( 0, $used - $p['row_gap'] );
			if ( $p['width'] > $width ) {
				$this->warn( $key, "$key: row exceeds content width.", false );
			}
		} elseif ( 'divider' === $type ) {
			$p['width']  = $this->extent( (float) ( $b['width'] ?? 1 ), $width, $scale );
			$p['height'] = max( 1, (int) round( (float) ( $b['thickness'] ?? 2 ) * $scale ) );
			$p['color']  = $b['color'] ?? 'accent';
		} elseif ( 'spacer' === $type ) {
			$p['height'] = max( 0, (int) round( (float) ( $b['height'] ?? 0 ) * $scale ) );
			$p['flex']   = ! empty( $b['flex'] );
		} else {
			$this->warn( $key, "$key: unknown block type skipped." );
		}
		return $p;
	}

	/** Public measured layout for inspection; no assets are generated here. */
	public function plan_layout( GDRenderer $r, array $data ): array {
		$this->warnings = array();
		$this->dropped  = array();
		$this->tokens   = BrandTokens::get( 'stack', $data );
		foreach ( array( 'heading', 'body', 'display', 'brand' ) as $font ) {
			$r->register_font( $font, (string) ( $this->tokens['fonts'][ $font ] ?? $this->tokens['fonts']['body'] ?? '' ) );
		}
		$scale     = $r->get_width() / 1080;
		$padding   = max( 0, min( (int) round( (float) ( $data['padding'] ?? 64 ) * $scale ), (int) floor( min( $r->get_width(), $r->get_height() ) / 2 ) - 1 ) );
		$width     = max( 1, $r->get_width() - 2 * $padding );
		$available = $r->get_height() - 2 * $padding;
		$shrink    = 1;
		for ( $pass = 0; $pass < 80; ++$pass ) {
			// Rebuild diagnostics too: a row may stop overflowing after fitting.
			$this->warnings = array();
			$this->dropped  = array();
			$boxes          = array();
			$total          = 0;
			foreach ( (array) ( $data['blocks'] ?? array() ) as $i => $block ) {
				if ( ! is_array( $block ) ) {
					$this->warn( "blocks.$i", "blocks.$i: invalid block skipped." );
					continue;
				}
				$box     = $this->measure( $r, $block, $width, $scale, $shrink, "blocks.$i", $data['align'] ?? 'center' );
				$total  += $box['gap'] + $box['height'];
				$boxes[] = $box;
			}
			if ( $total <= $available || $shrink <= .001 ) {
				break;
			}
			$shrink *= max( .1, min( .9, $available / max( 1, $total ) ) );
		}
		if ( $total > $available ) {
			$this->warn( 'overflow', 'Stack content exceeds canvas height after fitting; no blocks were dropped.', false );
		}
		$flex    = count( array_filter( $boxes, static fn( $b ) => ! empty( $b['flex'] ) ) );
		$spare   = max( 0, $available - $total );
		$between = ! $flex && count( $boxes ) > 1 ? $spare / ( count( $boxes ) - 1 ) : 0;
		$y       = $padding;
		foreach ( $boxes as &$box ) {
			$y       += $box['gap'];
			$box['x'] = $padding + ( 'left' === $box['align'] ? 0 : (int) round( ( $width - $box['width'] ) / 2 ) );
			$box['y'] = (int) round( $y );
			if ( ! empty( $box['flex'] ) ) {
				$box['height'] += (int) floor( $spare / $flex );
			}
			$y += $box['height'] + $between;
		}
		unset( $box );
		return $boxes;
	}

	private function draw( GDRenderer $r, array $b, int $x, int $y ): void {
		if ( 'text' === $b['type'] ) {
			$color = $this->color( $r, $b['color'] );
			if ( $b['wrap'] ) {
				$r->draw_text_wrapped( $b['text'], $b['size'], $x, $y, $color, $b['font'], $b['width'], $b['line_height'], $b['align'] );
			} else {
				$offset = 'left' === $b['align'] ? 0 : (int) round( ( $b['width'] - $r->measure_text_width( $b['text'], $b['size'], $b['font'] ) ) / 2 );
				$r->draw_text( $b['text'], $b['size'], $x + $offset, $y + $b['size'], $color, $b['font'] );
			}
		} elseif ( 'image' === $b['type'] && isset( $b['path'] ) ) {
			$r->overlay_image( $b['path'], $x, $y, $b['width'], $b['height'] );
		} elseif ( 'qr' === $b['type'] ) {
			$path = TemplateHelpers::qr_image_path( $b['url'], $b['size'] );
			if ( ! $path ) {
				$this->warn( $b['key'], $b['key'] . ': QR provider unavailable.' );
				return;
			}
			try {
				$r->filled_rect( $x, $y, $x + $b['width'] - 1, $y + $b['height'] - 1, $this->color( $r, $b['color'] ) );
				$r->overlay_image( $path, $x + $b['pad'], $y + $b['pad'], $b['size'], $b['size'], 100, false );
			} finally {
				wp_delete_file( $path );
			}
		} elseif ( 'row' === $b['type'] ) {
			foreach ( $b['children'] as $child ) {
				$this->draw( $r, $child, $x + $child['x'], $y + (int) round( ( $b['height'] - $child['height'] ) / 2 ) );
			}
		} elseif ( 'list' === $b['type'] ) {
			foreach ( $b['items'] as $i => $item ) {
				$marker = 'numbered' === $b['style'] ? ( $i + 1 ) . '.' : ( 'bullets' === $b['style'] ? '•' : '' );
				if ( '' !== $marker ) {
					$r->draw_text( $marker, $b['size'], $x, $y + $b['size'], $this->color( $r, $item['marker_color'] ), $item['font'] );
				}
				$y = $r->draw_text_wrapped( $item['text'], $b['size'], $x + $b['gutter'], $y, $this->color( $r, $item['color'] ), $item['font'], max( 1, (int) ( $b['width'] - $b['gutter'] ) ), $b['line_height'] );
			}
		} elseif ( 'divider' === $b['type'] ) {
			$r->filled_rect( $x, $y, $x + $b['width'] - 1, $y + $b['height'] - 1, $this->color( $r, $b['color'] ) );
		}
	}

	public function render( array $data, GDRenderer $renderer, array $options = array() ): array {
		$renderer->create_canvas( (string) ( $options['preset'] ?? $this->get_default_preset() ) );
		if ( ! $renderer->get_image() ) {
			return array();
		}
		$boxes = $this->plan_layout( $renderer, $data );
		$renderer->fill( $this->color( $renderer, $data['background'] ?? 'background_dark' ) );
		foreach ( $boxes as $box ) {
			$this->draw( $renderer, $box, $box['x'], $box['y'] );
		}
		$format   = (string) ( $options['format'] ?? 'png' );
		$context  = (array) ( $options['context'] ?? array() );
		$filename = (string) ( $options['filename'] ?? 'stack.' . ( 'jpeg' === $format ? 'jpg' : 'png' ) );
		$path     = ! empty( $context['pipeline_id'] ) && ! empty( $context['flow_id'] )
			? $renderer->save_to_repository( $filename, $context, $format )
			: $renderer->save_temp( $format );
		return $path ? array( $path ) : array();
	}
}
