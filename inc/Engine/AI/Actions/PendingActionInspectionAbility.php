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
		$result = \AgentsAPI\AI\Approvals\agents_list_pending_actions(
			array( 'filters' => self::normalize_filters( $input ) )
		);
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'actions' => is_array( $result['actions'] ?? null ) ? $result['actions'] : array(),
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
		if ( null === $action ) {
			return array(
				'success'   => false,
				'error'     => 'Pending action not found.',
				'action_id' => $action_id,
			);
		}

		return array(
			'success' => true,
			'action'  => $action,
		);
	}

	/**
	 * Ability callback: summarize actions.
	 */
	public static function summary( array $input ): array {
		$result = \AgentsAPI\AI\Approvals\agents_summary_pending_actions(
			array( 'filters' => self::normalize_filters( $input ) )
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
		foreach ( array( 'status', 'kind', 'created_after', 'created_before' ) as $key ) {
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
