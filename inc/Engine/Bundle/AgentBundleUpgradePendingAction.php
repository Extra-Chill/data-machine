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
		$rebased          = isset( $args['rebased_artifacts'] ) && is_array( $args['rebased_artifacts'] ) ? $args['rebased_artifacts'] : array();

		return PendingActionHelper::stage(
			array(
				'kind'         => self::KIND,
				'summary'      => (string) ( $args['summary'] ?? 'Review bundle upgrade artifacts.' ),
				'apply_input'  => array(
					'bundle'             => isset( $args['bundle'] ) && is_array( $args['bundle'] ) ? $args['bundle'] : array(),
					'agent'              => isset( $args['agent'] ) && is_array( $args['agent'] ) ? $args['agent'] : array(),
					'approved_artifacts' => $approved,
					'target_artifacts'   => $target_artifacts,
					'rebased_artifacts'  => $rebased,
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
		$targets  = isset( $apply_input['target_artifacts'] ) && is_array( $apply_input['target_artifacts'] ) ? $apply_input['target_artifacts'] : array();
		$rebased  = isset( $apply_input['rebased_artifacts'] ) && is_array( $apply_input['rebased_artifacts'] ) ? $apply_input['rebased_artifacts'] : array();
		$agent    = isset( $apply_input['agent'] ) && is_array( $apply_input['agent'] ) ? $apply_input['agent'] : array();

		$adoption_targets = self::rebased_targets( $targets, $rebased );
		self::ensure_package_artifact_types( $adoption_targets );

		$store  = new AgentBundleAdoptionStateStore(
			$agent,
			array(),
			array(),
			$adoption_targets,
			isset( $apply_input['bundle'] ) && is_array( $apply_input['bundle'] ) ? $apply_input['bundle'] : array()
		);
		$result = ( new \WP_Agent_Package_Adoption_Orchestrator( $store ) )->adopt(
			new \WP_Agent_Package_Adoption_Request(
				self::package_from_apply_input( $apply_input, $adoption_targets ),
				array(
					'operation'              => 'upgrade',
					'auto_apply'             => false,
					'approved_artifact_keys' => self::approved_package_artifact_keys( $approved ),
					'context'                => array_merge(
						$apply_input,
						array(
							'agent' => $agent,
						)
					),
				)
			)
		);

		$applied = self::applied_from_adoption_result( $result, self::rebase_by_package_key( $adoption_targets ) );
		$skipped = self::skipped_from_adoption_result( $result );
		$failed  = self::failed_from_adoption_result( $result );

		if ( empty( $applied ) && empty( $failed ) && ! empty( $targets ) ) {
			$failed[] = array(
				'artifact_key' => '',
				'error'        => 'No bundle artifacts were approved for apply; nothing changed.',
			);
		}

		return array(
			'success' => empty( $failed ),
			'applied' => $applied,
			'skipped' => $skipped,
			'failed'  => $failed,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $targets Target artifacts.
	 * @param array<int,array<string,mixed>> $rebased Rebasing results.
	 * @return array<int,array<string,mixed>>
	 */
	private static function rebased_targets( array $targets, array $rebased ): array {
		$rebased_by_key = array();
		foreach ( $rebased as $entry ) {
			if ( ! is_array( $entry ) || ! empty( $entry['requires_approval'] ) ) {
				continue;
			}

			$key = (string) ( $entry['artifact_key'] ?? AgentBundleArtifactExtensions::artifact_key(
				(string) ( $entry['artifact_type'] ?? '' ),
				(string) ( $entry['artifact_id'] ?? '' )
			) );
			if ( '' !== $key ) {
				$rebased_by_key[ $key ] = $entry;
			}
		}

		$prepared = array();
		foreach ( $targets as $artifact ) {
			if ( ! is_array( $artifact ) ) {
				continue;
			}

			$key            = AgentBundleArtifactExtensions::artifact_key( (string) ( $artifact['artifact_type'] ?? '' ), (string) ( $artifact['artifact_id'] ?? '' ) );
			$apply_artifact = $artifact;
			if ( isset( $rebased_by_key[ $key ] ) ) {
				$rebase_entry              = $rebased_by_key[ $key ];
				$apply_artifact['payload'] = $rebase_entry['merged'] ?? $artifact['payload'] ?? null;
				if ( isset( $rebase_entry['merged_hash'] ) ) {
					$apply_artifact['hash'] = (string) $rebase_entry['merged_hash'];
				}
				$apply_artifact['rebase'] = array(
					'policy'      => (string) ( $rebase_entry['policy'] ?? '' ),
					'merged_hash' => isset( $rebase_entry['merged_hash'] ) ? (string) $rebase_entry['merged_hash'] : null,
					'decisions'   => is_array( $rebase_entry['decisions'] ?? null ) ? $rebase_entry['decisions'] : array(),
				);
			}

			$prepared[] = $apply_artifact;
		}

		return $prepared;
	}

	/**
	 * @param array<int,array<string,mixed>> $targets Bundle target artifacts.
	 */
	private static function package_from_apply_input( array $apply_input, array $targets ): \WP_Agent_Package {
		$bundle    = isset( $apply_input['bundle'] ) && is_array( $apply_input['bundle'] ) ? $apply_input['bundle'] : array();
		$agent     = isset( $apply_input['agent'] ) && is_array( $apply_input['agent'] ) ? $apply_input['agent'] : array();
		$artifacts = array();

		foreach ( AgentBundleAdoptionStateStore::package_artifact_rows( $targets ) as $target ) {
			$artifacts[] = array(
				'type'   => (string) $target['artifact_type'],
				'slug'   => (string) $target['artifact_id'],
				'source' => (string) ( $target['source'] ?? '' ),
			);
		}

		$agent_slug = (string) ( $agent['agent_slug'] ?? $bundle['target_slug'] ?? 'bundle-agent' );

		return \WP_Agent_Package::from_array(
			array(
				'slug'      => (string) ( $bundle['bundle_slug'] ?? $agent_slug ),
				'version'   => (string) ( $bundle['bundle_version'] ?? '0.0.0' ),
				'agent'     => array(
					'slug'  => $agent_slug,
					'label' => (string) ( $agent['agent_name'] ?? $agent_slug ),
				),
				'artifacts' => $artifacts,
			)
		);
	}

	/**
	 * @param array<int,string> $approved Bundle artifact keys.
	 * @return array<int,string>
	 */
	private static function approved_package_artifact_keys( array $approved ): array {
		$keys = array();
		foreach ( $approved as $key ) {
			$parts = explode( ':', (string) $key, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}

			$keys[] = AgentBundleUpgradePlanner::package_artifact_type( $parts[0] ) . ':' . $parts[1];
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $targets Bundle target artifacts.
	 */
	private static function ensure_package_artifact_types( array $targets ): void {
		foreach ( AgentBundleAdoptionStateStore::package_artifact_rows( $targets ) as $target ) {
			wp_has_agent_package_artifact_type( (string) $target['artifact_type'] );
		}
	}

	/**
	 * @param array<string,array<string,mixed>> $rebase_by_key Rebase metadata by package artifact key.
	 */
	private static function applied_from_adoption_result( \WP_Agent_Package_Adoption_Result $result, array $rebase_by_key ): array {
		$applied = array();
		foreach ( $result->get_applied_artifacts() as $entry ) {
			$artifact      = self::bundle_entry_from_package_entry( $entry );
			$package_key   = (string) ( $entry['artifact_key'] ?? '' );
			$applied_entry = array(
				'artifact_key' => (string) $artifact['artifact_key'],
				'result'       => $entry['callback_result'] ?? array(),
			);
			if ( isset( $rebase_by_key[ $package_key ] ) ) {
				$applied_entry['rebase'] = $rebase_by_key[ $package_key ];
			}

			$applied[] = $applied_entry;
		}

		return $applied;
	}

	/**
	 * @param array<int,array<string,mixed>> $targets Prepared bundle targets.
	 * @return array<string,array<string,mixed>>
	 */
	private static function rebase_by_package_key( array $targets ): array {
		$indexed = array();
		foreach ( AgentBundleAdoptionStateStore::package_artifact_rows( $targets ) as $target ) {
			if ( is_array( $target['rebase'] ?? null ) ) {
				$indexed[ (string) $target['artifact_type'] . ':' . (string) $target['artifact_id'] ] = $target['rebase'];
			}
		}

		return $indexed;
	}

	private static function skipped_from_adoption_result( \WP_Agent_Package_Adoption_Result $result ): array {
		$skipped = array();
		foreach ( $result->get_skipped_artifacts() as $entry ) {
			$artifact  = self::bundle_entry_from_package_entry( $entry );
			$skipped[] = array(
				'artifact_key' => (string) $artifact['artifact_key'],
				'reason'       => (string) ( $entry['apply_reason'] ?? 'skipped' ),
			);
		}

		return $skipped;
	}

	private static function failed_from_adoption_result( \WP_Agent_Package_Adoption_Result $result ): array {
		$failed = array();
		foreach ( $result->get_failed_artifacts() as $entry ) {
			$artifact = self::bundle_entry_from_package_entry( $entry );
			$failed[] = array(
				'artifact_key' => (string) $artifact['artifact_key'],
				'error'        => (string) ( $entry['apply_error'] ?? $entry['apply_reason'] ?? 'Artifact apply failed.' ),
			);
		}

		return $failed;
	}

	/** @param array<string,mixed> $entry Package entry. */
	private static function bundle_entry_from_package_entry( array $entry ): array {
		$type = AgentBundleUpgradePlanner::bundle_artifact_type( (string) ( $entry['artifact_type'] ?? '' ) );
		$id   = (string) ( $entry['artifact_id'] ?? '' );

		return array_merge(
			$entry,
			array(
				'artifact_key'  => AgentBundleArtifactExtensions::artifact_key( $type, $id ),
				'artifact_type' => $type,
				'artifact_id'   => $id,
			)
		);
	}
}
