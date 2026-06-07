<?php
/**
 * Agent Remote Call Ability
 *
 * Makes authenticated HTTP requests to another Data Machine site using
 * a stored bearer token (from the authorize flow or manual registration).
 *
 * This is the agent-facing surface on top of {@see RemoteAgentClient}.
 * Pipelines, chat tools, and REST consumers all go through this ability
 * instead of instantiating the client directly.
 *
 * @package DataMachine\Abilities\AgentRemoteCall
 * @since 0.71.0
 */

namespace DataMachine\Abilities\AgentRemoteCall;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Auth\RemoteAgentClient;

defined( 'ABSPATH' ) || exit;

class AgentRemoteCallAbility {

	public function __construct() {
		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/agent-remote-call',
				array(
					'label'               => __( 'Call Remote Agent Site', 'data-machine' ),
					'description'         => __( 'Make an authenticated HTTP request to another Data Machine site using a stored bearer token. Used for cross-site agent workflows (publishing, reading wiki, querying abilities, chatting with a peer agent).', 'data-machine' ),
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'remote_site', 'agent_slug', 'method', 'path' ),
						'properties' => array(
							'remote_site' => array(
								'type'        => 'string',
								'description' => __( 'Remote site domain (e.g., "chubes.net"). Scheme optional.', 'data-machine' ),
							),
							'agent_slug'  => array(
								'type'        => 'string',
								'description' => __( 'Agent slug on the remote site. Combined with remote_site to look up the stored bearer token.', 'data-machine' ),
							),
							'method'      => array(
								'type'        => 'string',
								'enum'        => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ),
								'description' => __( 'HTTP method.', 'data-machine' ),
							),
							'path'        => array(
								'type'        => 'string',
								'description' => __( 'Path on the remote site starting with "/" (e.g., "/wp-json/wp/v2/posts"). Full URLs are also accepted if the host matches remote_site.', 'data-machine' ),
							),
							'body'        => array(
								'type'        => array( 'object', 'array', 'string' ),
								'description' => __( 'Request body. Arrays and objects are JSON-encoded with Content-Type: application/json.', 'data-machine' ),
							),
							'query'       => array(
								'type'        => 'object',
								'description' => __( 'Query parameters to append to the URL.', 'data-machine' ),
							),
							'headers'     => array(
								'type'        => 'object',
								'description' => __( 'Additional request headers. The Authorization header is always controlled by the stored token and cannot be overridden.', 'data-machine' ),
							),
							'timeout'     => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'maximum'     => 300,
								'description' => __( 'Request timeout in seconds. Default 30.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'status_code' => array( 'type' => 'integer' ),
							'body'        => array(
								'type'        => array( 'object', 'array', 'string', 'null' ),
								'description' => __( 'Decoded JSON body, or raw string if the response is not JSON.', 'data-machine' ),
							),
							'url'         => array( 'type' => 'string' ),
							'error'       => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Permission callback for ability execution.
	 *
	 * Cross-site calls fan out to other Data Machine instances with the
	 * owner's ceiling on the remote side, so we gate on local admin here.
	 * Agents invoking this via the pipeline run under their owner's
	 * context (see PermissionHelper), so this check still applies.
	 *
	 * @return bool True if the current user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute a remote agent call.
	 *
	 * @param array $input Input parameters.
	 * @return array Result envelope from {@see RemoteAgentClient::request()}.
	 */
	public function execute( array $input ): array {
		$remote_site = (string) ( $input['remote_site'] ?? '' );
		$agent_slug  = (string) ( $input['agent_slug'] ?? '' );
		$method      = (string) ( $input['method'] ?? '' );
		$path        = (string) ( $input['path'] ?? '' );

		$args = array();

		if ( array_key_exists( 'body', $input ) ) {
			$args['body'] = $input['body'];
		}

		if ( ! empty( $input['query'] ) && is_array( $input['query'] ) ) {
			$args['query'] = $input['query'];
		}

		if ( ! empty( $input['headers'] ) && is_array( $input['headers'] ) ) {
			$args['headers'] = $input['headers'];
		}

		if ( isset( $input['timeout'] ) ) {
			$args['timeout'] = (int) $input['timeout'];
		}

		return RemoteAgentClient::request( $remote_site, $agent_slug, $method, $path, $args );
	}
}
