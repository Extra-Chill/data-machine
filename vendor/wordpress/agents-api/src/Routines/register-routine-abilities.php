<?php
/**
 * Routine abilities: reconcile the registry against the scheduler store.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Routines;

defined( 'ABSPATH' ) || exit;

const AGENTS_RECONCILE_ROUTINES_ABILITY = 'agents/reconcile-routines';

add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( ! function_exists( 'wp_register_ability' ) || wp_has_ability( AGENTS_RECONCILE_ROUTINES_ABILITY ) ) {
			return;
		}

		wp_register_ability(
			AGENTS_RECONCILE_ROUTINES_ABILITY,
			array(
				'label'               => 'Reconcile Routines',
				'description'         => 'Reconcile registered routines against the Action Scheduler store: enqueue missing routine schedules and remove orphaned ones. Supports a dry-run mode that only reports.',
				'category'            => 'agents-api',
				'input_schema'        => array(
					'type'       => 'object',
					'default'    => array(),
					'properties' => array(
						'dry_run' => array(
							'type'        => 'boolean',
							'description' => 'Report what would change without enqueueing, removing, or writing any state.',
							'default'     => false,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'enqueued'  => array(
							'type'        => 'array',
							'description' => 'Routine ids whose missing schedule was (or would be) enqueued.',
							'items'       => array( 'type' => 'string' ),
						),
						'removed'   => array(
							'type'        => 'array',
							'description' => 'Routine ids whose orphaned scheduled action was (or would be) removed.',
							'items'       => array( 'type' => 'string' ),
						),
						'unchanged' => array(
							'type'        => 'array',
							'description' => 'Routine ids whose schedule was already covered.',
							'items'       => array( 'type' => 'string' ),
						),
						'errors'    => array(
							'type'        => 'object',
							'description' => 'Failure messages keyed by routine id (or a `_`-prefixed key for run-level failures).',
						),
					),
				),
				'execute_callback'    => __NAMESPACE__ . '\\agents_reconcile_routines',
				'permission_callback' => __NAMESPACE__ . '\\agents_reconcile_routines_permission',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'destructive' => true,
						'idempotent'  => true,
					),
				),
			)
		);
	}
);

/**
 * Run the routine/schedule reconciliation.
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>
 */
function agents_reconcile_routines( array $input ) {
	return WP_Agent_Routine_Registry::reconcile(
		array( 'dry_run' => ! empty( $input['dry_run'] ) )
	);
}

/**
 * Reconcile mutates scheduler state site-wide; gate on manage_options,
 * filterable like the rest of the module surface.
 *
 * @param array<string,mixed> $input Ability input.
 */
function agents_reconcile_routines_permission( array $input ): bool {
	$allowed = function_exists( 'current_user_can' ) ? current_user_can( 'manage_options' ) : false;
	return (bool) apply_filters( 'agents_reconcile_routines_permission', $allowed, $input );
}
