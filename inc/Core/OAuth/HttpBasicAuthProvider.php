<?php
/**
 * Generic HTTP Basic authentication provider.
 *
 * @package DataMachine\Core\OAuth
 */

namespace DataMachine\Core\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Stores named HTTP Basic credentials as encrypted Data Machine auth refs.
 *
 * One provider, many accounts: `http_basic:logstash` and `http_basic:matticspace` coexist, so a
 * second service does not evict the first. Accounts are the "which credential" axis and are
 * distinct from principal scoping, which is the "whose credential" axis the base class already
 * provides -- both apply, and a user-scoped config holds its own account set.
 */
final class HttpBasicAuthProvider extends BaseAuthProvider {

	public const PROVIDER_SLUG = 'http_basic';

	/** Per-account fields that must never be stored in the clear. */
	private const ACCOUNT_SECRET_FIELDS = array( 'password' );

	/**
	 * Account names whose stored secret could not be decrypted in the latest
	 * stored_accounts() read, keyed by account name.
	 *
	 * @var array<string, true>
	 */
	private array $undecryptable_accounts = array();

	/**
	 * Decryption error for the accounts marked undecryptable in the latest read.
	 *
	 * @var \WP_Error|null
	 */
	private ?\WP_Error $undecryptable_error = null;

	public function __construct() {
		parent::__construct( self::PROVIDER_SLUG );
	}

