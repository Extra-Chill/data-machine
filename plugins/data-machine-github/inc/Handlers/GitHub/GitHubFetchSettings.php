<?php
/**
 * Settings for the GitHub files fetch handler.
 *
 * @package DataMachineGitHub
 */

namespace DataMachineGitHub\Handlers\GitHub;

use DataMachine\Core\Steps\Settings\SettingsHandler;

defined( 'ABSPATH' ) || exit;

final class GitHubFetchSettings extends SettingsHandler {

	public const DEFAULT_MAX_FILE_SIZE = 1048576;
	public const MAX_FILE_SIZE         = 104857600;

	public static function get_fields(): array {
		return array(
			'repo'          => array(
				'type'        => 'text',
				'label'       => __( 'Repository', 'data-machine-github' ),
				'description' => __( 'GitHub repository in owner/name format.', 'data-machine-github' ),
				'required'    => true,
			),
			'branch'        => array(
				'type'        => 'text',
				'label'       => __( 'Branch', 'data-machine-github' ),
				'description' => __( 'Leave blank to use the repository default branch.', 'data-machine-github' ),
			),
			'file_path'     => array(
				'type'        => 'text',
				'label'       => __( 'File Path', 'data-machine-github' ),
				'description' => __( 'Limit to a file or directory path. Leave blank for the entire repository.', 'data-machine-github' ),
			),
			'file_pattern'  => array(
				'type'        => 'text',
				'label'       => __( 'File Pattern', 'data-machine-github' ),
				'description' => __( 'Optional glob, e.g. *.md. Patterns without a slash match filenames at any depth.', 'data-machine-github' ),
			),
			'max_file_size' => array(
				'type'    => 'number',
				'label'   => __( 'Maximum File Size (bytes)', 'data-machine-github' ),
				'default' => self::DEFAULT_MAX_FILE_SIZE,
				'min'     => 1,
				'max'     => self::MAX_FILE_SIZE,
			),
		);
	}
}
