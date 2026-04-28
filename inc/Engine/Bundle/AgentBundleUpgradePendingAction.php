<?php
/**
 * PendingAction integration for agent bundle upgrades.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

use DataMachine\Engine\AI\Actions\PendingActionHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Stages and applies approval-gated bundle artifact upgrades.
 */
final class AgentBundleUpgradePendingAction {

	public const KIND = 'bundle_upgrade';

	/**
	 * Stage a bundle upgrade plan for human review.
	 *
	 * @param AgentBundleUpgradePlan $plan Upgrade plan.
	 * @param array<string,mixed>    $args Staging args.
	 * @return array PendingAction envelope.
	 */
	public static function stage( AgentBundleUpgradePlan $plan, array $args = array() ): array {
		$plan_array       = $plan->to_array();
		$target_artifacts = isset( $args['target_artifacts'] ) && is_array( $args['target_artifacts'] ) ? $args['target_artifacts'] : array();
		$approved         = isset( $args['approved_artifacts'] ) && is_array( $args['approved_artifacts'] ) ? array_values( array_map( 'strval', $args['approved_artifacts'] ) ) : array();

		return PendingActionHelper::stage(
			array(
				'kind'         => self::KIND,
				'summary'      => (string) ( $args['summary'] ?? 'Review bundle upgrade artifacts.' ),
				'apply_input'  => array(
					'bundle'             => isset( $args['bundle'] ) && is_array( $args['bundle'] ) ? $args['bundle'] : array(),
					'approved_artifacts' => $approved,
					'target_artifacts'   => $target_artifacts,
					'plan'               => $plan_array,
				),
				'preview_data' => array(
					'bundle'         => isset( $args['bundle'] ) && is_array( $args['bundle'] ) ? $args['bundle'] : array(),
					'counts'         => $plan_array['counts'],
					'auto_apply'     => $plan_array['auto_apply'],
					'needs_approval' => $plan_array['needs_approval'],
					'warnings'       => $plan_array['warnings'],
					'no_op'          => $plan_array['no_op'],
				),
				'agent_id'     => isset( $args['agent_id'] ) ? (int) $args['agent_id'] : 0,
				'user_id'      => isset( $args['user_id'] ) ? (int) $args['user_id'] : 0,
				'context'      => isset( $args['context'] ) && is_array( $args['context'] ) ? $args['context'] : array(),
			)
		);
	}

	/**
	 * Apply only explicitly-approved artifact changes.
	 *
	 * Consumers provide the actual storage writer through the
	 * `datamachine_bundle_upgrade_apply_artifact` filter.
	 *
	 * @param array<string,mixed> $apply_input Stored PendingAction apply input.
	 * @return array<string,mixed>
	 */
	public static function apply( array $apply_input ): array {
		$approved = isset( $apply_input['approved_artifacts'] ) && is_array( $apply_input['approved_artifacts'] )
			? array_values( array_map( 'strval', $apply_input['approved_artifacts'] ) )
			: array();
		$approved = array_fill_keys( $approved, true );
		$targets  = isset( $apply_input['target_artifacts'] ) && is_array( $apply_input['target_artifacts'] ) ? $apply_input['target_artifacts'] : array();
		$applied  = array();
		$skipped  = array();
		$failed   = array();

		foreach ( $targets as $artifact ) {
			if ( ! is_array( $artifact ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $artifact['artifact_type'] ?? '' ) ) . ':' . (string) ( $artifact['artifact_id'] ?? '' );
			if ( ! isset( $approved[ $key ] ) ) {
				$skipped[] = array(
					'artifact_key' => $key,
					'reason'       => 'not_approved',
				);
				continue;
			}

			/**
			 * Apply one approved bundle upgrade artifact.
			 *
			 * @param mixed $result Null until a consumer applies the artifact.
			 * @param array $artifact Target artifact payload.
			 * @param array $apply_input Full PendingAction input.
			 */
			$result = apply_filters( 'datamachine_bundle_upgrade_apply_artifact', null, $artifact, $apply_input );
			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'artifact_key' => $key,
					'error'        => $result->get_error_message(),
				);
				continue;
			}
			if ( null === $result ) {
				$failed[] = array(
					'artifact_key' => $key,
					'error'        => 'No bundle artifact apply handler registered.',
				);
				continue;
			}

			$applied[] = array(
				'artifact_key' => $key,
				'result'       => $result,
			);
		}

		return array(
			'success' => empty( $failed ),
			'applied' => $applied,
			'skipped' => $skipped,
			'failed'  => $failed,
		);
	}
}
