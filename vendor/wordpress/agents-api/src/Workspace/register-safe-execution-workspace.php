<?php
/**
 * Safe execution workspace ability registration.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\Core\Workspace;

defined( 'ABSPATH' ) || exit;

add_filter(
	'wp_agent_execution_targets',
	static function ( array $targets ): array {
		if ( WP_Agent_Safe_Execution_Workspace::available() ) {
			$targets[] = WP_Agent_Safe_Execution_Workspace::target_metadata();
		}

		return $targets;
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( ! WP_Agent_Safe_Execution_Workspace::available() ) {
			return;
		}

		$abilities = array(
			'agents/workspace-prepare'    => array(
				'label'       => 'Prepare Safe Execution Workspace',
				'description' => 'Prepare a named host-approved workspace directory for isolated agent code execution.',
				'input'       => agents_workspace_handle_schema(),
				'output'      => agents_workspace_prepare_output_schema(),
				'callback'    => array( WP_Agent_Safe_Execution_Workspace::class, 'prepare' ),
				'annotations' => array(
					'destructive' => true,
					'idempotent'  => false,
				),
			),
			'agents/workspace-list'       => array(
				'label'       => 'List Safe Execution Workspaces',
				'description' => 'List prepared safe execution workspaces under the configured root.',
				'input'       => array( 'type' => 'object' ),
				'output'      => agents_workspace_list_output_schema(),
				'callback'    => array( WP_Agent_Safe_Execution_Workspace::class, 'list_workspaces' ),
				'annotations' => array( 'idempotent' => true ),
			),
			'agents/workspace-read-file'  => array(
				'label'       => 'Read Safe Workspace File',
				'description' => 'Read a file contained inside a prepared safe execution workspace.',
				'input'       => agents_workspace_file_schema( false ),
				'output'      => agents_workspace_read_output_schema(),
				'callback'    => array( WP_Agent_Safe_Execution_Workspace::class, 'read_file' ),
				'annotations' => array( 'idempotent' => true ),
			),
			'agents/workspace-write-file' => array(
				'label'       => 'Write Safe Workspace File',
				'description' => 'Write a file contained inside a prepared safe execution workspace.',
				'input'       => agents_workspace_file_schema( true ),
				'output'      => agents_workspace_write_output_schema(),
				'callback'    => array( WP_Agent_Safe_Execution_Workspace::class, 'write_file' ),
				'annotations' => array(
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		);

		foreach ( $abilities as $name => $ability ) {
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
				continue;
			}

			wp_register_ability(
				$name,
				array(
					'label'               => $ability['label'],
					'description'         => $ability['description'],
					'category'            => 'agents-api',
					'input_schema'        => $ability['input'],
					'output_schema'       => $ability['output'],
					'execute_callback'    => $ability['callback'],
					'permission_callback' => __NAMESPACE__ . '\\agents_workspace_permission',
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => $ability['annotations'],
					),
				)
			);
		}
	}
);

function agents_workspace_permission(): bool {
	$allowed = function_exists( 'current_user_can' ) ? current_user_can( 'manage_options' ) : false;
	return (bool) apply_filters( 'agents_api_safe_workspace_permission', $allowed );
}

/** @return array<string,mixed> */
function agents_workspace_handle_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'handle' ),
		'properties' => array(
			'handle' => array( 'type' => 'string' ),
		),
	);
}

/** @return array<string,mixed> */
function agents_workspace_file_schema( bool $include_content ): array {
	$properties = array(
		'handle' => array( 'type' => 'string' ),
		'path'   => array( 'type' => 'string' ),
	);
	$required   = array( 'handle', 'path' );
	if ( $include_content ) {
		$properties['content'] = array( 'type' => 'string' );
		$required[]            = 'content';
	}

	return array(
		'type'       => 'object',
		'required'   => $required,
		'properties' => $properties,
	);
}

/** @return array<string,mixed> */
function agents_workspace_prepare_output_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'success' => array( 'type' => 'boolean' ),
			'handle'  => array( 'type' => 'string' ),
			'path'    => array( 'type' => 'string' ),
			'target'  => array( 'type' => 'string' ),
		),
	);
}

/** @return array<string,mixed> */
function agents_workspace_list_output_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'success'    => array( 'type' => 'boolean' ),
			'root'       => array( 'type' => 'string' ),
			'workspaces' => array( 'type' => 'array' ),
		),
	);
}

/** @return array<string,mixed> */
function agents_workspace_read_output_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'success' => array( 'type' => 'boolean' ),
			'handle'  => array( 'type' => 'string' ),
			'path'    => array( 'type' => 'string' ),
			'content' => array( 'type' => 'string' ),
			'bytes'   => array( 'type' => 'integer' ),
		),
	);
}

/** @return array<string,mixed> */
function agents_workspace_write_output_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'success' => array( 'type' => 'boolean' ),
			'handle'  => array( 'type' => 'string' ),
			'path'    => array( 'type' => 'string' ),
			'bytes'   => array( 'type' => 'integer' ),
		),
	);
}
