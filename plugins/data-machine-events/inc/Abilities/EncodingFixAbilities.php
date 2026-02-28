<?php
/**
 * Encoding Fix Abilities
 *
 * Provides detection and repair of Unicode encoding issues in event block attributes.
 * Fixes escaped sequences like \u00a3 to proper UTF-8 characters.
 *
 * Abilities API integration pattern:
 * - Registers ability via wp_register_ability() on wp_abilities_api_init hook
 * - Static $registered flag prevents duplicate registration when instantiated multiple times
 * - execute_callback receives validated input, returns structured result
 * - permission_callback enforces admin capability requirement
 *
 * @package DataMachineEvents\Abilities
 * @since 0.10.10
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\Event_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EncodingFixAbilities {

	private const DEFAULT_LIMIT = 100;
	private const BLOCK_NAME    = 'data-machine-events/event-details';

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbility();
			self::$registered = true;
		}
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'data-machine-events/fix-encoding',
				array(
					'label'               => __( 'Fix Encoding', 'data-machine-events' ),
					'description'         => __( 'Fix Unicode encoding issues in event block attributes', 'data-machine-events' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'scope'   => array(
								'type'        => 'string',
								'enum'        => array( 'upcoming', 'all', 'past' ),
								'description' => 'Which events to scan: "upcoming" (default), "all", or "past"',
							),
							'limit'   => array(
								'type'        => 'integer',
								'description' => 'Maximum events to process (default: 100)',
							),
							'dry_run' => array(
								'type'        => 'boolean',
								'description' => 'Preview changes without applying (default: true)',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'       => array( 'type' => 'boolean' ),
							'total_scanned' => array( 'type' => 'integer' ),
							'total_matched' => array( 'type' => 'integer' ),
							'events'        => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'id'              => array( 'type' => 'integer' ),
										'title'           => array( 'type' => 'string' ),
										'affected_fields' => array( 'type' => 'array' ),
										'changes'         => array( 'type' => 'object' ),
										'status'          => array( 'type' => 'string' ),
									),
								),
							),
							'summary'       => array(
								'type'       => 'object',
								'properties' => array(
									'scanned' => array( 'type' => 'integer' ),
									'matched' => array( 'type' => 'integer' ),
									'updated' => array( 'type' => 'integer' ),
									'failed'  => array( 'type' => 'integer' ),
								),
							),
							'message'       => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeEncodingFix' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute encoding fix.
	 *
	 * @param array $input Input parameters
	 * @return array Results with matched events and fix status
	 */
	public function executeEncodingFix( array $input ): array {
		$scope   = $input['scope'] ?? 'upcoming';
		$limit   = (int) ( $input['limit'] ?? self::DEFAULT_LIMIT );
		$dry_run = $input['dry_run'] ?? true;

		if ( $limit <= 0 ) {
			$limit = self::DEFAULT_LIMIT;
		}

		$events = $this->queryEvents( $scope );

		if ( is_wp_error( $events ) ) {
			return array( 'error' => 'Query failed: ' . $events->get_error_message() );
		}

		$total_scanned = count( $events );

		if ( empty( $events ) ) {
			return array(
				'dry_run'       => $dry_run,
				'total_scanned' => 0,
				'total_matched' => 0,
				'events'        => array(),
				'summary'       => array(
					'scanned' => 0,
					'matched' => 0,
					'updated' => 0,
					'failed'  => 0,
				),
				'message'       => 'No events found matching the specified scope.',
			);
		}

		$results       = array();
		$matched_count = 0;
		$updated_count = 0;
		$failed_count  = 0;

		foreach ( $events as $event ) {
			$block_attrs = $this->extractBlockAttributes( $event->ID );

			if ( empty( $block_attrs ) ) {
				continue;
			}

			$encoding_issues = $this->checkEncodingIssues( $block_attrs );

			if ( empty( $encoding_issues ) ) {
				continue;
			}

			++$matched_count;

			if ( $matched_count > $limit ) {
				break;
			}

			$changes = $this->calculateFixes( $block_attrs, $encoding_issues );

			$status = 'would fix';
			if ( ! $dry_run ) {
				$update_result = $this->applyFixes( $event->ID, $block_attrs, $encoding_issues );
				$status        = $update_result ? 'fixed' : 'failed';
				if ( $update_result ) {
					++$updated_count;
				} else {
					++$failed_count;
				}
			}

			$results[] = array(
				'id'              => $event->ID,
				'title'           => $event->post_title,
				'affected_fields' => $encoding_issues,
				'changes'         => $changes,
				'status'          => $status,
			);
		}

		$message = $this->buildSummaryMessage( $dry_run, $total_scanned, $matched_count, $updated_count, $failed_count );

		return array(
			'dry_run'       => $dry_run,
			'total_scanned' => $total_scanned,
			'total_matched' => $matched_count,
			'events'        => $results,
			'summary'       => array(
				'scanned' => $total_scanned,
				'matched' => $matched_count,
				'updated' => $updated_count,
				'failed'  => $failed_count,
			),
			'message'       => $message,
		);
	}

	/**
	 * Query events based on scope.
	 *
	 * @param string $scope 'upcoming', 'past', or 'all'
	 * @return array|\WP_Error Array of WP_Post objects or WP_Error
	 */
	private function queryEvents( string $scope ): array|\WP_Error {
		$args = array(
			'post_type'      => Event_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'meta_value',
			'meta_key'       => Event_Post_Type::EVENT_DATE_META_KEY,
			'order'          => 'ASC',
		);

		$now = current_time( 'Y-m-d H:i:s' );

		if ( 'upcoming' === $scope ) {
			$args['meta_query'] = array(
				array(
					'key'     => Event_Post_Type::EVENT_DATE_META_KEY,
					'value'   => $now,
					'compare' => '>=',
					'type'    => 'DATETIME',
				),
			);
		} elseif ( 'past' === $scope ) {
			$args['meta_query'] = array(
				array(
					'key'     => Event_Post_Type::EVENT_DATE_META_KEY,
					'value'   => $now,
					'compare' => '<',
					'type'    => 'DATETIME',
				),
			);
			$args['order']      = 'DESC';
		}

		$query = new \WP_Query( $args );

		return $query->posts;
	}

	/**
	 * Extract Event Details block attributes.
	 *
	 * @param int $post_id Post ID
	 * @return array Block attributes
	 */
	private function extractBlockAttributes( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$blocks = parse_blocks( $post->post_content );
		foreach ( $blocks as $block ) {
			if ( self::BLOCK_NAME === $block['blockName'] ) {
				return $block['attrs'] ?? array();
			}
		}

		return array();
	}

	/**
	 * Check for Unicode encoding issues in block attributes.
	 *
	 * @param array $attrs Block attributes to check
	 * @return array List of affected field names
	 */
	private function checkEncodingIssues( array $attrs ): array {
		$fields_to_check = array( 'price', 'venue', 'address' );
		$affected_fields = array();

		foreach ( $fields_to_check as $field ) {
			if ( empty( $attrs[ $field ] ) ) {
				continue;
			}
			if ( preg_match( '/\\\\u[0-9a-fA-F]{4}/', $attrs[ $field ] ) ) {
				$affected_fields[] = $field;
			}
		}

		return $affected_fields;
	}

	/**
	 * Calculate what fixes would be applied.
	 *
	 * @param array $attrs Block attributes
	 * @param array $affected_fields Fields with encoding issues
	 * @return array Field => [before, after] changes
	 */
	private function calculateFixes( array $attrs, array $affected_fields ): array {
		$changes = array();

		foreach ( $affected_fields as $field ) {
			$original = $attrs[ $field ];
			$fixed    = $this->decodeUnicodeSequences( $original );

			$changes[ $field ] = array(
				'before' => $original,
				'after'  => $fixed,
			);
		}

		return $changes;
	}

	/**
	 * Decode escaped unicode sequences to UTF-8 characters.
	 *
	 * @param string $value String with potential \uXXXX sequences
	 * @return string Decoded string
	 */
	private function decodeUnicodeSequences( string $value ): string {
		return preg_replace_callback(
			'/\\\\u([0-9a-fA-F]{4})/',
			function ( $matches ) {
				return mb_convert_encoding( pack( 'H*', $matches[1] ), 'UTF-8', 'UTF-16BE' );
			},
			$value
		);
	}

	/**
	 * Apply encoding fixes to an event's block attributes.
	 *
	 * @param int   $post_id Post ID
	 * @param array $attrs Current block attributes
	 * @param array $affected_fields Fields to fix
	 * @return bool Success
	 */
	private function applyFixes( int $post_id, array $attrs, array $affected_fields ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$blocks      = parse_blocks( $post->post_content );
		$block_index = null;

		foreach ( $blocks as $index => $block ) {
			if ( self::BLOCK_NAME === $block['blockName'] ) {
				$block_index = $index;
				break;
			}
		}

		if ( null === $block_index ) {
			return false;
		}

		foreach ( $affected_fields as $field ) {
			if ( ! empty( $blocks[ $block_index ]['attrs'][ $field ] ) ) {
				$blocks[ $block_index ]['attrs'][ $field ] = $this->decodeUnicodeSequences(
					$blocks[ $block_index ]['attrs'][ $field ]
				);
			}
		}

		$new_content = serialize_blocks( $blocks );

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new_content,
			),
			true
		);

		return ! is_wp_error( $result );
	}

	/**
	 * Build summary message.
	 *
	 * @param bool $dry_run Whether this is a dry run
	 * @param int  $scanned Total scanned
	 * @param int  $matched Total matched
	 * @param int  $updated Updated count
	 * @param int  $failed Failed count
	 * @return string Summary message
	 */
	private function buildSummaryMessage( bool $dry_run, int $scanned, int $matched, int $updated, int $failed ): string {
		if ( 0 === $matched ) {
			return "Scanned {$scanned} events. No encoding issues found.";
		}

		if ( $dry_run ) {
			return "Scanned {$scanned} events. Found {$matched} with encoding issues. Run with --execute to apply fixes.";
		}

		$parts = array();
		if ( $updated > 0 ) {
			$parts[] = "{$updated} fixed";
		}
		if ( $failed > 0 ) {
			$parts[] = "{$failed} failed";
		}

		return "Scanned {$scanned} events. " . implode( ', ', $parts ) . '.';
	}
}
