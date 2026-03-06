<?php
/**
 * Workspace Publish Handler Settings.
 *
 * @package DataMachine\Core\Steps\Publish\Handlers\Workspace
 * @since   0.37.0
 */

namespace DataMachine\Core\Steps\Publish\Handlers\Workspace;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;

defined( 'ABSPATH' ) || exit;

class WorkspaceSettings extends PublishHandlerSettings {

	/**
	 * Get settings fields for workspace publish handler.
	 *
	 * @return array
	 */
	public static function get_fields(): array {
		$fields = array(
			'repo'           => array(
				'type'        => 'text',
				'label'       => __( 'Workspace Repo', 'data-machine' ),
				'description' => __( 'Workspace repository directory name for writes/mutations.', 'data-machine' ),
				'required'    => true,
			),
			'writable_paths' => array(
				'type'        => 'textarea',
				'label'       => __( 'Writable Paths', 'data-machine' ),
				'description' => __( 'Newline-separated relative path allowlist for writes (e.g. ec_docs/, docs/).', 'data-machine' ),
				'required'    => true,
			),
			'branch_mode'    => array(
				'type'        => 'select',
				'label'       => __( 'Branch Mode', 'data-machine' ),
				'description' => __( 'Use current branch or enforce a fixed branch for publish git operations.', 'data-machine' ),
				'default'     => 'current',
				'options'     => array(
					'current' => __( 'Current branch', 'data-machine' ),
					'fixed'   => __( 'Fixed branch', 'data-machine' ),
				),
			),
			'fixed_branch'   => array(
				'type'        => 'text',
				'label'       => __( 'Fixed Branch (optional)', 'data-machine' ),
				'description' => __( 'Required when branch mode is fixed. Example: docs/auto-updates', 'data-machine' ),
				'required'    => false,
			),
			'commit_enabled' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Enable Commits', 'data-machine' ),
				'description' => __( 'Allow git add/commit operations in this handler.', 'data-machine' ),
				'default'     => true,
			),
			'push_enabled'   => array(
				'type'        => 'checkbox',
				'label'       => __( 'Enable Push', 'data-machine' ),
				'description' => __( 'Allow git push operations in this handler.', 'data-machine' ),
				'default'     => false,
			),
			'commit_message' => array(
				'type'        => 'text',
				'label'       => __( 'Commit Message Template', 'data-machine' ),
				'description' => __( 'Template used when AI does not provide a commit message.', 'data-machine' ),
				'default'     => 'chore: update workspace outputs',
			),
		);

		return array_merge( $fields, parent::get_common_fields() );
	}
}
