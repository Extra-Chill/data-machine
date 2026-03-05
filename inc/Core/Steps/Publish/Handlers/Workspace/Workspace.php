<?php
/**
 * Workspace Publish Handler.
 *
 * Provides scoped workspace write + git tool exposure for adjacent AI steps.
 *
 * @package DataMachine\Core\Steps\Publish\Handlers\Workspace
 * @since   0.37.0
 */

namespace DataMachine\Core\Steps\Publish\Handlers\Workspace;

use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\Workspace\Tools\WorkspaceScopedTools;

defined( 'ABSPATH' ) || exit;

class Workspace extends PublishHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'workspace' );

		self::registerHandler(
			'workspace_publish',
			'publish',
			self::class,
			'Workspace Publish',
			'Write scoped files in workspace repositories with optional git commit/push operations',
			false,
			null,
			WorkspaceSettings::class,
			function ( $tools, $handler_slug, $handler_config ) {
				if ( 'workspace_publish' !== $handler_slug ) {
					return $tools;
				}

				$tools['workspace_write'] = array(
					'class'          => WorkspaceScopedTools::class,
					'method'         => 'handle_tool_call',
					'handler'        => 'workspace_publish',
					'operation'      => 'publish_write',
					'description'    => 'Write a file in the configured workspace repository within writable allowlist paths.',
					'parameters'     => array(
						'path'    => array(
							'type'        => 'string',
							'required'    => true,
							'description' => 'Relative file path within writable allowlist.',
						),
						'content' => array(
							'type'        => 'string',
							'required'    => true,
							'description' => 'File content to write.',
						),
					),
					'handler_config' => $handler_config,
				);

				$tools['workspace_edit'] = array(
					'class'          => WorkspaceScopedTools::class,
					'method'         => 'handle_tool_call',
					'handler'        => 'workspace_publish',
					'operation'      => 'publish_edit',
					'description'    => 'Edit a file in the configured workspace repository via scoped find/replace.',
					'parameters'     => array(
						'path'       => array(
							'type'        => 'string',
							'required'    => true,
							'description' => 'Relative file path within writable allowlist.',
						),
						'old_string' => array(
							'type'        => 'string',
							'required'    => true,
							'description' => 'Exact string to replace.',
						),
						'new_string' => array(
							'type'        => 'string',
							'required'    => false,
							'description' => 'Replacement string.',
						),
						'replace_all' => array(
							'type'        => 'boolean',
							'required'    => false,
							'description' => 'Replace all matches if true.',
						),
					),
					'handler_config' => $handler_config,
				);

				$tools['workspace_git_pull'] = array(
					'class'          => WorkspaceScopedTools::class,
					'method'         => 'handle_tool_call',
					'handler'        => 'workspace_publish',
					'operation'      => 'git_pull',
					'description'    => 'Pull latest changes for the configured workspace repository.',
					'parameters'     => array(
						'allow_dirty' => array(
							'type'        => 'boolean',
							'required'    => false,
							'description' => 'Allow pull with dirty working tree.',
						),
					),
					'handler_config' => $handler_config,
				);

				if ( ! empty( $handler_config['commit_enabled'] ) ) {
					$tools['workspace_git_add'] = array(
						'class'          => WorkspaceScopedTools::class,
						'method'         => 'handle_tool_call',
						'handler'        => 'workspace_publish',
						'operation'      => 'git_add',
						'description'    => 'Stage file paths in the configured workspace repository.',
						'parameters'     => array(
							'paths' => array(
								'type'        => 'array',
								'required'    => true,
								'description' => 'Relative paths to stage within writable allowlist.',
							),
						),
						'handler_config' => $handler_config,
					);

					$tools['workspace_git_commit'] = array(
						'class'          => WorkspaceScopedTools::class,
						'method'         => 'handle_tool_call',
						'handler'        => 'workspace_publish',
						'operation'      => 'git_commit',
						'description'    => 'Commit staged changes in the configured workspace repository.',
						'parameters'     => array(
							'message' => array(
								'type'        => 'string',
								'required'    => true,
								'description' => 'Commit message.',
							),
						),
						'handler_config' => $handler_config,
					);
				}

				if ( ! empty( $handler_config['push_enabled'] ) ) {
					$tools['workspace_git_push'] = array(
						'class'          => WorkspaceScopedTools::class,
						'method'         => 'handle_tool_call',
						'handler'        => 'workspace_publish',
						'operation'      => 'git_push',
						'description'    => 'Push commits for the configured workspace repository.',
						'parameters'     => array(
							'remote' => array(
								'type'        => 'string',
								'required'    => false,
								'description' => 'Remote name (default origin).',
							),
							'branch' => array(
								'type'        => 'string',
								'required'    => false,
								'description' => 'Optional branch override when fixed branch is not configured.',
							),
						),
						'handler_config' => $handler_config,
					);
				}

				return $tools;
			}
		);
	}

	/**
	 * Execute publish handler.
	 *
	 * Workspace publish is tool-driven and returns a noop success when called
	 * directly by publish step execution.
	 *
	 * @param array $parameters     Tool parameters.
	 * @param array $handler_config Handler configuration.
	 * @return array
	 */
	protected function executePublish( array $parameters, array $handler_config ): array {
		return $this->successResponse(
			array(
				'noop'    => true,
				'message' => 'Workspace publish operations are executed via scoped handler tools.',
			)
		);
	}

	/**
	 * Get display label.
	 *
	 * @return string
	 */
	public static function get_label(): string {
		return 'Workspace Publish';
	}
}
