<?php
/**
 * GitHub Fetch Handler Settings
 *
 * Defines settings fields for the GitHub fetch handler UI.
 *
 * @package DataMachine\Core\Steps\Fetch\Handlers\GitHub
 * @since 0.33.0
 */

namespace DataMachine\Core\Steps\Fetch\Handlers\GitHub;

use DataMachine\Core\Steps\Fetch\Handlers\FetchHandlerSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GitHubSettings extends FetchHandlerSettings {

	/**
	 * Get settings fields for GitHub fetch handler.
	 *
	 * @return array
	 */
	public static function get_fields(): array {
		$fields = array(
			'repo'        => array(
				'type'        => 'text',
				'label'       => __( 'Repository', 'data-machine' ),
				'description' => __( 'GitHub repository in owner/repo format (e.g., Extra-Chill/data-machine). Falls back to default repo in settings.', 'data-machine' ),
				'required'    => false,
			),
			'data_source' => array(
				'type'        => 'select',
				'label'       => __( 'Data Source', 'data-machine' ),
				'description' => __( 'What to fetch from the repository.', 'data-machine' ),
				'required'    => true,
				'default'     => 'issues',
				'options'     => array(
					'issues' => __( 'Issues', 'data-machine' ),
					'pulls'  => __( 'Pull Requests', 'data-machine' ),
				),
			),
			'state'       => array(
				'type'        => 'select',
				'label'       => __( 'State Filter', 'data-machine' ),
				'description' => __( 'Filter by issue/PR state.', 'data-machine' ),
				'required'    => false,
				'default'     => 'open',
				'options'     => array(
					'open'   => __( 'Open', 'data-machine' ),
					'closed' => __( 'Closed', 'data-machine' ),
					'all'    => __( 'All', 'data-machine' ),
				),
			),
			'labels'      => array(
				'type'        => 'text',
				'label'       => __( 'Label Filter', 'data-machine' ),
				'description' => __( 'Comma-separated label names to filter by (issues only).', 'data-machine' ),
				'required'    => false,
			),
		);

		return array_merge( $fields, parent::get_common_fields() );
	}
}
