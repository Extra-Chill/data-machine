<?php
/**
 * Google Drive Shared Settings.
 *
 * Mirrors GoogleSheetsSettings — shared scaffolding for handlers in the
 * GoogleDrive subnamespace. Currently only the fetch handler is wired,
 * so this class intentionally exposes no fields beyond the structural
 * authentication helper. A future publish handler can extend it.
 *
 * @package DataMachineBusiness
 * @subpackage Handlers\GoogleDrive
 * @since 0.3.0
 */

namespace DataMachineBusiness\Handlers\GoogleDrive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoogleDriveSettings {

	/**
	 * Required OAuth scopes for Drive read-only access.
	 *
	 * @return string[]
	 */
	public static function required_scopes(): array {
		return array(
			'https://www.googleapis.com/auth/drive.readonly',
			'https://www.googleapis.com/auth/drive.metadata.readonly',
		);
	}

	/**
	 * Validate that the shared Google credential covers Drive scopes.
	 *
	 * Reuses the unified GoogleAuth provider — see the layer note in
	 * data-machine-business.php for why every Google handler in this
	 * plugin shares one OAuth client and stored credential.
	 *
	 * Returns WP_Error when the user must re-consent to grant Drive
	 * scopes. Never silently returns success in that case.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return true|\WP_Error
	 */
	public static function validate_authentication( int $user_id ) {
		$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
		$auth_provider  = $auth_abilities->getProvider( 'google' );

		if ( ! $auth_provider ) {
			return new \WP_Error(
				'googledrive_auth_unavailable',
				__( 'Google authentication service not available.', 'data-machine-business' )
			);
		}

		if ( ! $auth_abilities->isHandlerAuthenticated( 'google' ) ) {
			return new \WP_Error(
				'googledrive_not_authenticated',
				__( 'Google authentication required. Connect a Google account in Data Machine settings.', 'data-machine-business' )
			);
		}

		foreach ( self::required_scopes() as $scope ) {
			if ( ! $auth_provider->has_scope( $scope ) ) {
				return new \WP_Error(
					'googledrive_scope_missing',
					sprintf(
						/* translators: %s: OAuth scope URL. */
						__( 'The connected Google account is missing the Drive scope (%s). Disconnect and reconnect the Google integration to grant Drive access.', 'data-machine-business' ),
						$scope
					)
				);
			}
		}

		return true;
	}
}
