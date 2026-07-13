<?php
/**
 * Flow Diagram Template
 *
 * A brand-agnostic, spec-driven flow diagram. Renders a set of labelled
 * nodes connected by directional arrows from a declarative JSON payload —
 * no domain logic, no external services, pure GDRenderer.
 *
 * Unlike domain templates (event cards, quote cards), this is a generic
 * structural primitive: describe a graph as data and get a deterministic
 * PNG/JPEG back. Any consumer of the `datamachine/render-image-template`
 * ability — CLI, REST, MCP, or PHP — can generate flow diagrams for free.
 *
 * Data spec:
 *   {
 *     "title":     "Optional heading",
 *     "direction": "horizontal" | "vertical",   // default: horizontal
 *     "nodes": [
 *       { "id": "a", "label": "First step",  "shape": "box",   "color": "#2d6cdf" },
 *       { "id": "b", "label": "Decision?",   "shape": "diamond" },
 *       { "id": "c", "label": "Done",        "shape": "oval" }
 *     ],
 *     "edges": [
 *       { "from": "a", "to": "b", "label": "next" },
 *       { "from": "b", "to": "c" }
 *     ]
 *   }
 *
 * `label` supports "\n" for manual line breaks; long labels also wrap to the
 * node width automatically. `shape` is one of box (default), diamond, oval.
 * Per-node `color` (hex) overrides the default node fill.
 *
 * @package DataMachine\Abilities\Media\Templates
 * @since 0.163.0
 */

namespace DataMachine\Abilities\Media\Templates;

