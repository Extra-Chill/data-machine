<?php
/**
 * Resolve Source URL Ability
 *
 * Read-only ability that reports which registered handler claims a source
 * URL. Exposed as datamachine/resolve-source-url. See HandlerUrlResolver for
 * the claim contract.
 *
 * @package DataMachine\Abilities\Handler
 */

namespace DataMachine\Abilities\Handler;

use DataMachine\Abilities\AbilityRegistration;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Steps\HandlerUrlResolver;

defined( 'ABSPATH' ) || exit;

class ResolveSourceUrlAbility {

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		AbilityRegistration::on_abilities_api_init( array( $this, 'register' ) );
		self::$registered = true;
	}

	/**
	 * Register datamachine/resolve-source-url.
	 */
	public function register(): void {
		$claim_schema = array(
			'type'       => 'object',
			'properties' => array(
				'handler_slug'   => array( 'type' => 'string' ),
				'handler_config' => array( 'type' => 'object' ),
				'priority'       => array( 'type' => 'integer' ),
			),
		);

		wp_register_ability(
			'datamachine/resolve-source-url',
			array(
				'label'               => __( 'Resolve Source URL', 'data-machine' ),
				'description'         => __( 'Find the handler that claims a source URL and return its normalized handler config. Parses the URL only; no remote requests are made.', 'data-machine' ),
				'category'            => 'datamachine-pipeline',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'url' ),
					'properties'           => array(
						'url'       => array(
							'type'        => 'string',
							'description' => __( 'Source URL to resolve.', 'data-machine' ),
						),
						'step_type' => array(
							'type'        => 'string',
							'description' => __( 'Optional step type (e.g. fetch). Only handlers visible to this step type are considered.', 'data-machine' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'url'            => array( 'type' => 'string' ),
						'matched'        => array( 'type' => 'boolean' ),
						'handler_slug'   => array( 'anyOf' => array( array( 'type' => 'string' ), array( 'type' => 'null' ) ) ),
						'handler_config' => array( 'anyOf' => array( array( 'type' => 'object' ), array( 'type' => 'null' ) ) ),
						'priority'       => array( 'anyOf' => array( array( 'type' => 'integer' ), array( 'type' => 'null' ) ) ),
						'candidates'     => array(
							'type'  => 'array',
							'items' => $claim_schema,
						),
					),
				),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => array( self::class, 'can_execute' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);
	}

	/**
	 * Permission: any logged-in user, plus trusted CLI and agent contexts.
	 *
	 * @return bool
	 */
	public static function can_execute(): bool {
		if ( is_user_logged_in() ) {
			return true;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		return PermissionHelper::in_agent_context() || PermissionHelper::is_authenticated_context();
	}

	/**
	 * Ability execute callback.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function execute( array $input ) {
		$url       = trim( (string) ( $input['url'] ?? '' ) );
		$step_type = isset( $input['step_type'] ) && '' !== trim( (string) $input['step_type'] ) ? trim( (string) $input['step_type'] ) : null;

		if ( ! HandlerUrlResolver::is_parseable_url( $url ) ) {
			return new \WP_Error(
				'invalid_url',
				__( 'A full URL with a scheme and host is required.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$candidates = HandlerUrlResolver::candidates( $url, $step_type );
		$best       = $candidates[0] ?? null;

		return array(
			'url'            => $url,
			'matched'        => null !== $best,
			'handler_slug'   => $best['handler_slug'] ?? null,
			'handler_config' => $best['handler_config'] ?? null,
			'priority'       => $best['priority'] ?? null,
			'candidates'     => $candidates,
		);
	}
}
