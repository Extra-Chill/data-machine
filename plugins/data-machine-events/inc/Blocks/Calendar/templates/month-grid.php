<?php
/**
 * Month Grid Template
 *
 * Server-renders the month-grid display mode for the calendar block.
 *
 * Implements the continuous-ribbon multi-day model: each multi-day
 * event spans one ribbon per row it intersects (week-boundary cuts
 * produce two ribbons with continuation indicators).
 *
 * Sister template to `date-group.php` / `event-item.php`. Both grid
 * and list templates render server-side; CSS picks one via the
 * `[data-display-mode="month-grid"]` viewport switch.
 *
 * @var array $grid     Structured grid from MonthGridBuilder::build().
 * @var string $base_url Base URL for prev/next/today nav (typically the
 *                       current archive URL with non-`month` query args
 *                       preserved by the consumer).
 *
 * @package DataMachineEvents\Blocks\Calendar\templates
 * @since   0.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $grid ) || ! is_array( $grid ) ) {
	return;
}

$base_url = isset( $base_url ) ? (string) $base_url : '';

$build_month_url = static function ( string $month_yyyymm ) use ( $base_url ): string {
	if ( '' === $month_yyyymm ) {
		return $base_url ?: '#';
	}
	$base = $base_url ?: '';
	$sep  = ( false === strpos( $base, '?' ) ) ? '?' : '&';
	return $base . $sep . 'month=' . rawurlencode( $month_yyyymm );
};

$weekday_labels = array(
	'sunday'    => __( 'Sun', 'data-machine-events' ),
	'monday'    => __( 'Mon', 'data-machine-events' ),
	'tuesday'   => __( 'Tue', 'data-machine-events' ),
	'wednesday' => __( 'Wed', 'data-machine-events' ),
	'thursday'  => __( 'Thu', 'data-machine-events' ),
	'friday'    => __( 'Fri', 'data-machine-events' ),
	'saturday'  => __( 'Sat', 'data-machine-events' ),
);
?>
<div class="data-machine-month-grid" data-month="<?php echo esc_attr( $grid['month'] ); ?>">
	<header class="data-machine-month-grid__nav">
		<a class="data-machine-month-grid__nav-prev" rel="prev"
			href="<?php echo esc_url( $build_month_url( $grid['prev_month'] ) ); ?>"
			data-month="<?php echo esc_attr( $grid['prev_month'] ); ?>">
			<span aria-hidden="true">&larr;</span>
			<span class="screen-reader-text"><?php esc_html_e( 'Previous month', 'data-machine-events' ); ?></span>
		</a>
		<h2 class="data-machine-month-grid__title">
			<?php echo esc_html( $grid['month_label'] ); ?>
		</h2>
		<a class="data-machine-month-grid__nav-today"
			href="<?php echo esc_url( $build_month_url( $grid['today_month'] ) ); ?>"
			data-month="<?php echo esc_attr( $grid['today_month'] ); ?>">
			<?php esc_html_e( 'Today', 'data-machine-events' ); ?>
		</a>
		<a class="data-machine-month-grid__nav-next" rel="next"
			href="<?php echo esc_url( $build_month_url( $grid['next_month'] ) ); ?>"
			data-month="<?php echo esc_attr( $grid['next_month'] ); ?>">
			<span class="screen-reader-text"><?php esc_html_e( 'Next month', 'data-machine-events' ); ?></span>
			<span aria-hidden="true">&rarr;</span>
		</a>
	</header>

	<div class="data-machine-month-grid__weekdays" role="row">
		<?php foreach ( $grid['weekday_keys'] as $weekday_key ) : ?>
			<div class="data-machine-month-grid__weekday data-machine-day-<?php echo esc_attr( $weekday_key ); ?>" role="columnheader">
				<?php echo esc_html( $weekday_labels[ $weekday_key ] ?? ucfirst( $weekday_key ) ); ?>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="data-machine-month-grid__body">
		<?php foreach ( $grid['rows'] as $row_index => $row ) : ?>
			<?php
			$lane_count = 0;
			foreach ( $row['ribbons'] as $ribbon ) {
				if ( $ribbon['lane'] + 1 > $lane_count ) {
					$lane_count = $ribbon['lane'] + 1;
				}
			}
			?>
			<div class="data-machine-month-grid__row"
				data-row-index="<?php echo esc_attr( (string) $row_index ); ?>"
				data-row-start="<?php echo esc_attr( $row['start_date'] ); ?>"
				data-row-end="<?php echo esc_attr( $row['end_date'] ); ?>"
				style="--data-machine-month-grid-lanes: <?php echo esc_attr( (string) $lane_count ); ?>;">
				<?php foreach ( $row['cells'] as $cell ) :
					$cell_classes = array(
						'data-machine-month-grid__cell',
						'data-machine-day-' . $cell['day_of_week'],
					);
					if ( $cell['is_today'] ) {
						$cell_classes[] = 'is-today';
					}
					if ( $cell['is_past'] ) {
						$cell_classes[] = 'is-past';
					}
					if ( $cell['is_other_month'] ) {
						$cell_classes[] = 'is-other-month';
					}
					?>
					<div class="<?php echo esc_attr( implode( ' ', $cell_classes ) ); ?>"
						data-date="<?php echo esc_attr( $cell['date'] ); ?>">
						<span class="data-machine-month-grid__date-number" aria-hidden="true">
							<?php echo esc_html( (string) $cell['day_number'] ); ?>
						</span>
						<?php if ( ! empty( $cell['single_day_events'] ) ) : ?>
							<div class="data-machine-month-grid__events">
								<?php foreach ( $cell['single_day_events'] as $event ) : ?>
									<a class="data-machine-month-grid__event-strip"
										href="<?php echo esc_url( $event['permalink'] ); ?>"
										title="<?php echo esc_attr( $event['title'] ); ?>">
										<span class="data-machine-month-grid__event-title">
											<?php echo esc_html( $event['title'] ); ?>
										</span>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

				<?php if ( ! empty( $row['ribbons'] ) ) : ?>
					<div class="data-machine-month-grid__ribbons" aria-hidden="false">
						<?php foreach ( $row['ribbons'] as $ribbon ) :
							$ribbon_classes = array(
								'data-machine-month-grid__ribbon',
								'data-machine-day-' . $ribbon['day_of_week'],
							);
							if ( $ribbon['continues_left'] ) {
								$ribbon_classes[] = 'is-continues-left';
							}
							if ( $ribbon['continues_right'] ) {
								$ribbon_classes[] = 'is-continues-right';
							}
							?>
							<a class="<?php echo esc_attr( implode( ' ', $ribbon_classes ) ); ?>"
								href="<?php echo esc_url( $ribbon['permalink'] ); ?>"
								title="<?php echo esc_attr( $ribbon['title'] ); ?>"
								style="--data-machine-month-grid-ribbon-start: <?php echo esc_attr( (string) ( $ribbon['start_col'] + 1 ) ); ?>; --data-machine-month-grid-ribbon-span: <?php echo esc_attr( (string) $ribbon['span'] ); ?>; --data-machine-month-grid-ribbon-lane: <?php echo esc_attr( (string) $ribbon['lane'] ); ?>;">
								<span class="data-machine-month-grid__ribbon-title">
									<?php echo esc_html( $ribbon['title'] ); ?>
								</span>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
</div>
