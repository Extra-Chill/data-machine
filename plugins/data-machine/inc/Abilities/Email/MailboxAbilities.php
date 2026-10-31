<?php
/** Multiple principal-owned inboxes using the existing named-account store. */

namespace DataMachine\Abilities\Email;

use DataMachine\Abilities\AbilityRegistration;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Steps\Fetch\Handlers\Email\EmailAuth;

defined( 'ABSPATH' ) || exit;

final class MailboxAbilities {
	public function __construct() {
		AbilityRegistration::on_abilities_api_init( function (): void {
			$credentials = array();
			foreach ( array( 'imap_host', 'imap_user', 'imap_password', 'imap_encryption', 'smtp_host', 'smtp_user', 'smtp_password', 'smtp_encryption', 'display_name', 'sent_folder' ) as $field ) {
				$credentials[ $field ] = array( 'type' => 'string' );
			}
			foreach ( array( 'imap_port', 'smtp_port' ) as $field ) {
				$credentials[ $field ] = array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 65535,
				);
			}
			$definitions = array(
				'email-mailboxes'       => array( 'List connected inboxes', 'List the inboxes the current principal can access, with non-secret connection metadata.', 'listMailboxes', array(), array() ),
				'email-mailbox-connect' => array(
					'Connect an inbox',
					'Connect or update one named IMAP/SMTP inbox owned by the current user or agent. Other inboxes are preserved.',
					'connectMailbox',
					array( 'name', 'credentials' ),
					array(
						'name'        => array(
							'type'    => 'string',
							'pattern' => '^[a-z0-9][a-z0-9._-]{0,63}$',
						),
						'credentials' => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array( 'imap_host', 'imap_user', 'imap_password' ),
							'properties'           => $credentials,
						),
					),
				),
				'email-mailbox-grant'   => array(
					'Delegate inbox access',
					'Grant an agent explicit operations on one inbox owned by the current user. Credentials stay in the owner account.',
					'grantMailbox',
					array( 'name', 'agent_id', 'operations' ),
					array(
						'name'       => array( 'type' => 'string' ),
						'agent_id'   => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'operations' => array(
							'type'     => 'array',
							'items'    => array(
								'type' => 'string',
								'enum' => EmailAuth::OPERATIONS,
							),
							'minItems' => 1,
						),
					),
				),
			);
			foreach ( $definitions as $name => [ $label, $description, $callback, $required, $properties ] ) {
				wp_register_ability( 'datamachine/' . $name, array(
					'label'               => $label,
					'description'         => $description,
					'category'            => 'datamachine-email',
					'input_schema'        => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => $required,
						'properties'           => $properties,
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( $this, $callback ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				) );
			}
		} );
	}

	public function checkPermission(): bool {
		return PermissionHelper::can( 'use_tools' ) || PermissionHelper::can_manage();
	}

	private function owner(): array|\WP_Error {
		if ( ! $this->checkPermission() ) {
			return new \WP_Error( 'email_mailbox_forbidden', 'Mailbox access is not authorized.', array( 'status' => 403 ) );
		}
		$agent = (int) PermissionHelper::get_acting_agent_id();
		$user  = PermissionHelper::acting_user_id();
		if ( $agent > 0 ) {
			return array( 'agent', $agent );
		}
		return $user > 0 ? array( 'user', $user ) : new \WP_Error( 'email_principal_required', 'An authenticated user or agent is required. Use --user with WP-CLI.' );
	}

	public function listMailboxes( array $input ): array|\WP_Error {
		unset( $input );
		$owner = $this->owner();
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$data  = get_site_option( 'datamachine_auth_data', array() );
		$names = array_keys( $data['email_imap']['principals'][ $owner[0] . ':' . $owner[1] ]['accounts'] ?? array() );
		if ( 'agent' === $owner[0] ) {
			foreach ( $data['email_imap']['delegations'] ?? array() as $accounts ) {
				foreach ( $accounts as $name => $grants ) {
					if ( isset( $grants[ 'agent:' . $owner[1] ] ) ) {
						$names[] = $name;
					}
				}
			}
		}
		$provider  = new EmailAuth();
		$mailboxes = array();
		foreach ( array_unique( $names ) as $name ) {
			$resolved = $provider->resolve_mailbox( $name, 'read' );
			if ( is_wp_error( $resolved ) ) {
				continue;
			}
			$config      = $resolved['credentials'];
			$mailboxes[] = array(
				'auth_ref'        => $resolved['ref'],
				'owner'           => $resolved['owner'],
				'email'           => $config['imap_user'],
				'display_name'    => $config['display_name'] ?? '',
				'imap_host'       => $config['imap_host'],
				'smtp_configured' => ! empty( $config['smtp_host'] ) && ! empty( $config['smtp_password'] ),
			);
		}
		return array(
			'success'   => true,
			'mailboxes' => $mailboxes,
		);
	}

	public function connectMailbox( array $input ): array|\WP_Error {
		$owner = $this->owner();
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$name   = $input['name'] ?? '';
		$config = $input['credentials'] ?? array();
		if ( ! is_string( $name ) || ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,63}$/', $name ) || 'default' === $name || ! is_array( $config ) ) {
			return new \WP_Error( 'email_mailbox_invalid', 'Use a named mailbox other than default and provide credentials.' );
		}
		$provider = new EmailAuth();
		$existing = $provider->get_named_account( $name, $owner[0], $owner[1] ) ?? array();
		$config   = array_merge( $existing, $config );
		$config  += array(
			'imap_port'       => 993,
			'imap_encryption' => 'ssl',
			'smtp_port'       => 587,
			'smtp_encryption' => 'tls',
		);
		foreach ( array( 'imap_host', 'imap_user', 'imap_password' ) as $field ) {
			if ( ! isset( $config[ $field ] ) || ! is_string( $config[ $field ] ) || '' === $config[ $field ] || strlen( $config[ $field ] ) > 2048 || preg_match( '/[\r\n\x00]/', $config[ $field ] ) ) {
				return new \WP_Error( 'email_credentials_invalid', 'Valid IMAP host, username, and password are required.' );
			}
		}
		if ( ! is_email( $config['imap_user'] ) ) {
			return new \WP_Error( 'email_identity_invalid', 'The IMAP username must be the sender email address.' );
		}
		foreach ( array( 'imap', 'smtp' ) as $protocol ) {
			if ( ! in_array( $config[ $protocol . '_encryption' ], array( 'ssl', 'tls' ), true ) || (int) $config[ $protocol . '_port' ] < 1 || (int) $config[ $protocol . '_port' ] > 65535 ) {
				return new \WP_Error( 'email_tls_required', 'Mailbox connections require TLS or SSL and a valid port.' );
			}
			if ( ! empty( $config[ $protocol . '_host' ] ) && ! preg_match( '/^[a-z0-9.-]+$/i', $config[ $protocol . '_host' ] ) ) {
				return new \WP_Error( 'email_host_invalid', 'Provide a mailbox server hostname without connection flags.' );
			}
		}
		if ( ! empty( $config['smtp_host'] ) ) {
			$config['smtp_user'] = $config['smtp_user'] ?? $config['imap_user'];
			if ( empty( $config['smtp_password'] ) || ! is_string( $config['smtp_password'] ) || ! is_string( $config['smtp_user'] ) || preg_match( '/[\r\n\x00]/', $config['smtp_user'] ) ) {
				return new \WP_Error( 'email_smtp_credentials_required', 'SMTP username and password are required for sending.' );
			}
		}
		if ( ! $provider->save_named_account( $name, $config, $owner[0], $owner[1] ) && $provider->get_named_account( $name, $owner[0], $owner[1] ) !== $config ) {
			return new \WP_Error( 'email_mailbox_save_failed', 'Mailbox name is already owned by another principal, or storage failed.' );
		}
		return array(
			'success'         => true,
			'auth_ref'        => 'email_imap:' . $name,
			'owner'           => array(
				'type' => $owner[0],
				'id'   => $owner[1],
			),
			'live_connection' => 'not_tested',
		);
	}

	public function grantMailbox( array $input ): array|\WP_Error {
		$owner = $this->owner();
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$agent = (int) ( $input['agent_id'] ?? 0 );
		if ( ! class_exists( '\DataMachine\Core\Database\Agents\Agents' ) || ! ( new \DataMachine\Core\Database\Agents\Agents() )->get_agent( $agent ) ) {
			return new \WP_Error( 'email_agent_invalid', 'Target agent does not exist.' );
		}
		$provider = new EmailAuth();
		return $provider->grant_agent( $input['name'] ?? '', $owner[0], $owner[1], $agent, (array) ( $input['operations'] ?? array() ) )
			? array(
				'success'  => true,
				'auth_ref' => 'email_imap:' . $input['name'],
				'agent_id' => $agent,
			)
			: new \WP_Error( 'email_grant_failed', 'Could not grant access to the owned mailbox.' );
	}
}
