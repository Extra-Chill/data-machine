<?php
/**
 * Workspace Fetch Handler Settings.
 *
 * @package DataMachine\Core\Steps\Fetch\Handlers\Workspace
 * @since   0.37.0
 */

namespace DataMachine\Core\Steps\Fetch\Handlers\Workspace;

use DataMachine\Core\Steps\Fetch\Handlers\FetchHandlerSettings;

defined( 'ABSPATH' ) || exit;

class WorkspaceSettings extends FetchHandlerSettings {

	/**
	 * Get settings fields for workspace fetch handler.
	 *
	 * @return array
	 */
	public static function get_fields(): array {
		$fields = array(
			'repo'          => array(
				'type'        => 'text',
				'label'       => __( 'Workspace Repo', 'data-machine' ),
				'description' => __( 'Workspace repository directory name.', 'data-machine' ),
				'required'    => true,
			),
			'paths'         => array(
				'type'        => 'textarea',
				'label'       => __( 'Readable Paths', 'data-machine' ),
				'description' => __( 'Newline-separated relative path allowlist for read operations (e.g. inc/, src/, docs/).', 'data-machine' ),
				'required'    => true,
			),
			'max_files'     => array(
				'type'        => 'number',
				'label'       => __( 'Max Files in Inventory', 'data-machine' ),
				'description' => __( 'Maximum number of files returned in fetch inventory payload.', 'data-machine' ),
				'default'     => 200,
				'min'         => 1,
				'max'         => 2000,
			),
			'since_commit'  => array(
				'type'        => 'text',
				'label'       => __( 'Since Commit (optional)', 'data-machine' ),
				'description' => __( 'Optional commit SHA/ref used by downstream AI for drift analysis.', 'data-machine' ),
				'required'    => false,
			),
			'include_glob'  => array(
				'type'        => 'text',
				'label'       => __( 'Include Glob (optional)', 'data-machine' ),
				'description' => __( 'Optional include glob hint for downstream AI (e.g. **/*.php).', 'data-machine' ),
				'required'    => false,
			),
			'exclude_glob'  => array(
				'type'        => 'text',
				'label'       => __( 'Exclude Glob (optional)', 'data-machine' ),
				'description' => __( 'Optional exclude glob hint for downstream AI (e.g. vendor/**).', 'data-machine' ),
				'required'    => false,
			),
		);

		return array_merge( $fields, parent::get_common_fields() );
	}
}
