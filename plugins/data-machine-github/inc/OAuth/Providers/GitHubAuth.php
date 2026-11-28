<?php
/**
 * GitHub personal access token provider (no OAuth flow).
 *
 * @package DataMachineGitHub
 */

namespace DataMachineGitHub\OAuth\Providers;

use DataMachine\Core\OAuth\BaseAuthProvider;

defined( 'ABSPATH' ) || exit;

final class GitHubAuth extends BaseAuthProvider {

	public function __construct() {
		parent::__construct( 'github' );
	}

	public function get_config_fields(): array {
		return array(
			'access_token' => array(
				'label'       => __( 'Personal Access Token', 'data-machine-github' ),
				'type'        => 'password',
				'required'    => false,
				'description' => __( 'Optional for public repositories. For private repositories, grant read access to repository contents.', 'data-machine-github' ),
			),
		);
	}

	public function is_configured(): bool {
		return '' !== $this->get_token();
	}

	public function is_authenticated(): bool {
		return $this->is_configured();
	}

	/** The PAT lives in provider config, not an OAuth account slot. */
	public function clear_account(): bool {
		return $this->save_config( array() );
	}

	public function get_token( array $context = array() ): string {
		return trim( (string) ( $this->get_config( $context )['access_token'] ?? '' ) );
	}
}
