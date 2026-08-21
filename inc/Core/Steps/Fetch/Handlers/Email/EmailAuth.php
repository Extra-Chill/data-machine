<?php
/**
 * Email IMAP Authentication Provider
 *
 * Stores IMAP credentials (host, port, user, app password) using
 * Data Machine's auth data storage. Not OAuth — just encrypted
 * credential management for IMAP connections.
 *
 * @package DataMachine\Core\Steps\Fetch\Handlers\Email
 */

namespace DataMachine\Core\Steps\Fetch\Handlers\Email;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\OAuth\BaseAuthProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EmailAuth extends BaseAuthProvider {
	public const OPERATIONS = array( 'read', 'search', 'draft', 'send', 'reply', 'organize', 'delete', 'unsubscribe' );

	public function __construct() {
		parent::__construct( 'email_imap' );
	}

	/**
	 * Get configuration fields for IMAP setup.
	 *
	 * @return array Configuration field definitions.
	 */
	public function get_config_fields(): array {
		return array(
			'imap_host'       => array(
				'type'        => 'text',
				'label'       => __( 'IMAP Host', 'data-machine' ),
				'placeholder' => 'imap.gmail.com',
				'description' => __( 'IMAP server hostname.', 'data-machine' ),
				'required'    => true,
			),
			'imap_port'       => array(
				'type'        => 'number',
				'label'       => __( 'IMAP Port', 'data-machine' ),
				'default'     => 993,
				'description' => __( 'IMAP server port. Usually 993 for SSL.', 'data-machine' ),
			),
			'imap_encryption' => array(
				'type'        => 'select',
				'label'       => __( 'Encryption', 'data-machine' ),
				'options'     => array(
					'ssl'  => 'SSL',
					'tls'  => 'TLS',
					'none' => __( 'None', 'data-machine' ),
				),
				'default'     => 'ssl',
				'description' => __( 'Connection encryption method.', 'data-machine' ),
			),
			'imap_user'       => array(
				'type'        => 'text',
				'label'       => __( 'Username', 'data-machine' ),
				'placeholder' => 'your-email@gmail.com',
				'description' => __( 'Your email address (used as IMAP username).', 'data-machine' ),
				'required'    => true,
			),
			'imap_password'   => array(
				'type'        => 'password',
				'label'       => __( 'App Password', 'data-machine' ),
				'description' => __( 'An app-specific password (not your account password). Generate one in your email provider\'s security settings.', 'data-machine' ),
				'required'    => true,
			),
		);
	}

	/**
	 * Check if IMAP credentials are configured.
	 *
	 * @return bool True if authenticated (credentials saved).
	 */
	public function is_authenticated(): bool {
		$config = $this->get_config();
		return ! empty( $config['imap_host'] )
			&& ! empty( $config['imap_user'] )
			&& ! empty( $config['imap_password'] );
	}

	protected function get_encrypted_fields(): array {
		return array_values( array_unique( array_merge( parent::get_encrypted_fields(), array( 'imap_password' ) ) ) );
	}

	public function strip_auth_config_secrets( array $handler_config ): array {
		foreach ( array( 'imap_host', 'imap_port', 'imap_encryption', 'imap_user', 'imap_password' ) as $field ) {
			unset( $handler_config[ $field ] );
		}
		return parent::strip_auth_config_secrets( $handler_config );
	}

	/**
	 * Convert inline IMAP credentials to the install-local default ref.
	 *
	 * @param array  $handler_config Handler config being exported.
	 * @param string $handler_slug Handler slug.
	 * @param array  $context Export context.
	 * @return string|null Auth ref or null when config carries no IMAP credential shape.
	 */
	public function get_auth_ref_for_config( array $handler_config, string $handler_slug = '', array $context = array() ): ?string {
		unset( $handler_slug, $context );

		foreach ( array( 'imap_host', 'imap_user', 'imap_password' ) as $field ) {
			if ( ! empty( $handler_config[ $field ] ) ) {
				return 'email_imap:default';
			}
		}

		return null;
	}

