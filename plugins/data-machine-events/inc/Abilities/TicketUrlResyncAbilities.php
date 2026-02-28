<?php
/**
 * Ticket URL Resync Abilities
 *
 * Re-normalizes ticket URL meta from block content to recover from
 * the v0.8.39 bug that stripped identity parameters from affiliate URLs.
 *
 * Abilities API integration pattern:
 * - Registers ability via wp_register_ability() on wp_abilities_api_init hook
 * - Static $registered flag prevents duplicate registration when instantiated multiple times
 * - execute_callback receives validated input, returns structured result
 * - permission_callback enforces admin capability requirement
 *
 * @package DataMachineEvents\Abilities
 * @since 0.10.11
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\Event_Post_Type;
use function DataMachineEvents\Core\datamachine_normalize_ticket_url;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TicketUrlResyncAbilities {

	private const DEFAULT_LIMIT = -1;
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
				'data-machine-events/resync-ticket-urls',
				array(
					'label'               => __( 'Resync Ticket URLs', 'data-machine-events' ),
					'description'         => __( 'Re-normalize ticket URL meta from block content', 'data-machine-events' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'     => array(
								'type'        => 'boolean',
								'description' => 'Preview changes without applying (default: true)',
								'default'     => true,
							),
							'limit'       => array(
								'type'        => 'integer',
								'description' => 'Maximum events to process (default: -1 for all)',
								'default'     => -1,
							),
							'future_only' => array(
								'type'        => 'boolean',
								'description' => 'Only process events with future start dates (default: false)',
								'default'     => false,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run' => array( 'type' => 'boolean' ),
							'updated' => array( 'type' => 'integer' ),
							'skipped' => array( 'type' => 'integer' ),
							'changes' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'post_id' => array( 'type' => 'integer' ),
										'title'   => array( 'type' => 'string' ),
										'old'     => array( 'type' => 'string' ),
										'new'     => array( 'type' => 'string' ),
									),
								),
							),
							'message' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeResync' ),
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
	 * Execute ticket URL resync.
	 *
	 * @param array $input Input parameters
	 * @return array Results with updated counts and change details
	 */
	public function executeResync( array $input ): array {
		$dry_run     = $input['dry_run'] ?? true;
		$limit       = (int) ( $input['limit'] ?? self::DEFAULT_LIMIT );
		$future_only = $input['future_only'] ?? false;

		$args = array(
			'post_type'      => Event_Post_Type::POST_TYPE,
			'posts_per_page' => $limit,
			'post_status'    => 'any',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( $future_only ) {
			$args['meta_query'] = array(
				array(
					'key'     => '_datamachine_event_datetime',
					'value'   => current_time( 'Y-m-d' ),
					'compare' => '>=',
					'type'    => 'DATE',
				),
			);
		}

		$events  = get_posts( $args );
		$updated = 0;
		$skipped = 0;
		$changes = array();

		foreach ( $events as $event ) {
			$ticket_url = $this->extractTicketUrl( $event->ID );

			if ( empty( $ticket_url ) ) {
				++$skipped;
				continue;
			}

			$new_normalized = datamachine_normalize_ticket_url( $ticket_url );
			$old_normalized = get_post_meta( $event->ID, '_datamachine_ticket_url', true );

			if ( $new_normalized !== $old_normalized ) {
				if ( ! $dry_run ) {
					update_post_meta( $event->ID, '_datamachine_ticket_url', $new_normalized );
				}
				$changes[] = array(
					'post_id' => $event->ID,
					'title'   => $event->post_title,
					'old'     => $old_normalized ? $old_normalized : '(empty)',
					'new'     => $new_normalized,
				);
				++$updated;
			} else {
				++$skipped;
			}
		}

		$message = $this->buildSummaryMessage( $dry_run, $updated, $skipped );

		return array(
			'dry_run' => $dry_run,
			'updated' => $updated,
			'skipped' => $skipped,
			'changes' => $changes,
			'message' => $message,
		);
	}

	/**
	 * Extract ticket URL from Event Details block.
	 *
	 * @param int $post_id Post ID
	 * @return string Ticket URL or empty string
	 */
	private function extractTicketUrl( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$blocks = parse_blocks( $post->post_content );
		return $this->findTicketUrlInBlocks( $blocks );
	}

	/**
	 * Recursively search blocks for ticket URL.
	 *
	 * @param array $blocks Block array
	 * @return string Ticket URL or empty string
	 */
	private function findTicketUrlInBlocks( array $blocks ): string {
		foreach ( $blocks as $block ) {
			if ( self::BLOCK_NAME === $block['blockName'] ) {
				return $block['attrs']['ticketUrl'] ?? '';
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$result = $this->findTicketUrlInBlocks( $block['innerBlocks'] );
				if ( $result ) {
					return $result;
				}
			}
		}
		return '';
	}

	/**
	 * Build summary message.
	 *
	 * @param bool $dry_run Whether this is a dry run
	 * @param int  $updated Updated count
	 * @param int  $skipped Skipped count
	 * @return string Summary message
	 */
	private function buildSummaryMessage( bool $dry_run, int $updated, int $skipped ): string {
		if ( $dry_run ) {
			return "{$updated} events would be updated, {$skipped} skipped. Run with --execute to apply.";
		}

		return "Updated: {$updated}, Skipped: {$skipped}";
	}
}
