<?php
/**
 * Diagram Template
 *
 * Generates diagram images from structured data — flowcharts, process flows.
 * Supports different node types: rectangle (process), diamond (decision), oval (start/end).
 *
 * @package DataMachineSocials\ImageGeneration\Templates
 * @since 0.2.0
 */

namespace DataMachineSocials\ImageGeneration\Templates;

use DataMachine\Abilities\Media\GDRenderer;
use DataMachine\Abilities\Media\TemplateInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DiagramTemplate implements TemplateInterface {

	/**
	 * Layout constants.
	 */
	private const PADDING     = 40;
	private const NODE_WIDTH  = 160;
	private const NODE_HEIGHT = 60;
	private const NODE_GAP_X  = 60;
	private const NODE_GAP_Y  = 50;

	/**
	 * Default colors.
	 */
	private const COLORS = array(
		'background'  => array( 255, 255, 255 ),
		'node_fill'   => array( 250, 250, 250 ),
		'node_stroke' => array( 60, 60, 60 ),
		'text'        => array( 40, 40, 40 ),
		'start'       => array( 83, 148, 11 ),   // Green.
		'end'         => array( 255, 59, 48 ),  // Red.
		'decision'    => array( 255, 204, 5 ),   // Yellow.
		'connector'   => array( 100, 100, 100 ),
	);

	public function get_id(): string {
		return 'diagram';
	}

	public function get_name(): string {
		return 'Diagram';
	}

	public function get_description(): string {
		return 'Generate flowcharts and process diagrams from structured data. Supports process, decision, start, and end nodes.';
	}

	public function get_fields(): array {
		return array(
			'title'       => array(
				'label'    => 'Diagram Title',
				'type'     => 'string',
				'required' => false,
			),
			'nodes'       => array(
				'label'    => 'Nodes',
				'type'     => 'array',
				'required' => true,
			),
			'connections' => array(
				'label'    => 'Connections',
				'type'     => 'array',
				'required' => false,
			),
		);
	}

	public function get_default_preset(): string {
		return 'instagram_feed_portrait';
	}

	/**
	 * Render a diagram.
	 *
	 * @param array      $data     Diagram data.
	 * @param GDRenderer $renderer GD renderer instance.
	 * @param array      $options  Render options.
	 * @return string[] Generated image file paths.
	 */
	public function render( array $data, GDRenderer $renderer, array $options = array() ): array {
		$title       = $data['title'] ?? '';
		$nodes       = $data['nodes'] ?? array();
		$connections = $data['connections'] ?? array();
		$preset      = $options['preset'] ?? $this->get_default_preset();
		$format      = $options['format'] ?? 'png';
		$context     = $options['context'] ?? array();

		if ( empty( $nodes ) ) {
			return array();
		}

		$renderer->create_canvas( $preset );

		if ( ! $renderer->get_image() ) {
			return array();
		}

		$renderer->register_font( 'body', 'helvetica.ttf' );

		// Allocate colors.
		$bg_color        = $renderer->color_rgb( 'background', self::COLORS['background'] );
		$node_fill       = $renderer->color_rgb( 'node_fill', self::COLORS['node_fill'] );
		$node_stroke     = $renderer->color_rgb( 'node_stroke', self::COLORS['node_stroke'] );
		$text_color      = $renderer->color_rgb( 'text', self::COLORS['text'] );
		$connector_color = $renderer->color_rgb( 'connector', self::COLORS['connector'] );

		$renderer->fill( $bg_color );

		$width  = $renderer->get_width();
		$height = $renderer->get_height();

		// Draw title.
		if ( $title ) {
			$renderer->draw_text_centered( $title, 24, 40, $text_color, 'body' );
		}

		// Calculate layout.
		$layout = $this->calculate_layout( $nodes, $width, $height );

		// Draw connections first (behind nodes).
		foreach ( $connections as $conn ) {
			$from_id = $conn['from'] ?? null;
			$to_id   = $conn['to'] ?? null;
			$label   = $conn['label'] ?? '';

			if ( ! isset( $layout[ $from_id ] ) || ! isset( $layout[ $to_id ] ) ) {
				continue;
			}

			$from = $layout[ $from_id ];
			$to   = $layout[ $to_id ];

			// Calculate connection points (edge to edge).
			$start = $this->get_edge_point( $from, $to );
			$end   = $this->get_edge_point( $to, $from );

			$renderer->draw_arrow( $start['x'], $start['y'], $end['x'], $end['y'], $connector_color, 2 );

			// Connection label.
			if ( $label ) {
				$label_x = (int) ( ( $start['x'] + $end['x'] ) / 2 );
				$label_y = (int) ( ( $start['y'] + $end['y'] ) / 2 ) - 10;
				$renderer->draw_text_centered( $label, 10, $label_y, $text_color, 'body' );
			}
		}

		// Draw nodes.
		foreach ( $nodes as $node ) {
			$id    = $node['id'] ?? '';
			$label = $node['label'] ?? '';
			$type  = $node['type'] ?? 'process';

			if ( ! isset( $layout[ $id ] ) ) {
				continue;
			}

			$pos = $layout[ $id ];
			$this->draw_node( $renderer, $pos, $label, $type, $text_color );
		}

		$filename = 'diagram.' . ( 'jpeg' === $format ? 'jpg' : 'png' );

		$path = null;
		if ( ! empty( $context ) ) {
			$path = $renderer->save_to_repository( $filename, $context, $format );
		} else {
			$path = $renderer->save_temp( $format );
		}

		$renderer->destroy();

		return $path ? array( $path ) : array();
	}

	/**
	 * Calculate node positions using simple grid layout.
	 */
	private function calculate_layout( array $nodes, int $canvas_width, int $canvas_height ): array {
		$layout  = array();
		$cols    = 3; // Max columns.
		$start_x = self::PADDING + 40;
		$start_y = 100;
		$col     = 0;
		$row     = 0;

		foreach ( $nodes as $node ) {
			$x = $start_x + $col * ( self::NODE_WIDTH + self::NODE_GAP_X );
			$y = $start_y + $row * ( self::NODE_HEIGHT + self::NODE_GAP_Y );

			$layout[ $node['id'] ?? 'node_' . count( $layout ) ] = array(
				'x'        => $x,
				'y'        => $y,
				'width'    => self::NODE_WIDTH,
				'height'   => self::NODE_HEIGHT,
				'center_x' => $x + (int) ( self::NODE_WIDTH / 2 ),
				'center_y' => $y + (int) ( self::NODE_HEIGHT / 2 ),
			);

			++$col;
			if ( $col >= $cols ) {
				$col = 0;
				++$row;
			}
		}

		return $layout;
	}

	/**
	 * Draw a single node.
	 */
	private function draw_node( GDRenderer $renderer, array $pos, string $label, string $type, int $text_color ): void {
		$x        = $pos['x'];
		$y        = $pos['y'];
		$width    = $pos['width'];
		$height   = $pos['height'];
		$center_x = $pos['center_x'];
		$center_y = $pos['center_y'];

		$fill_color   = $renderer->color_rgb( 'node_fill', self::COLORS['node_fill'] );
		$stroke_color = $renderer->color_rgb( 'node_stroke', self::COLORS['node_stroke'] );

		// Node type styling.
		$node_colors = array(
			'start'    => self::COLORS['start'],
			'end'      => self::COLORS['end'],
			'decision' => self::COLORS['decision'],
			'process'  => self::COLORS['node_fill'],
		);

		$bg_rgb   = $node_colors[ $type ] ?? $node_colors['process'];
		$bg_color = $renderer->color_rgb( 'node_bg', $bg_rgb );

		match ( $type ) {
			'start', 'end' => $renderer->draw_ellipse( $center_x, $center_y, $width, $height, $bg_color, true ),
			'decision' => $renderer->draw_diamond( $center_x, $center_y, $width, $height, $bg_color, true ),
			default    => $renderer->draw_rounded_rect( $x, $y, $width, $height, $bg_color, 8 ),
		};

		// Border.
		match ( $type ) {
			'start', 'end' => $renderer->draw_ellipse( $center_x, $center_y, $width, $height, $stroke_color, false ),
			'decision' => $renderer->draw_diamond( $center_x, $center_y, $width, $height, $stroke_color, false ),
			default    => $renderer->draw_rect( $x, $y, $x + $width, $y + $height, $stroke_color, false ),
		};

		// Label - split on pipe for multiline.
		$lines       = explode( '|', $label );
		$line_height = 16;
		$start_y     = $center_y - ( count( $lines ) - 1 ) * ( $line_height / 2 );

		foreach ( $lines as $i => $line ) {
			$ly = $start_y + $i * $line_height;
			$renderer->draw_text_centered( trim( $line ), 12, $ly, $text_color, 'body' );
		}
	}

	/**
	 * Get the point where a line from one node to another should start/end (at the edge).
	 */
	private function get_edge_point( array $from, array $to ): array {
		$dx    = $to['center_x'] - $from['center_x'];
		$dy    = $to['center_y'] - $from['center_y'];
		$angle = atan2( $dy, $dx );

		// Calculate intersection with node boundary.
		$from_half_w = $from['width'] / 2;
		$from_half_h = $from['height'] / 2;

		// Simple box intersection - check which edge the line hits.
		if ( 0 === $dx ) {
			// Vertical line - hit top or bottom.
			$from['center_y'] += ( $dy > 0 ? $from_half_h : -$from_half_h );
		} elseif ( 0 === $dy ) {
			// Horizontal line - hit left or right.
			$from['center_x'] += ( $dx > 0 ? $from_half_w : -$from_half_w );
		} else {
			// Diagonal - calculate which edge is closer.
			$tan_angle = abs( tan( $angle ) );
			$slope_x   = $from_half_w;
			$slope_y   = $from_half_h * $tan_angle;

			if ( $slope_y < $slope_x ) {
				// Intersects left or right edge.
				$from['center_x'] += ( $dx > 0 ? $from_half_w : -$from_half_w );
			} else {
				// Intersects top or bottom edge.
				$from['center_y'] += ( $dy > 0 ? $from_half_h : -$from_half_h );
			}
		}

		return array(
			'x' => $from['center_x'],
			'y' => $from['center_y'],
		);
	}
}