	/**
	 * Resolve the default IMAP auth ref to local credentials.
	 *
	 * @param string $account Auth ref account/id segment.
	 * @param string $handler_slug Handler slug requesting credentials.
	 * @param array  $context Import/runtime context.
	 * @return array|\WP_Error Local IMAP config or failure.
	 */
	public function resolve_auth_ref( string $account, string $handler_slug = '', array $context = array() ): array|\WP_Error {
		unset( $handler_slug );
		if ( empty( $context['runtime'] ) ) {
			$exists = 'default' === $account ? $this->is_authenticated() : ! empty( $this->find_named_accounts( $account ) );
			return $exists
				? array( 'auth_ref' => 'email_imap:' . $account )
				: new \WP_Error( 'auth_ref_unresolved', __( 'Email mailbox ref is not configured on this install.', 'data-machine' ) );
		}
		if ( ! empty( $context['runtime'] ) ) {
			$context['_trusted_execution'] = true;
		}
		$operation = (string) ( $context['operation'] ?? 'read' );
		$resolved  = $this->resolve_mailbox( $account, $operation, $context );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		return array( 'auth_ref' => $resolved['ref'] );
	}

	public function resolve_mailbox_for_principal( string $account, string|array $operations, array $context ): array|\WP_Error {
		$context['_trusted_execution'] = true;
		return $this->resolve_mailbox( $account, $operations, $context );
	}

