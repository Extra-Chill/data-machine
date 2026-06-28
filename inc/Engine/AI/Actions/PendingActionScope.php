<?php
/**
 * Pending-action caller scoping helpers.
 *
 * @package DataMachine\Engine\AI\Actions
 */

namespace DataMachine\Engine\AI\Actions;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Workspace\WordPressWorkspaceScope;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the default pending-action visibility boundary for non-operator callers.
 */
final class PendingActionScope {

	/**
	 * Return scoped filters for list/summary inspection.
	 *
	 * @param array<string,mixed> $filters Normalized query filters.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function filters( array $filters ) {
		if ( self::operator_wide_requested( $filters ) ) {
			return self::operator_wide_allowed() ? self::without_operator_flags( $filters ) : self::operator_wide_error();
		}

		return self::apply_scope_to_filters( self::without_operator_flags( $filters ), self::current_scope( $filters ) );
	}

	/**
	 * Check whether the current caller may inspect or resolve a payload.
	 *
	 * @param array<string,mixed> $payload Stored pending-action payload.
	 * @param array<string,mixed> $input   Caller input/context.
	 */
	public static function can_access_payload( array $payload, array $input = array() ): bool {
		if ( self::operator_wide_requested( $input ) ) {
			return self::operator_wide_allowed();
		}

		$scope = self::current_scope( $input );
		if ( ! self::workspace_matches( $payload, $scope ) ) {
			return false;
		}

		if ( isset( $scope['created_by'] ) && ! self::owner_matches( $payload, $scope ) ) {
			return false;
		}

		if ( ( isset( $scope['agent_id'] ) || isset( $scope['agent'] ) ) && ! self::agent_matches( $payload, $scope ) ) {
			return false;
		}

		foreach ( $scope['context'] ?? array() as $key => $value ) {
			$context = isset( $payload['context'] ) && is_array( $payload['context'] ) ? $payload['context'] : array();
			if ( ! array_key_exists( $key, $context ) || (string) $context[ $key ] !== (string) $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Convert a canonical Agents API action array into Data Machine's payload shape.
	 *
	 * @param array<string,mixed> $action Canonical pending-action array.
	 * @return array<string,mixed>
	 */
	public static function action_array_to_payload( array $action ): array {
		$metadata    = isset( $action['metadata'] ) && is_array( $action['metadata'] ) ? $action['metadata'] : array();
		$datamachine = isset( $metadata['datamachine'] ) && is_array( $metadata['datamachine'] ) ? $metadata['datamachine'] : array();

		return array(
			'action_id'   => $action['action_id'] ?? '',
			'kind'        => $action['kind'] ?? '',
			'summary'     => $action['summary'] ?? '',
			'preview'     => $action['preview'] ?? array(),
			'apply_input' => $action['apply_input'] ?? array(),
			'workspace'   => $action['workspace'] ?? null,
			'agent'       => $action['agent'] ?? null,
			'creator'     => $action['creator'] ?? null,
			'agent_id'    => $datamachine['agent_id'] ?? null,
			'created_by'  => $datamachine['created_by'] ?? null,
			'context'     => isset( $datamachine['context'] ) && is_array( $datamachine['context'] ) ? $datamachine['context'] : array(),
			'metadata'    => $metadata,
		);
	}

	/**
	 * Return an explicit operator-wide denial error.
	 */
	public static function operator_wide_error(): \WP_Error {
		return new \WP_Error(
			'datamachine_pending_action_operator_wide_forbidden',
			'operator_wide pending-action access requires manage-level Data Machine permissions.'
		);
	}

	/**
	 * Build the current default visibility scope.
	 *
	 * @param array<string,mixed> $input Caller input/context.
	 * @return array<string,mixed>
	 */
	private static function current_scope( array $input ): array {
		$workspace = WordPressWorkspaceScope::current();
		$scope     = array(
			'workspace_type' => $workspace->workspace_type,
			'workspace_id'   => $workspace->workspace_id,
			'context'        => self::session_context_from_input( $input ),
		);

		$acting_user_id = PermissionHelper::acting_user_id();
		if ( $acting_user_id > 0 ) {
			$scope['created_by'] = $acting_user_id;
			$scope['creator']    = 'user:' . $acting_user_id;
		}

		$acting_agent_id = PermissionHelper::get_acting_agent_id();
		if ( null !== $acting_agent_id && $acting_agent_id > 0 ) {
			$scope['agent_id'] = $acting_agent_id;
			$scope['agent']    = 'agent:' . $acting_agent_id;
		} else {
			$principal = PermissionHelper::get_execution_principal();
			if ( null !== $principal && '' !== trim( (string) $principal->effective_agent_id ) ) {
				$scope['agent'] = (string) $principal->effective_agent_id;
			}
		}

		/**
		 * Filter the default pending-action caller scope.
		 *
		 * @param array<string,mixed> $scope Current scope.
		 * @param array<string,mixed> $input Caller input/context.
		 */
		$scope = apply_filters( 'datamachine_pending_action_current_scope', $scope, $input );

		return is_array( $scope ) ? $scope : array();
	}

	/**
	 * Apply caller scope as mandatory filters.
	 *
	 * @param array<string,mixed> $filters Query filters.
	 * @param array<string,mixed> $scope   Caller scope.
	 * @return array<string,mixed>
	 */
	private static function apply_scope_to_filters( array $filters, array $scope ): array {
		foreach ( array( 'workspace_type', 'workspace_id' ) as $key ) {
			if ( isset( $scope[ $key ] ) && '' !== $scope[ $key ] && null !== $scope[ $key ] ) {
				$filters[ $key ] = $scope[ $key ];
			}
		}

		if ( ! empty( $scope['created_by'] ) ) {
			unset( $filters['created_by'], $filters['creator'] );
			$filters['owner_user_id'] = (int) $scope['created_by'];
		}

		if ( ! empty( $scope['agent_id'] ) || ! empty( $scope['agent'] ) ) {
			unset( $filters['agent_id'], $filters['agent'] );
			$filters['agent_scope'] = array(
				'agent_id' => $scope['agent_id'] ?? null,
				'agent'    => $scope['agent'] ?? null,
			);
		}

		if ( ! empty( $scope['context'] ) && is_array( $scope['context'] ) ) {
			$filters['context'] = array_merge(
				isset( $filters['context'] ) && is_array( $filters['context'] ) ? $filters['context'] : array(),
				$scope['context']
			);
		}

		return $filters;
	}

	/**
	 * Extract supported session context from caller input.
	 *
	 * @param array<string,mixed> $input Caller input/context.
	 * @return array<string,string>
	 */
	private static function session_context_from_input( array $input ): array {
		$context = isset( $input['context'] ) && is_array( $input['context'] ) ? $input['context'] : array();
		foreach ( array( 'session_id', 'transcript_session_id' ) as $key ) {
			if ( ! empty( $context[ $key ] ) ) {
				return array( $key => (string) $context[ $key ] );
			}
			if ( ! empty( $input[ $key ] ) ) {
				return array( $key => (string) $input[ $key ] );
			}
		}

		$principal = PermissionHelper::get_execution_principal();
		if ( null !== $principal ) {
			foreach ( array( 'session_id', 'transcript_session_id' ) as $key ) {
				if ( ! empty( $principal->request_metadata[ $key ] ) ) {
					return array( $key => (string) $principal->request_metadata[ $key ] );
				}
			}
		}

		return array();
	}

	/**
	 * Check whether explicit operator-wide access was requested.
	 *
	 * @param array<string,mixed> $input Caller input/context.
	 */
	private static function operator_wide_requested( array $input ): bool {
		foreach ( array( 'operator_wide', 'operator-wide', 'all' ) as $key ) {
			if ( ! empty( $input[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Remove internal operator flags before passing filters to the store.
	 *
	 * @param array<string,mixed> $filters Query filters.
	 * @return array<string,mixed>
	 */
	private static function without_operator_flags( array $filters ): array {
		unset( $filters['operator_wide'], $filters['operator-wide'], $filters['all'] );
		return $filters;
	}

	/**
	 * Whether the current caller may use the operator-wide view.
	 */
	private static function operator_wide_allowed(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Compare payload workspace with scope workspace.
	 *
	 * @param array<string,mixed> $payload Stored pending-action payload.
	 * @param array<string,mixed> $scope   Caller scope.
	 */
	private static function workspace_matches( array $payload, array $scope ): bool {
		if ( empty( $scope['workspace_type'] ) || empty( $scope['workspace_id'] ) ) {
			return true;
		}

		$workspace = isset( $payload['workspace'] ) && is_array( $payload['workspace'] ) ? $payload['workspace'] : array();
		return (string) ( $workspace['workspace_type'] ?? '' ) === (string) $scope['workspace_type']
			&& (string) ( $workspace['workspace_id'] ?? '' ) === (string) $scope['workspace_id'];
	}

	/**
	 * Return payload creator identifier.
	 *
	 * @param array<string,mixed> $payload Stored pending-action payload.
	 */
	private static function payload_creator( array $payload ): string {
		if ( ! empty( $payload['creator'] ) ) {
			return (string) $payload['creator'];
		}

		return ! empty( $payload['created_by'] ) ? 'user:' . (int) $payload['created_by'] : '';
	}

	/**
	 * Check owner scope against numeric and canonical owner fields.
	 *
	 * @param array<string,mixed> $payload Stored pending-action payload.
	 * @param array<string,mixed> $scope   Caller scope.
	 */
	private static function owner_matches( array $payload, array $scope ): bool {
		$created_by = isset( $scope['created_by'] ) ? (int) $scope['created_by'] : 0;
		$creator    = isset( $scope['creator'] ) ? (string) $scope['creator'] : ( $created_by > 0 ? 'user:' . $created_by : '' );

		return ( $created_by > 0 && (int) ( $payload['created_by'] ?? 0 ) === $created_by )
			|| ( '' !== $creator && self::payload_creator( $payload ) === $creator );
	}

	/**
	 * Return payload agent identifier.
	 *
	 * @param array<string,mixed> $payload Stored pending-action payload.
	 */
	private static function payload_agent( array $payload ): string {
		if ( ! empty( $payload['agent'] ) ) {
			return (string) $payload['agent'];
		}

		return ! empty( $payload['agent_id'] ) ? 'agent:' . (int) $payload['agent_id'] : '';
	}

	/**
	 * Check agent scope against numeric and canonical agent fields.
	 *
	 * @param array<string,mixed> $payload Stored pending-action payload.
	 * @param array<string,mixed> $scope   Caller scope.
	 */
	private static function agent_matches( array $payload, array $scope ): bool {
		$agent_id = isset( $scope['agent_id'] ) ? (int) $scope['agent_id'] : 0;
		$agent    = isset( $scope['agent'] ) ? (string) $scope['agent'] : ( $agent_id > 0 ? 'agent:' . $agent_id : '' );

		return ( $agent_id > 0 && (int) ( $payload['agent_id'] ?? 0 ) === $agent_id )
			|| ( '' !== $agent && self::payload_agent( $payload ) === $agent );
	}
}