use DataMachine\Abilities\Media\GDRenderer;
use DataMachine\Abilities\Media\PlatformPresets;
use DataMachine\Abilities\Media\TemplateInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FlowDiagramTemplate implements TemplateInterface {

	private const DEFAULT_BG      = '#0f1117';
	private const DEFAULT_SURFACE = '#1b1f2a';
	private const DEFAULT_NODE    = '#2d6cdf';
	private const DEFAULT_EDGE    = '#8a93a6';
	private const DEFAULT_TEXT    = '#ffffff';
	private const DEFAULT_TITLE   = '#ffffff';
	private const DEFAULT_MUTED   = '#8a93a6';

	private const NODE_WIDTH  = 260;
	private const NODE_HEIGHT = 120;
	private const GAP         = 90;
	private const MARGIN      = 60;
	private const TITLE_BAND  = 96;

	public function get_id(): string {
		return 'flow_diagram';
	}

	public function get_name(): string {
		return 'Flow Diagram';
	}

	public function get_description(): string {
		return 'Brand-agnostic flow diagram. Renders labelled nodes (box, diamond, oval) connected by directional arrows from a declarative nodes/edges spec. Composable via CLI, REST, MCP, or PHP.';
	}

	public function get_fields(): array {
		return array(
			'title'     => array(
				'label'    => 'Title',
				'type'     => 'string',
				'required' => false,
			),
			'direction' => array(
				'label'    => 'Layout Direction',
				'type'     => 'string',
				'required' => false,
			),
			'nodes'     => array(
				'label'    => 'Nodes',
				'type'     => 'array',
				'required' => true,
			),
			'edges'     => array(
				'label'    => 'Edges',
				'type'     => 'array',
				'required' => false,
			),
		);
	}

	/**
	 * Default canvas: twitter_card is 1200x675, a true 16:9 card that works well
	 * as a blog featured image / social share. The diagram is centered within it.
	 */
	private const DEFAULT_PRESET = 'twitter_card';

	public function get_default_preset(): string {
		return self::DEFAULT_PRESET;
	}

	/**
	 * @param array      $data     Diagram spec (title, direction, nodes, edges).
	 * @param GDRenderer $renderer Shared GD renderer.
	 * @param array      $options  preset, format, context, colors overrides.
	 * @return string[] Rendered file paths.
	 */
	public function render( array $data, GDRenderer $renderer, array $options = array() ): array {
		$nodes = isset( $data['nodes'] ) && is_array( $data['nodes'] ) ? array_values( $data['nodes'] ) : array();
		if ( empty( $nodes ) ) {
			do_action( 'datamachine_log', 'error', 'FlowDiagramTemplate: no nodes provided' );
			return array();
		}

		$edges     = isset( $data['edges'] ) && is_array( $data['edges'] ) ? $data['edges'] : array();
		$title     = isset( $data['title'] ) ? (string) $data['title'] : '';
		$direction = ( ( $data['direction'] ?? 'horizontal' ) === 'vertical' ) ? 'vertical' : 'horizontal';
		$colors    = isset( $options['colors'] ) && is_array( $options['colors'] ) ? $options['colors'] : array();
		$format    = $options['format'] ?? 'png';

		$count      = count( $nodes );
		$title_band = '' !== $title ? self::TITLE_BAND : 0;

		// Widen the inter-node gap when any edge carries a label so the label
		// chip has room to sit clear of both node fills.
		$has_edge_labels = false;
		foreach ( $edges as $edge ) {
			if ( ! empty( $edge['label'] ) ) {
				$has_edge_labels = true;
				break;
			}
		}

		// Resolve the target canvas first (default is a 16:9 card). The diagram
		// grid is then scaled to fit inside it and centered, so a bare render
		// always produces a clean, correctly-proportioned image.
		$canvas = $this->resolve_canvas( $options );
		$width  = $canvas['width'];
		$height = $canvas['height'];

		// Base (unscaled) grid metrics.
		$node_w = self::NODE_WIDTH;
		$node_h = self::NODE_HEIGHT;
		$gap    = $has_edge_labels ? self::GAP + 90 : self::GAP;
		$margin = self::MARGIN;

		$content_w = $this->grid_width( $direction, $count, $node_w, $gap, $margin );
		$content_h = $this->grid_height( $direction, $count, $node_h, $gap, $margin, $title_band );

		// Scale the whole grid down uniformly if it overflows the canvas in
		// either axis. Never scale up past the base size (keeps small diagrams
		// from ballooning on a large canvas).
		$scale = min( 1.0, $width / max( 1, $content_w ), $height / max( 1, $content_h ) );
		if ( $scale < 1.0 ) {
			// floor() (not round()) guarantees the scaled grid never exceeds the
			// target canvas by a rounding pixel, so preset dimensions stay exact.
			$node_w     = (int) floor( $node_w * $scale );
			$node_h     = (int) floor( $node_h * $scale );
			$gap        = (int) floor( $gap * $scale );
			$margin     = (int) floor( $margin * $scale );
			$title_band = (int) floor( $title_band * $scale );
			$content_w  = $this->grid_width( $direction, $count, $node_w, $gap, $margin );
			$content_h  = $this->grid_height( $direction, $count, $node_h, $gap, $margin, $title_band );
		}

		// If content is still larger than the canvas (single very wide node),
		// grow the canvas so nothing clips.
		$width  = max( $width, $content_w );
		$height = max( $height, $content_h );
		$off_x  = intdiv( $width - $content_w, 2 );
		$off_y  = intdiv( $height - $content_h, 2 );

		$renderer->create_canvas( $width, $height );

		// Fonts: theme fonts if present, DejaVu fallback otherwise (handled by register_font).
		$renderer->register_font( 'title', 'Inter-Bold.ttf' );
		$renderer->register_font( 'label', 'Inter-Regular.ttf' );

		$bg      = $renderer->color_hex( 'bg', $colors['background'] ?? self::DEFAULT_BG );
		$surface = $renderer->color_hex( 'surface', $colors['surface'] ?? self::DEFAULT_SURFACE );
		$node_c  = $renderer->color_hex( 'node', $colors['node'] ?? self::DEFAULT_NODE );
		$edge_c  = $renderer->color_hex( 'edge', $colors['edge'] ?? self::DEFAULT_EDGE );
		$text_c  = $renderer->color_hex( 'text', $colors['text'] ?? self::DEFAULT_TEXT );
		$title_c = $renderer->color_hex( 'title', $colors['title'] ?? self::DEFAULT_TITLE );
		$muted_c = $renderer->color_hex( 'muted', $colors['muted'] ?? self::DEFAULT_MUTED );

		$renderer->fill( $bg );

		if ( '' !== $title ) {
			$title_fs = max( 16, (int) round( 30 * ( $title_band / self::TITLE_BAND ) ) );
			$renderer->filled_rect( 0, $off_y, $width, $off_y + $title_band, $surface );
			$renderer->draw_text_centered( $title, $title_fs, $off_y + $margin + 6, $title_c, 'title' );
		}

		// Position nodes on a single row/column and index by id for edge routing.
		// Offsets center the content grid within the (possibly larger) canvas.
		$positions = array();
		$top0      = $off_y + $margin + $title_band;

		foreach ( $nodes as $i => $node ) {
			if ( 'vertical' === $direction ) {
				$x = $off_x + $margin;
				$y = $top0 + $i * ( $node_h + $gap );
			} else {
				$x = $off_x + $margin + $i * ( $node_w + $gap );
				$y = $top0;
			}

			$id = isset( $node['id'] ) ? (string) $node['id'] : (string) $i;

			$positions[ $id ] = array(
				'x'      => $x,
				'y'      => $y,
				'cx'     => $x + intdiv( $node_w, 2 ),
				'cy'     => $y + intdiv( $node_h, 2 ),
				'right'  => $x + $node_w,
				'bottom' => $y + $node_h,
			);
		}

		// Draw edges first so arrows sit behind node fills.
		foreach ( $edges as $edge ) {
			$from = isset( $edge['from'] ) ? (string) $edge['from'] : '';
			$to   = isset( $edge['to'] ) ? (string) $edge['to'] : '';

			if ( ! isset( $positions[ $from ], $positions[ $to ] ) ) {
				continue;
			}

			$a = $positions[ $from ];
			$b = $positions[ $to ];

			if ( 'vertical' === $direction ) {
				$x1 = $a['cx'];
				$y1 = $a['bottom'];
				$x2 = $b['cx'];
				$y2 = $b['y'];
			} else {
				$x1 = $a['right'];
				$y1 = $a['cy'];
				$x2 = $b['x'];
				$y2 = $b['cy'];
			}

			$renderer->draw_arrow( $x1, $y1, $x2, $y2, $edge_c, 3 );

			$label = isset( $edge['label'] ) ? (string) $edge['label'] : '';
			if ( '' !== $label ) {
				$mid_x  = intdiv( $x1 + $x2, 2 );
				$mid_y  = intdiv( $y1 + $y2, 2 );
				$fs     = 14;
				$lw     = $renderer->measure_text_width( $label, $fs, 'label' );
				$pad    = 8;
				$chip_h = $fs + $pad;
				// Chip sits above the connector so it never overlaps node fills.
				$chip_x1 = $mid_x - intdiv( $lw, 2 ) - $pad;
				$chip_y1 = $mid_y - $chip_h - 6;
				$renderer->draw_rounded_rect( $chip_x1, $chip_y1, $lw + $pad * 2, $chip_h, $surface, 6 );
				$renderer->draw_text( $label, $fs, $mid_x - intdiv( $lw, 2 ), $chip_y1 + $fs + intdiv( $pad, 2 ) - 2, $muted_c, 'label' );
			}
		}

		// Draw nodes.
		foreach ( $nodes as $i => $node ) {
			$id  = isset( $node['id'] ) ? (string) $node['id'] : (string) $i;
			$pos = $positions[ $id ];

			$shape = $node['shape'] ?? 'box';
			$fill  = isset( $node['color'] ) ? $renderer->color_hex( 'node_' . $id, (string) $node['color'] ) : $node_c;
			$label = isset( $node['label'] ) ? (string) $node['label'] : '';
			$label = str_replace( '\n', "\n", $label );

			$radius = max( 6, (int) round( 16 * ( $node_h / self::NODE_HEIGHT ) ) );

			switch ( $shape ) {
				case 'diamond':
					$renderer->draw_diamond( $pos['cx'], $pos['cy'], $node_w, $node_h, $fill, true );
					break;
				case 'oval':
					$renderer->draw_oval( $pos['cx'], $pos['cy'], $node_w, $node_h, $fill, true );
					break;
				default:
					$renderer->draw_rounded_rect( $pos['x'], $pos['y'], $node_w, $node_h, $fill, $radius );
					break;
			}

			$this->draw_node_label( $renderer, $label, $pos, $text_c, $node_w );
		}

		$path = $renderer->save_temp( $format );

		return $path ? array( $path ) : array();
	}

	/**
	 * Resolve the target output canvas size.
	 *
	 * Precedence:
	 *   1. Explicit options[width] + options[height]
	 *   2. options[preset] resolved via PlatformPresets
	 *   3. The template default preset (16:9 twitter_card, 1200x675)
	 *
	 * @param array $options Render options (preset, width, height).
	 * @return array{width: int, height: int}
	 */
	private function resolve_canvas( array $options ): array {
		if ( ! empty( $options['width'] ) && ! empty( $options['height'] ) ) {
			return array(
				'width'  => (int) $options['width'],
				'height' => (int) $options['height'],
			);
		}

		$preset = ! empty( $options['preset'] ) ? (string) $options['preset'] : self::DEFAULT_PRESET;
		$dims   = PlatformPresets::dimensions( $preset );
		if ( ! $dims ) {
			$dims = PlatformPresets::dimensions( self::DEFAULT_PRESET );
		}

		return array(
			'width'  => (int) ( $dims['width'] ?? 1200 ),
			'height' => (int) ( $dims['height'] ?? 675 ),
		);
	}

	/**
	 * Total grid width for the given metrics.
	 */
	private function grid_width( string $direction, int $count, int $node_w, int $gap, int $margin ): int {
		if ( 'vertical' === $direction ) {
			return $margin * 2 + $node_w;
		}
		return $margin * 2 + $count * $node_w + ( $count - 1 ) * $gap;
	}

	/**
	 * Total grid height for the given metrics.
	 */
	private function grid_height( string $direction, int $count, int $node_h, int $gap, int $margin, int $title_band ): int {
		if ( 'vertical' === $direction ) {
			return $margin * 2 + $title_band + $count * $node_h + ( $count - 1 ) * $gap;
		}
		return $margin * 2 + $title_band + $node_h;
	}

	/**
	 * Draw a node's (possibly multi-line, possibly wrapped) label centered in the node box.
	 *
	 * @param GDRenderer          $renderer Renderer.
	 * @param string              $label    Label text (may contain "\n").
	 * @param array<string, int>  $pos      Node position record.
	 * @param int                 $color    Text color id.
	 * @param int                 $node_w   Node width (scaled).
	 */
	private function draw_node_label( GDRenderer $renderer, string $label, array $pos, int $color, int $node_w ): void {
		if ( '' === $label ) {
			return;
		}

		$inner = $node_w - 40;

		// Base font scales with the node, then auto-shrinks until the longest
		// single token fits the inner width. Handles long unbroken strings
		// (e.g. "ai-provider-for-claude-code") that word-wrapping can't break.
		$font_size = max( 11, (int) round( 20 * ( $node_w / self::NODE_WIDTH ) ) );
		$longest   = '';
		foreach ( preg_split( '/\s+/', str_replace( "\n", ' ', $label ) ) as $token ) {
			if ( strlen( $token ) > strlen( $longest ) ) {
				$longest = $token;
			}
		}
		while ( $font_size > 11 && $renderer->measure_text_width( $longest, $font_size, 'label' ) > $inner ) {
			--$font_size;
		}

		// Expand manual line breaks, then wrap each to the inner width.
		$lines = array();
		foreach ( explode( "\n", $label ) as $segment ) {
			foreach ( $renderer->wrap_text( $segment, $font_size, 'label', $inner ) as $wrapped ) {
				$lines[] = $wrapped;
			}
		}

		$line_h  = (int) ( $font_size * 1.35 );
		$block_h = count( $lines ) * $line_h;
		$start_y = $pos['cy'] - intdiv( $block_h, 2 );

		foreach ( $lines as $n => $line ) {
			$lw = $renderer->measure_text_width( $line, $font_size, 'label' );
			$lx = $pos['cx'] - intdiv( $lw, 2 );
			$ly = $start_y + $n * $line_h + $font_size;
			$renderer->draw_text( $line, $font_size, $lx, $ly, $color, 'label' );
		}
	}
}
