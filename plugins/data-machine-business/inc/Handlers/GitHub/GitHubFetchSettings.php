<?php
/**
 * Settings for the GitHub files fetch handler.
 *
 * @package DataMachineBusiness
 */

namespace DataMachineBusiness\Handlers\GitHub;

use DataMachine\Core\Steps\Settings\SettingsHandler;

defined( 'ABSPATH' ) || exit;

final class GitHubFetchSettings extends SettingsHandler {

	public const DEFAULT_MAX_FILE_SIZE = 1048576;
	public const MAX_FILE_SIZE         = 104857600;

	public static function get_fields(): array {
		return array(
			'repo'          => array(
				'type'        => 'text',
				'label'       => __( 'Repository', 'data-machine-business' ),
				'description' => __( 'GitHub repository in owner/name format.', 'data-machine-business' ),
				'required'    => true,
			),
			'branch'        => array(
				'type'        => 'text',
				'label'       => __( 'Branch', 'data-machine-business' ),
				'description' => __( 'Leave blank to use the repository default branch.', 'data-machine-business' ),
			),
			'file_path'     => array(
				'type'        => 'text',
				'label'       => __( 'File Path', 'data-machine-business' ),
				'description' => __( 'Limit to a file or directory path. Leave blank for the entire repository.', 'data-machine-business' ),
			),
			'file_pattern'  => array(
				'type'        => 'text',
				'label'       => __( 'File Pattern', 'data-machine-business' ),
				'description' => __( 'Optional glob, e.g. *.md. Patterns without a slash match filenames at any depth.', 'data-machine-business' ),
			),
			'max_file_size' => array(
				'type'    => 'number',
				'label'   => __( 'Maximum File Size (bytes)', 'data-machine-business' ),
				'default' => self::DEFAULT_MAX_FILE_SIZE,
				'min'     => 1,
				'max'     => self::MAX_FILE_SIZE,
			),
		);
	}
}