	/**
	 * Get configuration fields for CLI/UI credential entry.
	 */
	public function get_config_fields(): array {
		return array(
			'account'   => array(
				'label'       => __( 'Account', 'data-machine' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Local auth ref account name, for example logstash. Configuring a new name adds a credential; reusing a name replaces that one.', 'data-machine' ),
			),
			'username'  => array(
				'label'    => __( 'Username', 'data-machine' ),
				'type'     => 'text',
				'required' => true,
			),
			'password'  => array(
				'label'    => __( 'Password', 'data-machine' ),
				'type'     => 'password',
				'required' => true,
			),
			'proxy_url' => array(
				'label'       => __( 'Proxy URL', 'data-machine' ),
				'type'        => 'url',
				'required'    => false,
				'description' => __( 'Optional per-request proxy URL.', 'data-machine' ),
			),
		);
	}

	/**
	 * Whether at least one usable credential is stored.
	 */
	public function is_authenticated(): bool {
		return array() !== $this->stored_accounts();
	}

	/**
	 * Merge a submitted credential into the account set rather than replacing it.
	 *
	 * The base implementation writes the whole config blob, which for this provider would mean
	 * configuring a second service silently deleted the first. Each account is encrypted here
	 * because the base encrypt pass only walks top-level string fields and would leave passwords
	 * nested inside the account map in the clear.
	 *
	 * @param array $data    Submitted config; an `account` key selects which credential to write.
	 * @param array $context Optional principal context.
	 */
	public function save_config( array $data, array $context = array() ): bool {
		$account = trim( (string) ( $data['account'] ?? '' ) );
		if ( '' === $account ) {
			return parent::save_config( $data, $context );
		}

		$accounts             = $this->stored_accounts( $context, false );
		$accounts[ $account ] = $this->map_account_secrets(
			array(
				'username'  => (string) ( $data['username'] ?? '' ),
				'password'  => (string) ( $data['password'] ?? '' ),
				'proxy_url' => trim( (string) ( $data['proxy_url'] ?? '' ) ),
			),
			true
		);

		return parent::save_config( array( 'accounts' => $accounts ), $context );
	}

	/**
	 * Resolve http_basic:<account> into HttpClient auth/proxy options.
	 */
	public function resolve_auth_ref( string $account, string $handler_slug = '', array $context = array() ): array|\WP_Error {
		unset( $handler_slug );

		$accounts = $this->stored_accounts( $context );

		if ( isset( $this->undecryptable_accounts[ $account ] ) && null !== $this->undecryptable_error ) {
			return new \WP_Error(
				$this->undecryptable_error->get_error_code(),
				sprintf(
					/* translators: 1: auth ref account, 2: decryption failure reason. */
					__( 'The stored HTTP Basic credential for auth ref "%1$s" cannot be read: %2$s No outbound request was attempted.', 'data-machine' ),
					$account,
					$this->undecryptable_error->get_error_message()
				)
			);
		}

		$credential = $accounts[ $account ] ?? null;

		if ( ! is_array( $credential ) || empty( $credential['username'] ) || empty( $credential['password'] ) ) {
			return new \WP_Error(
				'auth_ref_unresolved',
				sprintf(
					/* translators: %s: auth ref account. */
					__( 'No HTTP Basic credential is configured for auth ref "%s".', 'data-machine' ),
					$account
				)
			);
		}

		$resolved = array(
			'auth' => array(
				'type'     => 'basic',
				'username' => (string) $credential['username'],
				'password' => (string) $credential['password'],
			),
		);

		if ( ! empty( $credential['proxy_url'] ) ) {
			$resolved['proxy_url'] = (string) $credential['proxy_url'];
		}

		return $resolved;
	}

	/**
	 * Account names that currently hold a usable credential.
	 *
	 * @param array $context Optional principal context.
	 * @return string[]
	 */
	public function get_account_names( array $context = array() ): array {
		return array_keys( $this->stored_accounts( $context ) );
	}

	/**
	 * Remove one stored credential. Returns false when the account does not exist.
	 *
	 * @param string $account Account name to remove.
	 * @param array  $context Optional principal context.
	 */
	public function delete_account( string $account, array $context = array() ): bool {
		$accounts = $this->stored_accounts( $context, false );
		if ( ! isset( $accounts[ $account ] ) ) {
			return false;
		}

		unset( $accounts[ $account ] );

		return parent::save_config( array( 'accounts' => $accounts ), $context );
	}

	/**
	 * The stored account map, reading the pre-multi-account shape when that is what is there.
	 *
	 * Installs configured before this change hold a single flat credential alongside the account
	 * name it was given. Presenting it as a one-entry map means those installs keep resolving
	 * without a migration step, and the next save rewrites them into the map.
	 *
	 * @param array $context Optional principal context.
	 * @param bool  $decrypt Whether to decrypt secrets; false when reading for a re-save.
	 * @return array<string, array<string, string>>
	 */
	private function stored_accounts( array $context = array(), bool $decrypt = true ): array {
		$config = $this->get_config( $context );
		$stored = $config['accounts'] ?? null;

		if ( ! is_array( $stored ) ) {
			$legacy_account = trim( (string) ( $config['account'] ?? '' ) );
			if ( '' === $legacy_account || empty( $config['username'] ) ) {
				return array();
			}

			// get_config() already decrypted the legacy top-level password.
			$legacy = array(
				'username'  => (string) $config['username'],
				'password'  => (string) ( $config['password'] ?? '' ),
				'proxy_url' => trim( (string) ( $config['proxy_url'] ?? '' ) ),
			);

			if ( $decrypt ) {
				$this->undecryptable_accounts = array();
				$this->undecryptable_error    = null;
				// decrypt_fields() nulls a password it cannot decrypt; the
				// fail-closed sentinel distinguishes that from an empty one.
				if ( array_key_exists( 'password', $config ) && null === $config['password'] ) {
					$this->mark_undecryptable( $legacy_account );
				}
			}

			return array( $legacy_account => $decrypt ? $legacy : $this->map_account_secrets( $legacy, true ) );
		}

		$accounts = array();
		if ( $decrypt ) {
			$this->undecryptable_accounts = array();
			$this->undecryptable_error    = null;
		}
		foreach ( $stored as $name => $credential ) {
			if ( ! is_array( $credential ) ) {
				continue;
			}
			if ( ! $decrypt ) {
				$accounts[ (string) $name ] = $credential;
				continue;
			}
			$raw_secret = $credential['password'] ?? null;
			$mapped     = $this->map_account_secrets( $credential, false );
			if ( is_string( $raw_secret ) && str_starts_with( $raw_secret, BaseAuthProvider::ENCRYPTION_PREFIX ) && array_key_exists( 'password', $mapped ) && null === $mapped['password'] ) {
				$this->mark_undecryptable( (string) $name );
			}
			$accounts[ (string) $name ] = $mapped;
		}

		return $accounts;
	}

	/**
	 * Record an account whose stored envelope failed to decrypt, keeping the
	 * first specific error for surfacing through resolve_auth_ref().
	 */
	private function mark_undecryptable( string $account ): void {
		$this->undecryptable_accounts[ $account ] = true;
		if ( null === $this->undecryptable_error ) {
			$error                     = $this->get_last_decryption_error();
			$this->undecryptable_error = $error ?? new \WP_Error(
				'datamachine_auth_decrypt_failed',
				__( 'A stored credential could not be decrypted and was withheld.', 'data-machine' )
			);
		}
	}

	/**
	 * Map the secret fields of a single account entry through the base encrypt or decrypt pass.
	 *
	 * The base helpers only walk top-level string keys, so an account nested under `accounts`
	 * has to be handed to them one field at a time; doing that in one place keeps the two
	 * directions from drifting apart.
	 *
	 * @param array $credential Account entry.
	 * @param bool  $encrypt    True to encrypt, false to decrypt.
	 * @return array<string, string|null> A secret field is null when its stored
	 *                                    envelope could not be decrypted.
	 */
	private function map_account_secrets( array $credential, bool $encrypt ): array {
		foreach ( self::ACCOUNT_SECRET_FIELDS as $field ) {
			$value = (string) ( $credential[ $field ] ?? '' );
			if ( '' === $value ) {
				continue;
			}

			$mapped = $encrypt
				? $this->encrypt_fields( array( $field => $value ) )
				: $this->decrypt_fields( array( $field => $value ) );
			// array_key_exists, not ?? : a decryption failure must stay null
			// instead of falling back to the stored envelope.
			$credential[ $field ] = array_key_exists( $field, $mapped ) ? $mapped[ $field ] : $value;
		}

		return $credential;
	}
}
