<?php
/**
 * Pending-action list/get/summary abilities and REST surfaces.
 *
 * @package DataMachine\Engine\AI\Actions
 */

namespace DataMachine\Engine\AI\Actions;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes durable pending-action queues to agents and operators.
 */
final class PendingActionInspectionAbility {

	/** @var bool */
	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->register_abilities();
		$this->register_rest_routes();
		self::$registered = true;
	}

	/**
	 * Register WordPress abilities.
	 */
	private function register_abilities(): void {
		$register = function () {
			wp_register_ability(
				'datamachine/list-pending-actions',
				array(
					'label'               => __( 'List Pending Actions', 'data-machine' ),
					'description'         => __( 'List staged pending actions awaiting review or already resolved for audit.', 'data-machine' ),
					'category'            => 'datamachine-actions',
					'input_schema'        => self::query_schema(),
					'output_schema'       => self::list_output_schema(),
					'execute_callback'    => array( self::class, 'list_actions' ),
					'permission_callback' => fn() => PermissionHelper::can( 'chat' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/get-pending-action',
				array(
					'label'               => __( 'Get Pending Action', 'data-machine' ),
					'description'         => __( 'Fetch a staged or resolved pending action by ID.', 'data-machine' ),
					'category'            => 'datamachine-actions',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'action_id' ),
						'properties' => array(
							'action_id' => array( 'type' => 'string' ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( self::class, 'get_action' ),
					'permission_callback' => fn() => PermissionHelper::can( 'chat' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/summarize-pending-actions',
				array(
					'label'               => __( 'Summarize Pending Actions', 'data-machine' ),
					'description'         => __( 'Summarize pending actions by status, kind, agent, and context.', 'data-machine' ),
					'category'            => 'datamachine-actions',
					'input_schema'        => self::query_schema(),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( self::class, 'summary' ),
					'permission_callback' => fn() => PermissionHelper::can( 'chat' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register );
		}
	}

	/**
	 * Register REST routes for operators that do not call abilities directly.
	 */
	private function register_rest_routes(): void {
		add_action(
			'rest_api_init',
			function () {
				register_rest_route(
					'datamachine/v1',
					'/actions',
					array(
						'methods'             => 'GET',
						'callback'            => static fn( \WP_REST_Request $request ) => new \WP_REST_Response( self::list_actions( self::request_to_filters( $request ) ), 200 ),
						'permission_callback' => fn() => PermissionHelper::can( 'chat' ),
					)
				);

				register_rest_route(
					'datamachine/v1',
					'/actions/summary',
					array(
						'methods'             => 'GET',
						'callback'            => static fn( \WP_REST_Request $request ) => new \WP_REST_Response( self::summary( self::request_to_filters( $request ) ), 200 ),
						'permission_callback' => fn() => PermissionHelper::can( 'chat' ),
					)
				);

				register_rest_route(
					'datamachine/v1',
					'/actions/(?P<action_id>[a-zA-Z0-9_\-]+)',
					array(
						'methods'             => 'GET',
						'callback'            => array( self::class, 'handle_get_rest' ),
						'permission_callback' => fn() => PermissionHelper::can( 'chat' ),
					)
				);
			}
		);
	}

	/**
	 * Ability callback: list actions.
	 */
	public static function list_actions( array $input ): array {
		$filters = PendingActionScope::filters( self::normalize_filters( $input ) );
		if ( is_wp_error( $filters ) ) {
			return array(
				'success' => false,
				'error'   => $filters->get_error_message(),
			);
		}

		$result = \AgentsAPI\AI\Approvals\agents_list_pending_actions(
			array( 'filters' => $filters )
		);
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'actions' => self::normalize_action_rows( is_array( $result['actions'] ?? null ) ? $result['actions'] : array() ),
		);
	}

	/**
	 * Ability callback: get one action.
	 */
	public static function get_action( array $input ): array {
		$action_id = isset( $input['action_id'] ) ? sanitize_text_field( (string) $input['action_id'] ) : '';
		if ( '' === $action_id ) {
			return array(
				'success' => false,
				'error'   => 'action_id is required.',
			);
		}

		$result = \AgentsAPI\AI\Approvals\agents_get_pending_action(
			array(
				'action_id'        => $action_id,
				'include_resolved' => true,
			)
		);
		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'error'     => $result->get_error_message(),
				'action_id' => $action_id,
			);
		}

		$action = is_array( $result['action'] ?? null ) ? $result['action'] : null;
		if ( null === $action || ! PendingActionScope::can_access_payload( PendingActionScope::action_array_to_payload( $action ), $input ) ) {
			return array(
				'success'   => false,
				'error'     => 'Pending action not found.',
				'action_id' => $action_id,
			);
		}

		return array(
			'success' => true,
			'action'  => self::normalize_action_row( $action ),
		);
	}

	/**
	 * Normalize action rows for frontend/CLI inspection.
	 *
	 * @param array<int,array<string,mixed>> $actions Canonical action rows.
	 * @return array<int,array<string,mixed>> Rows with stable flattened Data Machine fields.
	 */
	public static function normalize_action_rows( array $actions ): array {
		return array_map( array( self::class, 'normalize_action_row' ), array_values( array_filter( $actions, 'is_array' ) ) );
	}

	/**
	 * Normalize one canonical pending-action row for frontend/CLI inspection.
	 *
	 * Agents API rows are intentionally generic. Data Machine consumers also need
	 * stable scalar fields for table output, filtering evidence, and review UIs.
	 *
	 * @param array<string,mixed> $action Canonical action row.
	 * @return array<string,mixed> Normalized action row.
	 */
	public static function normalize_action_row( array $action ): array {
		$metadata    = isset( $action['metadata'] ) && is_array( $action['metadata'] ) ? $action['metadata'] : array();
		$datamachine = isset( $metadata['datamachine'] ) && is_array( $metadata['datamachine'] ) ? $metadata['datamachine'] : array();
		$context     = isset( $datamachine['context'] ) && is_array( $datamachine['context'] ) ? $datamachine['context'] : array();
		$workspace   = isset( $action['workspace'] ) && is_array( $action['workspace'] ) ? $action['workspace'] : array();

		$action['preview_data']      = $action['preview'] ?? $action['preview_data'] ?? array();
		$action['agent_id']          = isset( $datamachine['agent_id'] ) ? (int) $datamachine['agent_id'] : self::id_from_canonical_ref( $action['agent'] ?? null, 'agent' );
		$action['created_by']        = isset( $datamachine['created_by'] ) ? (int) $datamachine['created_by'] : self::id_from_canonical_ref( $action['creator'] ?? null, 'user' );
		$action['context']           = $context;
		$action['workspace_type']    = isset( $workspace['workspace_type'] ) ? (string) $workspace['workspace_type'] : null;
		$action['workspace_id']      = isset( $workspace['workspace_id'] ) ? (string) $workspace['workspace_id'] : null;
		$action['created_at_iso']    = isset( $action['created_at'] ) ? (string) $action['created_at'] : null;
		$action['expires_at_iso']    = isset( $action['expires_at'] ) ? $action['expires_at'] : null;
		$action['resolved_at_iso']   = isset( $action['resolved_at'] ) ? $action['resolved_at'] : null;
		$action['audit_context']     = isset( $datamachine['audit_context'] ) && is_array( $datamachine['audit_context'] ) ? $datamachine['audit_context'] : array();
		$action['principal_context'] = isset( $action['audit_context']['principal_context'] ) && is_array( $action['audit_context']['principal_context'] ) ? $action['audit_context']['principal_context'] : array();
		$action['resolve_with']      = isset( $datamachine['resolve_with'] ) ? (string) $datamachine['resolve_with'] : 'resolve_pending_action';
		$action['resolve_params']    = array(
			'action_id' => (string) ( $action['action_id'] ?? '' ),
			'decision'  => '<accepted|rejected>',
		);

		return $action;
	}

	/**
	 * Extract numeric IDs from canonical refs such as agent:7 or user:12.
	 *
	 * @param mixed  $value  Canonical ref.
	 * @param string $prefix Expected prefix.
	 * @return int Positive ID or 0.
	 */
	private static function id_from_canonical_ref( $value, string $prefix ): int {
		if ( ! is_string( $value ) ) {
			return 0;
		}

		$match = array();
		return preg_match( '/^' . preg_quote( $prefix, '/' ) . ':(\d+)$/', $value, $match ) ? (int) $match[1] : 0;
	}

	/**
	 * Ability callback: summarize actions.
	 */
	public static function summary( array $input ): array {
		$filters = PendingActionScope::filters( self::normalize_filters( $input ) );
		if ( is_wp_error( $filters ) ) {
			return array(
				'success' => false,
				'error'   => $filters->get_error_message(),
			);
		}

		$result = \AgentsAPI\AI\Approvals\agents_summary_pending_actions(
			array( 'filters' => $filters )
		);
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'summary' => is_array( $result ) ? $result : array(),
		);
	}

	/**
	 * REST callback for a single action.
	 */
	public static function handle_get_rest( \WP_REST_Request $request ): \WP_REST_Response {
		$result = self::get_action( array( 'action_id' => $request->get_param( 'action_id' ) ) );
		return new \WP_REST_Response( $result, ! empty( $result['success'] ) ? 200 : 404 );
	}

	/**
	 * Convert request params to filters.
	 */
	private static function request_to_filters( \WP_REST_Request $request ): array {
		return self::normalize_filters( $request->get_params() );
	}

	/**
	 * Normalize query filters.
	 */
	public static function normalize_filters( array $input ): array {
		$filters = array();
		foreach ( array( 'status', 'kind', 'created_after', 'created_before', 'workspace_type', 'workspace_id', 'agent', 'creator', 'operator_wide', 'operator-wide', 'all' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== $input[ $key ] ) {
				$filters[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}

		foreach ( array( 'agent_id', 'created_by', 'limit', 'offset' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== $input[ $key ] ) {
				$filters[ $key ] = (int) $input[ $key ];
			}
		}

		foreach ( array( 'context_limit', 'context-limit' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== $input[ $key ] ) {
				$filters['context_limit'] = (int) $input[ $key ];
				break;
			}
		}

		foreach ( array( 'include_context_details', 'include-context-details' ) as $key ) {
			if ( ! empty( $input[ $key ] ) ) {
				$filters['include_context_details'] = true;
				break;
			}
		}

		if ( isset( $input['context'] ) && is_array( $input['context'] ) ) {
			$filters['context'] = array_map( 'sanitize_text_field', $input['context'] );
		}

		foreach ( array( 'session_id', 'transcript_session_id' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== $input[ $key ] ) {
				$filters['context'][ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}

		return $filters;
	}

	/**
	 * Shared query schema.
	 */
	private static function query_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'status'                  => array( 'type' => 'string' ),
				'kind'                    => array( 'type' => 'string' ),
				'agent_id'                => array( 'type' => 'integer' ),
				'created_by'              => array( 'type' => 'integer' ),
				'context'                 => array( 'type' => 'object' ),
				'workspace_type'          => array( 'type' => 'string' ),
				'workspace_id'            => array( 'type' => 'string' ),
				'agent'                   => array( 'type' => 'string' ),
				'creator'                 => array( 'type' => 'string' ),
				'session_id'              => array( 'type' => 'string' ),
				'transcript_session_id'   => array( 'type' => 'string' ),
				'operator_wide'           => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Explicitly request an operator-wide view. Requires manage-level Data Machine permissions.', 'data-machine' ),
				),
				'created_after'           => array( 'type' => 'string' ),
				'created_before'          => array( 'type' => 'string' ),
				'limit'                   => array(
					'type'    => 'integer',
					'default' => 50,
				),
				'offset'                  => array(
					'type'    => 'integer',
					'default' => 0,
				),
				'context_limit'           => array(
					'type'        => 'integer',
					'default'     => 25,
					'description' => __( 'Maximum context buckets to include in summaries. Use 0 for all buckets.', 'data-machine' ),
				),
				'include_context_details' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Include all context buckets in summaries.', 'data-machine' ),
				),
			),
		);
	}

	/**
	 * List output schema.
	 */
	private static function list_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'actions' => array( 'type' => 'array' ),
			),
		);
	}
}
