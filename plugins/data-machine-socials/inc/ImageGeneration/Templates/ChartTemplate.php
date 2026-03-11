<?php
/**
 * Chart Template
 *
 * Generates chart images from structured data — bar, line, and pie charts.
 * Useful for analytics visualizations, statistics, and data-driven social content.
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

class ChartTemplate implements TemplateInterface {

	/**
	 * Layout constants.
	 */
	private const PADDING      = 60;
	private const CHART_MARGIN = 40;
	private const LEGEND_SIZE  = 24;

	/**
	 * Default colors — brand-consistent palette.
	 */
	private const COLORS = array(
		'background' => array( 255, 255, 255 ),
		'title'      => array( 26, 26, 26 ),
		'axis'       => array( 180, 180, 180 ),
		'axis_label' => array( 100, 100, 100 ),
		'grid'       => array( 230, 230, 230 ),
		'legend'     => array( 60, 60, 60 ),
	);

	public function get_id(): string {
		return 'chart';
	}

	public function get_name(): string {
		return 'Chart';
	}

	public function get_description(): string {
		return 'Generate bar, line, or pie charts from structured data. Useful for analytics and statistics.';
	}

	public function get_fields(): array {
		return array(
			'chart_type'    => array(
				'label'    => 'Chart Type',
				'type'     => 'string',
				'required' => true,
			),
			'title'         => array(
				'label'    => 'Chart Title',
				'type'     => 'string',
				'required' => false,
			),
			'labels'        => array(
				'label'    => 'Labels',
				'type'     => 'array',
				'required' => true,
			),
			'values'        => array(
				'label'    => 'Values',
				'type'     => 'array',
				'required' => true,
			),
			'dataset_label' => array(
				'label'    => 'Dataset Label',
				'type'     => 'string',
				'required' => false,
			),
		);
	}

	public function get_default_preset(): string {
		return 'instagram_feed_portrait';
	}

	/**
	 * Render a chart.
	 *
	 * @param array      $data     Chart data.
	 * @param GDRenderer $renderer GD renderer instance.
	 * @param array      $options  Render options.
	 * @return string[] Generated image file paths.
	 */
	public function render( array $data, GDRenderer $renderer, array $options = array() ): array {
		$chart_type = $data['chart_type'] ?? 'bar';
		$title      = $data['title'] ?? '';
		$labels     = $data['labels'] ?? array();
		$values     = $data['values'] ?? array();
		$preset     = $options['preset'] ?? $this->get_default_preset();
		$format     = $options['format'] ?? 'png';
		$context    = $options['context'] ?? array();

		if ( empty( $values ) ) {
			return array();
		}

		$renderer->create_canvas( $preset );

		if ( ! $renderer->get_image() ) {
			return array();
		}

		$renderer->register_font( 'title', 'WilcoLoftSans-Treble.ttf' );
		$renderer->register_font( 'body', 'helvetica.ttf' );

		// Allocate colors.
		$bg_color    = $renderer->color_rgb( 'background', self::COLORS['background'] );
		$title_color = $renderer->color_rgb( 'title', self::COLORS['title'] );
		$axis_color  = $renderer->color_rgb( 'axis', self::COLORS['axis'] );
		$grid_color  = $renderer->color_rgb( 'grid', self::COLORS['grid'] );
		$label_color = $renderer->color_rgb( 'axis_label', self::COLORS['axis_label'] );

		$renderer->fill( $bg_color );

		$width  = $renderer->get_width();
		$height = $renderer->get_height();

		$y = self::PADDING;

		// Draw title.
		if ( $title ) {
			$renderer->draw_text_centered( $title, 28, $y + 28, $title_color, 'title' );
			$y += 60;
		}

		$chart_area = array(
			'x'      => self::PADDING,
			'y'      => $y + 20,
			'width'  => $width - ( self::PADDING * 2 ),
			'height' => $height - $y - self::PADDING - 80,
		);

		// Route to chart-specific rendering.
		$path = match ( $chart_type ) {
			'line'   => $this->render_line_chart( $renderer, $labels, $values, $chart_area, $grid_color, $label_color ),
			'pie'    => $this->render_pie_chart( $renderer, $labels, $values, $chart_area ),
			default  => $this->render_bar_chart( $renderer, $labels, $values, $chart_area, $grid_color, $label_color ),
		};

		if ( ! $path ) {
			$filename = 'chart.' . ( 'jpeg' === $format ? 'jpg' : 'png' );

			if ( ! empty( $context ) ) {
				$path = $renderer->save_to_repository( $filename, $context, $format );
			} else {
				$path = $renderer->save_temp( $format );
			}
		}

		$renderer->destroy();

		return $path ? array( $path ) : array();
	}

	/**
	 * Render a bar chart.
	 */
	private function render_bar_chart( GDRenderer $renderer, array $labels, array $values, array $area, int $grid_color, int $label_color ): ?string {
		$count   = count( $values );
		$max_val = max( $values ) > 0 ? max( $values ) : 1;

		$bar_width = (int) ( ( $area['width'] - ( $count - 1 ) * 10 ) / $count );
		$bar_gap   = 10;

		// Draw grid lines.
		$grid_lines = 5;
		for ( $i = 0; $i <= $grid_lines; $i++ ) {
			$y = $area['y'] + (int) ( $area['height'] * $i / $grid_lines );
			$renderer->draw_line( $area['x'], $y, $area['x'] + $area['width'], $y, $grid_color, 1 );
		}

		// Draw bars.
		$colors   = $renderer->get_chart_palette( $count );
		$baseline = $area['y'] + $area['height'];

		foreach ( $values as $i => $val ) {
			$bar_height = (int) ( ( $val / $max_val ) * $area['height'] );
			$x          = $area['x'] + $i * ( $bar_width + $bar_gap );

			$renderer->draw_bar( $x, 0, $bar_width, $bar_height, $colors[ $i ], $baseline );

			// Label.
			$label = $labels[ $i ] ?? '';
			if ( $label ) {
				$label_x = $x + (int) ( $bar_width / 2 );
				$renderer->draw_text_centered( $label, 12, $baseline + 20, $label_color, 'body' );
			}
		}

		return null;
	}

	/**
	 * Render a line chart.
	 */
	private function render_line_chart( GDRenderer $renderer, array $labels, array $values, array $area, int $grid_color, int $label_color ): ?string {
		$count   = count( $values );
		$max_val = max( $values ) > 0 ? max( $values ) : 1;

		// Draw grid lines.
		$grid_lines = 5;
		for ( $i = 0; $i <= $grid_lines; $i++ ) {
			$y = $area['y'] + (int) ( $area['height'] * $i / $grid_lines );
			$renderer->draw_line( $area['x'], $y, $area['x'] + $area['width'], $y, $grid_color, 1 );
		}

		// Calculate points.
		$points = array();
		$step_x = $area['width'] / ( $count > 1 ? $count - 1 : 1 );

		foreach ( $values as $i => $val ) {
			$x        = $area['x'] + (int) ( $i * $step_x );
			$y        = $area['y'] + $area['height'] - (int) ( ( $val / $max_val ) * $area['height'] );
			$points[] = array( $x, $y );
		}

		// Draw lines between points.
		$line_color   = $renderer->color( 'line', 83, 148, 11 ); // Brand green.
		$points_count = count( $points );
		for ( $i = 0; $i < $points_count - 1; $i++ ) {
			$renderer->draw_line( $points[ $i ][0], $points[ $i ][1], $points[ $i + 1 ][0], $points[ $i + 1 ][1], $line_color, 3 );
		}

		// Draw points.
		foreach ( $points as $point ) {
			$renderer->draw_point( $point[0], $point[1], $line_color, 5 );
		}

		// Labels.
		$baseline = $area['y'] + $area['height'];
		foreach ( $labels as $i => $label ) {
			$x = $area['x'] + (int) ( $i * $step_x );
			$renderer->draw_text_centered( $label, 12, $baseline + 20, $label_color, 'body' );
		}

		return null;
	}

	/**
	 * Render a pie chart.
	 */
	private function render_pie_chart( GDRenderer $renderer, array $labels, array $values, array $area ): ?string {
		$total  = array_sum( $values );
		$count  = count( $values );
		$colors = $renderer->get_chart_palette( $count );

		$center_x = $area['x'] + (int) ( $area['width'] / 2 );
		$center_y = $area['y'] + (int) ( $area['height'] / 2 );
		$radius   = min( $area['width'], $area['height'] ) / 2 - 20;

		// Draw pie slices manually using filled arcs.
		$start_angle = 0;

		foreach ( $values as $i => $val ) {
			$sweep_angle = $total > 0 ? ( $val / $total ) * 360 : 0;

			// Draw filled arc slice.
			$this->draw_pie_slice( $renderer, $center_x, $center_y, $radius, $start_angle, $sweep_angle, $colors[ $i ] );

			$start_angle += $sweep_angle;
		}

		// Draw legend.
		$legend_y = $area['y'] + $area['height'] + 30;
		$legend_x = self::PADDING;

		foreach ( $labels as $i => $label ) {
			$val_str     = $values[ $i ] ?? 0;
			$legend_text = "{$label}: {$val_str}";

			$renderer->filled_rect( $legend_x, $legend_y - 12, $legend_x + 12, $legend_y, $colors[ $i ], true );
			$renderer->draw_text( $legend_text, 12, $legend_x + 18, $legend_y, $colors[ $i ], 'body' );

			$legend_x += $renderer->measure_text_width( $legend_text, 12, 'body' ) + 30;
		}

		return null;
	}

	/**
	 * Draw a filled pie slice.
	 */
	private function draw_pie_slice( GDRenderer $renderer, int $cx, int $cy, int $radius, float $start_deg, float $sweep_deg, int $color ): void {
		if ( ! $renderer->get_image() ) {
			return;
		}

		$image = $renderer->get_image();
		$steps = max( 36, (int) ( abs( $sweep_deg ) / 5 ) );

		// Draw the slice as a polygon.
		$points   = array();
		$points[] = $cx;
		$points[] = $cy;

		for ( $i = 0; $i <= $steps; $i++ ) {
			$angle    = deg2rad( $start_deg + ( $sweep_deg * $i / $steps ) );
			$points[] = $cx + (int) ( $radius * cos( $angle ) );
			$points[] = $cy + (int) ( $radius * sin( $angle ) );
		}

		imagefilledpolygon( $image, $points, count( $points ) / 2, $color );
		imagedashedline( $image, $cx, $cy, $cx + (int) ( $radius * cos( deg2rad( $start_deg ) ) ), $cy + (int) ( $radius * sin( deg2rad( $start_deg ) ) ), $color );
	}
}