	/**
	 * Resolve and authorize a mailbox without exposing credentials externally.
	 *
	 * @param string       $account    Account segment or full auth ref.
	 * @param string|array $operations Required operation(s).
	 * @param array        $context    Trusted execution context.
	 * @return array|\WP_Error Resolved internal mailbox envelope.
	 */
	public function resolve_mailbox( string $account, string|array $operations = 'read', array $context = array() ): array|\WP_Error {
		$account = str_starts_with( $account, 'email_imap:' ) ? substr( $account, 11 ) : $account;
		$account = strtolower( trim( $account ) );
		$ref     = 'email_imap:' . $account;
		$trusted  = ! empty( $context['_trusted_execution'] );
		$agent_id = $trusted && isset( $context['agent_id'] ) ? absint( $context['agent_id'] ) : ( class_exists( PermissionHelper::class ) ? absint( PermissionHelper::get_acting_agent_id() ) : 0 );
		$user_id  = $trusted && isset( $context['user_id'] ) ? absint( $context['user_id'] ) : ( class_exists( PermissionHelper::class ) ? absint( PermissionHelper::acting_user_id() ) : 0 );

		if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]*$/', $account ) ) {
			return $this->audit_error( 'auth_ref_invalid', $ref, $operations, $context, $agent_id, $user_id );
		}

		if ( 'default' === $account ) {
			if ( ! $this->can_use_default( $context, $agent_id, $user_id ) ) {
				return $this->audit_error( 'email_mailbox_forbidden', $ref, $operations, $context, $agent_id, $user_id );
			}
			$credentials = $this->get_config();
			if ( ! $this->valid_credentials( $credentials ) ) {
				return $this->audit_error( 'auth_ref_unresolved', $ref, $operations, $context, $agent_id, $user_id );
			}
			$resolved = array(
				'ref'         => $ref,
				'owner'       => array(
					'type' => self::AUTH_SCOPE_SITE,
					'id'   => 0,
				),
				'credentials' => $credentials,
			);
			$this->audit( $resolved, $operations, $context, $agent_id, $user_id, 'allowed' );
			return $resolved;
		}

		$matches = $this->find_named_accounts( $account );
		$allowed = array_values( array_filter( $matches, fn ( array $match ): bool => $this->can_access( $match, $operations, $agent_id, $user_id ) ) );
		if ( 1 !== count( $allowed ) || ! $this->valid_credentials( $allowed[0]['account'] ) ) {
			$code = empty( $matches ) ? 'auth_ref_unresolved' : 'email_mailbox_forbidden';
			if ( count( $allowed ) > 1 ) {
				$code = 'email_mailbox_ambiguous';
			}
			return $this->audit_error( $code, $ref, $operations, $context, $agent_id, $user_id );
		}

		$resolved = array(
			'ref'         => $ref,
			'owner'       => array(
				'type' => $allowed[0]['owner_type'],
				'id'   => $allowed[0]['owner_id'],
			),
			'credentials' => $allowed[0]['account'],
		);
		$this->audit( $resolved, $operations, $context, $agent_id, $user_id, 'allowed' );
		return $resolved;
	}

	private function can_use_default( array $context, int $agent_id, int $user_id ): bool {
		if ( $agent_id > 0 ) {
			return false;
		}
		if ( ! empty( $context['principal_less_system'] ) && ! empty( $context['_trusted_execution'] ) ) {
			return true;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return class_exists( PermissionHelper::class ) && PermissionHelper::can_manage();
		}
		return $this->user_has_management_capability( $user_id );
	}

	public function grant_agent( string $account, string $owner_type, int $owner_id, int $agent_id, array $operations ): bool {
		$account = $this->normalize_named_account_name( $account );
		if ( $agent_id <= 0 || null === $this->get_named_account( $account, $owner_type, $owner_id ) ) {
			return false;
		}
		$acting_agent = class_exists( PermissionHelper::class ) ? absint( PermissionHelper::get_acting_agent_id() ) : 0;
		$acting_user  = class_exists( PermissionHelper::class ) ? absint( PermissionHelper::acting_user_id() ) : 0;
		if ( $acting_agent > 0 && ( self::AUTH_SCOPE_AGENT !== $owner_type || $acting_agent !== $owner_id ) ) {
			return false;
		}
		if ( 0 === $acting_agent && self::AUTH_SCOPE_USER === $owner_type && $acting_user !== $owner_id && class_exists( PermissionHelper::class ) && ! PermissionHelper::can_manage() ) {
			return false;
		}
		if ( 0 === $acting_agent && self::AUTH_SCOPE_SITE === $owner_type && class_exists( PermissionHelper::class ) && ! PermissionHelper::can_manage() ) {
			return false;
		}
		if ( 0 === $acting_agent && self::AUTH_SCOPE_AGENT === $owner_type && ! $this->user_can_manage_agent_owner( $acting_user, $owner_id ) ) {
			return false;
		}
		$operations = array_values( array_unique( array_intersect( self::OPERATIONS, array_map( 'sanitize_key', $operations ) ) ) );
		if ( empty( $operations ) ) {
			return false;
		}
		$data = get_site_option( 'datamachine_auth_data', array() );
		$data['email_imap']['delegations'][ $owner_type . ':' . $owner_id ][ $account ][ 'agent:' . $agent_id ] = array(
			'operations' => $operations,
			'granted_at' => gmdate( 'c' ),
			'granted_by' => $acting_user,
		);
		return update_site_option( 'datamachine_auth_data', $data );
	}

	public function revoke_agent( string $account, string $owner_type, int $owner_id, int $agent_id ): bool {
		$account = $this->normalize_named_account_name( $account );
		if ( '' === $account || $agent_id <= 0 ) {
			return false;
		}
		$acting_agent = class_exists( PermissionHelper::class ) ? absint( PermissionHelper::get_acting_agent_id() ) : 0;
		$acting_user  = class_exists( PermissionHelper::class ) ? absint( PermissionHelper::acting_user_id() ) : 0;
		if ( $acting_agent > 0 && ( self::AUTH_SCOPE_AGENT !== $owner_type || $acting_agent !== $owner_id ) ) {
			return false;
		}
		if ( 0 === $acting_agent && self::AUTH_SCOPE_USER === $owner_type && $acting_user !== $owner_id && class_exists( PermissionHelper::class ) && ! PermissionHelper::can_manage() ) {
			return false;
		}
		if ( 0 === $acting_agent && self::AUTH_SCOPE_SITE === $owner_type && class_exists( PermissionHelper::class ) && ! PermissionHelper::can_manage() ) {
			return false;
		}
		if ( 0 === $acting_agent && self::AUTH_SCOPE_AGENT === $owner_type && ! $this->user_can_manage_agent_owner( $acting_user, $owner_id ) ) {
			return false;
		}
		$data = get_site_option( 'datamachine_auth_data', array() );
		$key  = $owner_type . ':' . $owner_id;
		if ( ! isset( $data['email_imap']['delegations'][ $key ][ $account ][ 'agent:' . $agent_id ] ) ) {
			return true;
		}
		unset( $data['email_imap']['delegations'][ $key ][ $account ][ 'agent:' . $agent_id ] );
		update_site_option( 'datamachine_auth_data', $data );
		$current = get_site_option( 'datamachine_auth_data', array() );
		return ! isset( $current['email_imap']['delegations'][ $key ][ $account ][ 'agent:' . $agent_id ] );
	}

	public function delete_named_account( string $account_name, string $owner_type = self::AUTH_SCOPE_SITE, int $owner_id = 0 ): bool {
		$account_name = $this->normalize_named_account_name( $account_name );
		$scope        = $this->named_account_scope( $owner_type, $owner_id );
		if ( '' === $account_name || false === $scope ) {
			return false;
		}

		$data       = get_site_option( 'datamachine_auth_data', array() );
		$owner_id   = self::AUTH_SCOPE_SITE === $owner_type ? 0 : $owner_id;
		$owner_key  = $owner_type . ':' . $owner_id;
		$has_grants = isset( $data['email_imap']['delegations'][ $owner_key ][ $account_name ] );
		$has_account = null === $scope
			? isset( $data['email_imap']['accounts'][ $account_name ] )
			: isset( $data['email_imap']['principals'][ $scope ]['accounts'][ $account_name ] );
		if ( ! $has_account && ! $has_grants ) {
			return true;
		}

		if ( null === $scope ) {
			unset( $data['email_imap']['accounts'][ $account_name ] );
		} else {
			unset( $data['email_imap']['principals'][ $scope ]['accounts'][ $account_name ] );
		}
		unset( $data['email_imap']['delegations'][ $owner_key ][ $account_name ] );

		return update_site_option( 'datamachine_auth_data', $data );
	}

	private function user_can_manage_agent_owner( int $user_id, int $agent_id ): bool {
		if ( $user_id <= 0 || $agent_id <= 0 ) {
			return false;
		}
		if ( class_exists( PermissionHelper::class ) && PermissionHelper::can( 'manage_agents' ) ) {
			return true;
		}
		if ( ! class_exists( Agents::class ) ) {
			return false;
		}
		$agent = ( new Agents() )->get_agent( $agent_id );
		return is_array( $agent ) && (int) ( $agent['owner_id'] ?? 0 ) === $user_id;
	}

	private function can_access( array $match, string|array $operations, int $agent_id, int $user_id ): bool {
		$operations = (array) $operations;
		if ( $agent_id > 0 && self::AUTH_SCOPE_AGENT === $match['owner_type'] && $agent_id === $match['owner_id'] ) {
			return true;
		}
		if ( 0 === $agent_id && self::AUTH_SCOPE_USER === $match['owner_type'] && $user_id === $match['owner_id'] ) {
			return true;
		}
		if ( 0 === $agent_id && self::AUTH_SCOPE_SITE === $match['owner_type'] && $this->has_management_principal( $user_id ) ) {
			return true;
		}
		if ( $agent_id <= 0 ) {
			return false;
		}
		$data       = get_site_option( 'datamachine_auth_data', array() );
		$owner_key  = $match['owner_type'] . ':' . $match['owner_id'];
		$grant      = $data['email_imap']['delegations'][ $owner_key ][ $match['account_name'] ][ 'agent:' . $agent_id ] ?? null;
		return is_array( $grant ) && empty( array_diff( $operations, (array) ( $grant['operations'] ?? array() ) ) );
	}

	private function has_management_principal( int $user_id ): bool {
		return ( defined( 'WP_CLI' ) && WP_CLI && class_exists( PermissionHelper::class ) && PermissionHelper::can_manage() )
			|| $this->user_has_management_capability( $user_id );
	}

	private function user_has_management_capability( int $user_id ): bool {
		if ( $user_id <= 0 || ! function_exists( 'user_can' ) ) {
			return false;
		}
		foreach ( array( 'manage_options', 'datamachine_manage_agents', 'datamachine_manage_flows', 'datamachine_manage_settings' ) as $capability ) {
			if ( user_can( $user_id, $capability ) ) {
				return true;
			}
		}
		return false;
	}

	private function valid_credentials( array $credentials ): bool {
		return ! empty( $credentials['imap_host'] ) && ! empty( $credentials['imap_user'] ) && ! empty( $credentials['imap_password'] );
	}

	private function audit_error( string $code, string $ref, string|array $operations, array $context, int $agent_id, int $user_id ): \WP_Error {
		$this->audit(
			array(
				'ref'   => $ref,
				'owner' => null,
			),
			$operations,
			$context,
			$agent_id,
			$user_id,
			'denied'
		);
		return new \WP_Error(
			$code,
			sprintf(
				/* translators: %s: mailbox auth reference. */
				__( 'Mailbox ref "%s" could not be resolved or authorized.', 'data-machine' ),
				$ref
			),
			array( 'status' => 403 )
		);
	}

	private function audit( array $mailbox, string|array $operations, array $context, int $agent_id, int $user_id, string $result ): void {
		$acting_principal = $agent_id > 0
			? array(
				'type' => 'agent',
				'id'   => $agent_id,
			)
			: array(
				'type' => 'user',
				'id'   => $user_id,
			);
		$metadata = array(
			'mailbox_ref'     => $mailbox['ref'],
			'owner_principal' => $mailbox['owner'],
			'acting_principal' => $acting_principal,
			'operation'       => array_values( (array) $operations ),
			'result'          => $result,
		);
		foreach ( array( 'flow_id', 'pipeline_id', 'flow_step_id', 'job_id' ) as $key ) {
			if ( isset( $context[ $key ] ) ) {
				$metadata[ $key ] = $context[ $key ];
			}
		}
		do_action( 'datamachine_email_operation_audit', $metadata );
	}

	/**
	 * Get IMAP host.
	 *
	 * @return string IMAP hostname.
	 */
	public function getHost(): string {
		$config = $this->get_config();
		return $config['imap_host'] ?? '';
	}

	/**
	 * Get IMAP port.
	 *
	 * @return int IMAP port number.
	 */
	public function getPort(): int {
		$config = $this->get_config();
		return (int) ( $config['imap_port'] ?? 993 );
	}

	/**
	 * Get IMAP encryption type.
	 *
	 * @return string Encryption type (ssl, tls, none).
	 */
	public function getEncryption(): string {
		$config = $this->get_config();
		return $config['imap_encryption'] ?? 'ssl';
	}

	/**
	 * Get IMAP username.
	 *
	 * @return string IMAP username.
	 */
	public function getUser(): string {
		$config = $this->get_config();
		return $config['imap_user'] ?? '';
	}

	/**
	 * Get IMAP password.
	 *
	 * @return string IMAP app password.
	 */
	public function getPassword(): string {
		$config = $this->get_config();
		return $config['imap_password'] ?? '';
	}

	/**
	 * Get account details for display.
	 *
	 * @return array|null Account display details.
	 */
	public function get_account_details(): ?array {
		if ( ! $this->is_authenticated() ) {
			return null;
		}

		$config = $this->get_config();
		return array(
			'email'      => $config['imap_user'] ?? '',
			'host'       => $config['imap_host'] ?? '',
			'port'       => $config['imap_port'] ?? 993,
			'encryption' => $config['imap_encryption'] ?? 'ssl',
		);
	}
}
