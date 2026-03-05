<?php
/**
 * Workspace Fetch Handler.
 *
 * Produces a structured workspace inventory packet for downstream AI steps.
 *
 * @package DataMachine\Core\Steps\Fetch\Handlers\Workspace
 * @since   0.37.0
 */

namespace DataMachine\Core\Steps\Fetch\Handlers\Workspace;

use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachine\Core\Steps\Workspace\Tools\WorkspaceScopedTools;

defined( 'ABSPATH' ) || exit;

class Workspace extends FetchHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'workspace' );

		self::registerHandler(
			'workspace',
			'fetch',
			self::class,
			'Workspace Audit',
			'Read scoped workspace repository context for downstream AI processing',
			false,
			null,
			WorkspaceSettings::class,
			function ( $tools, $handler_slug, $handler_config ) {
				if ( 'workspace' !== $handler_slug ) {
					return $tools;
				}

				$tools['workspace_fetch_ls'] = array(
					'class'          => WorkspaceScopedTools::class,
					'method'         => 'handle_tool_call',
					'handler'        => 'workspace',
					'operation'      => 'fetch_ls',
					'description'    => 'List workspace directories within this handler\'s configured repo and path scope.',
					'parameters'     => array(
						'path' => array(
							'type'        => 'string',
							'required'    => false,
							'description' => 'Relative path inside allowed workspace roots.',
						),
					),
					'handler_config' => $handler_config,
				);

				$tools['workspace_fetch_read'] = array(
					'class'          => WorkspaceScopedTools::class,
					'method'         => 'handle_tool_call',
					'handler'        => 'workspace',
					'operation'      => 'fetch_read',
					'description'    => 'Read workspace files within this handler\'s configured repo and path scope.',
					'parameters'     => array(
						'path'   => array(
							'type'        => 'string',
							'required'    => true,
							'description' => 'Relative file path inside allowed workspace roots.',
						),
						'offset' => array(
							'type'        => 'integer',
							'required'    => false,
							'description' => 'Optional line offset for partial reads.',
						),
						'limit'  => array(
							'type'        => 'integer',
							'required'    => false,
							'description' => 'Optional maximum lines to return.',
						),
					),
					'handler_config' => $handler_config,
				);

				return $tools;
			}
		);
	}

	/**
	 * Execute workspace fetch.
	 *
	 * @param array            $config  Handler config.
	 * @param ExecutionContext $context Execution context.
	 * @return array
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$repo = trim( (string) ( $config['repo'] ?? '' ) );
		if ( '' === $repo ) {
			$context->log( 'warning', 'Workspace fetch skipped: missing repo in config.' );
			return array();
		}

		$paths_raw = $config['paths'] ?? array();
		$paths     = $this->normalizePaths( $paths_raw );

		if ( empty( $paths ) ) {
			$context->log( 'warning', 'Workspace fetch skipped: no readable paths configured.' );
			return array();
		}

		$max_files = isset( $config['max_files'] ) ? max( 1, (int) $config['max_files'] ) : 200;
		$max_files = min( 2000, $max_files );

		$payload = array(
			'repo'         => $repo,
			'paths'        => $paths,
			'max_files'    => $max_files,
			'since_commit' => trim( (string) ( $config['since_commit'] ?? '' ) ),
			'include_glob' => trim( (string) ( $config['include_glob'] ?? '' ) ),
			'exclude_glob' => trim( (string) ( $config['exclude_glob'] ?? '' ) ),
		);

		return array(
			'title'    => sprintf( 'Workspace audit context: %s', $repo ),
			'content'  => wp_json_encode( $payload, JSON_PRETTY_PRINT ),
			'metadata' => array(
				'source_type'            => 'workspace',
				'item_identifier_to_log' => 'workspace:' . $repo,
				'dedup_key'              => 'workspace:' . $repo,
				'workspace_repo'         => $repo,
				'_engine_data'           => array(
					'workspace_repo'  => $repo,
					'workspace_paths' => $paths,
				),
			),
		);
	}

	/**
	 * Normalize configured path values.
	 *
	 * @param mixed $value Raw config value.
	 * @return array
	 */
	private function normalizePaths( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$paths = array();
		foreach ( $value as $path ) {
			$normalized = trim( (string) $path );
			if ( '' === $normalized ) {
				continue;
			}

			$normalized = ltrim( str_replace( '\\', '/', $normalized ), '/' );
			$normalized = rtrim( $normalized, '/' );
			$paths[]    = $normalized;
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Get display label.
	 *
	 * @return string
	 */
	public static function get_label(): string {
		return 'Workspace Audit';
	}
}
