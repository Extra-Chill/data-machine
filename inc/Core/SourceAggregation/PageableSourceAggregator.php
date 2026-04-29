<?php
/**
 * Pageable source aggregation primitive.
 *
 * @package DataMachine\Core\SourceAggregation
 */

namespace DataMachine\Core\SourceAggregation;

defined( 'ABSPATH' ) || exit;

class PageableSourceAggregator {

	/**
	 * Aggregate a pageable source by repeatedly invoking a page callback.
	 *
	 * @param callable $page_callback Callback receiving page params and state.
	 * @param array    $config        Aggregation config.
	 * @return array Aggregation result.
	 */
	public function aggregate( callable $page_callback, array $config ): array {
		$pagination  = is_array( $config['pagination'] ?? null ) ? $config['pagination'] : array();
		$base_params = is_array( $config['params'] ?? null ) ? $config['params'] : array();

		$limit        = max( 1, (int) ( $pagination['limit'] ?? 100 ) );
		$start_offset = max( 0, (int) ( $pagination['start_offset'] ?? 0 ) );
		$offset_param = (string) ( $pagination['offset_param'] ?? 'offset' );
		$limit_param  = (string) ( $pagination['limit_param'] ?? 'limit' );
		$item_path    = (string) ( $pagination['item_path'] ?? 'items' );
		$total_path   = isset( $pagination['total_path'] ) ? (string) $pagination['total_path'] : null;

		$max_items               = max( 0, (int) ( $config['max_items'] ?? 1000 ) );
		$max_pages               = max( 1, (int) ( $config['max_pages'] ?? 100 ) );
		$group_by                = $this->normalizeStringList( $config['group_by'] ?? array() );
		$sample_limit_per_bucket = max( 0, (int) ( $config['sample_limit_per_bucket'] ?? 3 ) );

		$total       = null;
		$processed   = 0;
		$page_count  = 0;
		$groups      = array();
		$samples     = array();
		$diagnostics = array(
			'limit'        => $limit,
			'start_offset' => $start_offset,
			'max_items'    => $max_items,
			'max_pages'    => $max_pages,
		);

		for ( $page_index = 0; $page_index < $max_pages; ++$page_index ) {
			$offset                  = $start_offset + ( $page_index * $limit );
			$params                  = $base_params;
			$params[ $offset_param ] = $offset;
			$params[ $limit_param ]  = $limit;

			$page = $page_callback(
				$params,
				array(
					'page_index' => $page_index,
					'offset'     => $offset,
					'limit'      => $limit,
				)
			);

			++$page_count;

			if ( ! is_array( $page ) ) {
				$diagnostics['stop_reason'] = 'invalid_page';
				break;
			}

			if ( null === $total && null !== $total_path ) {
				$page_total = $this->getPath( $page, $total_path );
				if ( is_numeric( $page_total ) ) {
					$total = (int) $page_total;
				}
			}

			$items = $this->getPath( $page, $item_path );
			if ( ! is_array( $items ) || array() === $items ) {
				$diagnostics['stop_reason'] = 'empty_page';
				break;
			}

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$this->aggregateItem( $item, $group_by, $groups, $samples, $sample_limit_per_bucket );
				++$processed;

				if ( $max_items > 0 && $processed >= $max_items ) {
					$diagnostics['stop_reason'] = 'max_items';
					break 2;
				}
			}

			if ( null !== $total && $processed >= $total ) {
				$diagnostics['stop_reason'] = 'total_reached';
				break;
			}

			if ( count( $items ) < $limit ) {
				$diagnostics['stop_reason'] = 'short_page';
				break;
			}
		}

		if ( ! isset( $diagnostics['stop_reason'] ) ) {
			$diagnostics['stop_reason'] = 'max_pages';
		}

		return array(
			'total'       => $total ?? $processed,
			'processed'   => $processed,
			'pages'       => $page_count,
			'groups'      => $groups,
			'samples'     => $samples,
			'diagnostics' => $diagnostics,
		);
	}

	/**
	 * Read a top-level or dotted path from an array.
	 *
	 * @param array  $data Data to read.
	 * @param string $path Dotted path.
	 * @return mixed|null Path value, or null when missing.
	 */
	public function getPath( array $data, string $path ): mixed {
		if ( '' === $path ) {
			return $data;
		}

		$current = $data;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( is_array( $current ) && array_key_exists( $segment, $current ) ) {
				$current = $current[ $segment ];
				continue;
			}

			return null;
		}

		return $current;
	}

	private function aggregateItem( array $item, array $group_by, array &$groups, array &$samples, int $sample_limit_per_bucket ): void {
		foreach ( $group_by as $field ) {
			$value = $this->bucketValue( $this->getPath( $item, $field ) );

			$groups[ $field ][ $value ] = ( $groups[ $field ][ $value ] ?? 0 ) + 1;

			if ( $sample_limit_per_bucket <= 0 ) {
				continue;
			}

			$current_samples = $samples[ $field ][ $value ] ?? array();
			if ( count( $current_samples ) < $sample_limit_per_bucket ) {
				$current_samples[]           = $item;
				$samples[ $field ][ $value ] = $current_samples;
			}
		}
	}

	private function normalizeStringList( mixed $value ): array {
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$value = array_map( 'trim', explode( ',', $value ) );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'strval', $value ),
				fn( string $item ): bool => '' !== $item
			)
		);
	}

	private function bucketValue( mixed $value ): string {
		if ( null === $value || '' === $value ) {
			return '(missing)';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		$encoded = wp_json_encode( $value );

		return is_string( $encoded ) && '' !== $encoded ? $encoded : '(unserializable)';
	}
}
