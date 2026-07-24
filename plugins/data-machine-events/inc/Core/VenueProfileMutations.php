<?php
/**
 * Canonical venue profile mutation contract.
 *
 * Lock ordering is strict: callers must hold no SQL transaction before entry.
 * The contract acquires the venue advisory lock first, then opens its transaction.
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

defined( 'ABSPATH' ) || exit;

class VenueProfileMutations {
	private static array $active_mutations    = array();
	private static array $native_term_locks   = array();
	private static bool $internal_term_update = false;
	private static bool $shutdown_registered  = false;

	public const STRATEGY_OVERWRITE  = 'overwrite';
	public const STRATEGY_FILL_EMPTY = 'fill_empty';

	private const TAXONOMY     = 'venue';
	private const LOCK_TIMEOUT = 10;

	/** @var string[] */
	private const EDITABLE_FIELDS = array( 'address', 'city', 'state', 'zip', 'country', 'phone', 'website', 'capacity' );

	/** @var string[] */
	private const ADDRESS_FIELDS = array( 'address', 'city', 'state', 'zip', 'country' );

	/**
	 * Read the bounded member-editable venue profile and canonical revision.
	 *
	 * Authorization belongs to the consumer and must happen before this call.
	 *
	 * @param int $term_id Venue term ID.
	 * @return array|\WP_Error
	 */
	public static function read( int $term_id ): array|\WP_Error {
		$term = get_term( $term_id, self::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return is_wp_error( $term ) ? $term : new \WP_Error( 'venue_not_found', 'Venue not found.', array( 'status' => 404 ) );
		}

		$profile = array(
			'term_id'     => (int) $term->term_id,
			'name'        => (string) $term->name,
			'description' => (string) $term->description,
		);
		foreach ( self::editableMetaFields() as $field => $meta_key ) {
			$profile[ $field ] = (string) get_term_meta( $term_id, $meta_key, true );
		}

		$profile['revision'] = self::revision( $term );
		return $profile;
	}

	/**
	 * Update only member-editable venue profile fields using optimistic concurrency.
	 *
	 * @param int    $term_id           Venue term ID.
	 * @param array  $changes           Bounded profile changes.
	 * @param string $expected_revision Revision returned by read().
	 * @return array|\WP_Error
	 */
	public static function updateProfile( int $term_id, array $changes, string $expected_revision ): array|\WP_Error {
		if ( '' === $expected_revision ) {
			return new \WP_Error( 'venue_revision_required', 'An expected venue revision is required.', array( 'status' => 400 ) );
		}

		return self::mutate( $term_id, $changes, self::editableMetaFields(), self::STRATEGY_OVERWRITE, $expected_revision, true );
	}

	/**
	 * Update canonical fields for an owner-controlled system writer.
	 *
	 * @param int    $term_id Venue term ID.
	 * @param array  $changes Canonical field changes.
	 * @param string $strategy overwrite or fill_empty.
	 * @return array|\WP_Error
	 */
	public static function updateSystem( int $term_id, array $changes, string $strategy = self::STRATEGY_OVERWRITE ): array|\WP_Error {
		return self::mutate( $term_id, $changes, self::canonicalMetaFields(), $strategy, '', false );
	}

	/**
	 * Reject unsupported native venue updates before WordPress can split a term.
	 *
	 * WordPress has no WP_Error-returning pre-update hook. wp_die() is therefore
	 * the only reliable failure channel that prevents the native write.
	 *
	 * @param int    $parent_term_id Parsed parent term ID.
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return int
	 */
	public static function guardNativeTermEdit( int $parent_term_id, int $term_id, string $taxonomy ): int {
		if ( self::$internal_term_update || self::TAXONOMY !== $taxonomy ) {
			return $parent_term_id;
		}
		if ( in_array( self::lockName( $term_id ), self::$native_term_locks, true ) ) {
			self::abortNativeTermEdit( new \WP_Error( 'venue_mutation_reentrant', 'Recursive native mutation of the same venue is not supported.', array( 'status' => 409 ) ) );
		}

		$error = self::preflight( $term_id );
		if ( is_wp_error( $error ) ) {
			self::abortNativeTermEdit( $error );
		}
		return $parent_term_id;
	}

	/**
	 * Acquire canonical serialization immediately before a native term write.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function beginNativeTermEdit( int $term_id, string $taxonomy ): void {
		if ( self::$internal_term_update || self::TAXONOMY !== $taxonomy ) {
			return;
		}

		$lock_key = self::acquireLock( $term_id );
		if ( is_wp_error( $lock_key ) ) {
			self::abortNativeTermEdit( $lock_key );
		}
		self::$native_term_locks[] = $lock_key;
		self::registerShutdownCleanup();
	}

	/**
	 * Release a native WordPress venue edit lock after all venue save hooks.
	 */
	public static function endNativeTermEdit(): void {
		$lock_key = array_pop( self::$native_term_locks );
		if ( is_string( $lock_key ) ) {
			self::releaseLock( $lock_key );
		}
	}

	/**
	 * Whether an owner mutation is currently invoking wp_update_term().
	 *
	 * @return bool
	 */
	public static function isInternalTermUpdate(): bool {
		return self::$internal_term_update;
	}

	/**
	 * Build the multisite-scoped advisory lock name.
	 *
	 * Exposed for integration tests and operational diagnostics.
	 *
	 * @param int $term_id Venue term ID.
	 * @return string
	 */
	public static function lockName( int $term_id ): string {
		global $wpdb;
		$scope = DB_NAME . '|' . $wpdb->prefix . '|' . get_current_blog_id() . '|' . $term_id;
		return 'dme:venue:' . md5( $scope );
	}

	/**
	 * Run one top-level canonical mutation.
	 *
	 * @param int    $term_id           Venue term ID.
	 * @param array  $changes           Requested changes.
	 * @param array  $meta_fields       Allowed meta field map.
	 * @param string $strategy          Mutation strategy.
	 * @param string $expected_revision Optional optimistic revision.
	 * @param bool   $profile_contract  Whether this is the public profile contract.
	 * @return array|\WP_Error
	 */
	private static function mutate( int $term_id, array $changes, array $meta_fields, string $strategy, string $expected_revision, bool $profile_contract ): array|\WP_Error {
		if ( ! in_array( $strategy, array( self::STRATEGY_OVERWRITE, self::STRATEGY_FILL_EMPTY ), true ) ) {
			return new \WP_Error( 'venue_strategy_invalid', 'Unknown venue mutation strategy.', array( 'status' => 400 ) );
		}

		$allowed = array_merge( array( 'name', 'description' ), array_keys( $meta_fields ) );
		$changes = array_intersect_key( $changes, array_flip( $allowed ) );
		if ( empty( $changes ) ) {
			return new \WP_Error( 'venue_no_fields', 'No supported venue fields were provided.', array( 'status' => 400 ) );
		}

		$preflight = self::preflight( $term_id );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}

		$lock_key = self::lockName( $term_id );
		if ( isset( self::$active_mutations[ $lock_key ] ) ) {
			return new \WP_Error( 'venue_mutation_reentrant', 'Recursive mutation of the same venue is not supported.', array( 'status' => 409 ) );
		}
		self::$active_mutations[ $lock_key ] = true;

		$acquired = self::acquireLock( $term_id );
		if ( is_wp_error( $acquired ) ) {
			unset( self::$active_mutations[ $lock_key ] );
			return $acquired;
		}

		$transaction_open       = false;
		$connection_quarantined = false;
		$quarantine_closed      = false;
		try {
			if ( false === static::query( 'START TRANSACTION' ) ) {
				return new \WP_Error( 'venue_transaction_failed', 'Could not start the venue mutation transaction.', array( 'status' => 500 ) );
			}
			$transaction_open = true;
			self::clearCaches( $term_id );

			$term = get_term( $term_id, self::TAXONOMY );
			if ( ! $term || is_wp_error( $term ) ) {
				$error = $term instanceof \WP_Error ? $term : new \WP_Error( 'venue_not_found', 'Venue not found.', array( 'status' => 404 ) );
				return self::rollbackError( $term_id, $error, $transaction_open, $connection_quarantined, $quarantine_closed );
			}

			$current_revision = self::revision( $term );
			if ( '' !== $expected_revision && ! hash_equals( $current_revision, $expected_revision ) ) {
				return self::rollbackError(
					$term_id,
					new \WP_Error(
						'venue_revision_conflict',
						'The venue changed after it was read.',
						array(
							'status'           => 409,
							'current_revision' => $current_revision,
						)
					),
					$transaction_open,
					$connection_quarantined,
					$quarantine_closed
				);
			}

			$normalized = self::normalizeChanges( $changes );
			$updated    = array();
			$term_args  = array();
			foreach ( array( 'name', 'description' ) as $field ) {
				if ( ! array_key_exists( $field, $normalized ) ) {
					continue;
				}
				$existing = (string) $term->{$field};
				if ( self::STRATEGY_FILL_EMPTY === $strategy && '' !== $existing ) {
					continue;
				}
				if ( $existing !== $normalized[ $field ] ) {
					$term_args[ $field ] = $normalized[ $field ];
					$updated[]           = $field;
				}
			}

			if ( ! empty( $term_args ) ) {
				global $wpdb;
				$wpdb->last_error           = '';
				self::$internal_term_update = true;
				$result                     = wp_update_term( $term_id, self::TAXONOMY, $term_args );
				self::$internal_term_update = false;
				if ( is_wp_error( $result ) ) {
					return self::rollbackError( $term_id, $result, $transaction_open, $connection_quarantined, $quarantine_closed );
				}
				if ( '' !== self::databaseLastError() ) {
					$error = new \WP_Error( 'venue_term_update_failed', 'WordPress could not persist the venue term fields.', array( 'status' => 500 ) );
					return self::rollbackError( $term_id, $error, $transaction_open, $connection_quarantined, $quarantine_closed );
				}
			}

			$address_changed = false;
			foreach ( $meta_fields as $field => $meta_key ) {
				if ( ! array_key_exists( $field, $normalized ) ) {
					continue;
				}

				$existing_values = array_values( get_term_meta( $term_id, $meta_key, false ) );
				if ( self::STRATEGY_FILL_EMPTY === $strategy && self::hasNonEmptyValue( $existing_values ) ) {
					continue;
				}
				$already_canonical = 1 === count( $existing_values ) && (string) $existing_values[0] === $normalized[ $field ];
				if ( $already_canonical ) {
					continue;
				}

				$result = update_term_meta( $term_id, $meta_key, $normalized[ $field ] );
				if ( is_wp_error( $result ) || false === $result ) {
					$error = is_wp_error( $result ) ? $result : new \WP_Error( 'venue_meta_update_failed', "Could not update venue field '{$field}'.", array( 'status' => 500 ) );
					return self::rollbackError( $term_id, $error, $transaction_open, $connection_quarantined, $quarantine_closed );
				}
				$updated[] = $field;
				if ( in_array( $field, self::ADDRESS_FIELDS, true ) ) {
					$address_changed = true;
				}
			}

			if ( $address_changed ) {
				$derived = self::replaceDerivedLocation( $term_id );
				if ( is_wp_error( $derived ) ) {
					return self::rollbackError( $term_id, $derived, $transaction_open, $connection_quarantined, $quarantine_closed );
				}
				$updated = array_merge( $updated, $derived );
			} elseif ( in_array( 'coordinates', $updated, true ) ) {
				$derived = self::replaceTimezone( $term_id, (string) get_term_meta( $term_id, '_venue_coordinates', true ) );
				if ( is_wp_error( $derived ) ) {
					return self::rollbackError( $term_id, $derived, $transaction_open, $connection_quarantined, $quarantine_closed );
				}
				$updated = array_merge( $updated, $derived );
			}

			if ( false === static::query( 'COMMIT' ) ) {
				$transaction_open       = false;
				$connection_quarantined = true;
				$quarantine             = self::quarantineConnection( $term_id );
				$quarantine_closed      = $quarantine['closed'];
				return new \WP_Error(
					'venue_commit_uncertain',
					'The database did not confirm the venue commit; the connection was quarantined and callers must read again before retrying.',
					array(
						'status'               => 503,
						'connection_closed'    => $quarantine['closed'],
						'connection_recovered' => $quarantine['recovered'],
					)
				);
			}
			$transaction_open = false;
			self::clearCaches( $term_id );

			$profile = self::read( $term_id );
			if ( is_wp_error( $profile ) ) {
				return $profile;
			}

			$result = array(
				'success'        => true,
				'term_id'        => $term_id,
				'updated_fields' => array_values( array_unique( $updated ) ),
				'revision'       => $profile['revision'],
				'profile'        => $profile,
			);
			do_action( 'data_machine_events_venue_mutated', $result, $profile_contract );
			return $result;
		} catch ( \Throwable $throwable ) {
			$error = new \WP_Error( 'venue_mutation_exception', $throwable->getMessage(), array( 'status' => 500 ) );
			return self::rollbackError( $term_id, $error, $transaction_open, $connection_quarantined, $quarantine_closed );
		} finally {
			self::$internal_term_update = false;
			if ( $transaction_open ) {
				if ( false === static::query( 'ROLLBACK' ) ) {
					$connection_quarantined = true;
					$quarantine             = self::quarantineConnection( $term_id );
					$quarantine_closed      = $quarantine['closed'];
				}
				self::clearCaches( $term_id );
			}
			if ( ! $connection_quarantined ) {
				self::releaseLock( $lock_key );
			}
			if ( ! $connection_quarantined || $quarantine_closed ) {
				unset( self::$active_mutations[ $lock_key ] );
			}
		}
	}

	/**
	 * Validate lock ordering and unsupported term state before acquisition.
	 *
	 * @param int $term_id Venue term ID.
	 * @return true|\WP_Error
	 */
	private static function preflight( int $term_id ): true|\WP_Error {
		if ( isset( self::$active_mutations[ self::lockName( $term_id ) ] ) ) {
			return new \WP_Error( 'venue_mutation_reentrant', 'Recursive mutation of the same venue is not supported.', array( 'status' => 409 ) );
		}
		$in_transaction = self::inTransaction();
		if ( is_wp_error( $in_transaction ) ) {
			return $in_transaction;
		}
		if ( $in_transaction ) {
			return new \WP_Error( 'venue_transaction_unsupported', 'Venue mutations must begin outside an existing SQL transaction.', array( 'status' => 409 ) );
		}
		if ( wp_term_is_shared( $term_id ) ) {
			return new \WP_Error( 'venue_shared_term_unsupported', 'Shared venue terms must be split before canonical mutation.', array( 'status' => 409 ) );
		}
		return true;
	}

	/**
	 * Determine whether the current wpdb session owns an active transaction.
	 *
	 * MySQL accepts SAVEPOINT outside a transaction but cannot subsequently
	 * release it. Inside a transaction both statements succeed. This bounded
	 * probe requires no metadata privileges and leaves caller state unchanged.
	 * Unknown responses fail closed before advisory-lock acquisition.
	 *
	 * @return bool|\WP_Error
	 */
	private static function inTransaction(): bool|\WP_Error {
		global $wpdb;
		$probe            = 'dme_venue_probe_' . substr( md5( uniqid( '', true ) ), 0, 12 );
		$suppress_errors  = $wpdb->suppress_errors();
		$wpdb->last_error = '';
		$created          = static::query( 'SAVEPOINT ' . $probe );
		$released         = false !== $created ? static::query( 'RELEASE SAVEPOINT ' . $probe ) : false;
		$database_error   = strtolower( self::databaseLastError() );
		$wpdb->suppress_errors( $suppress_errors );
		if ( false !== $created && false !== $released ) {
			return true;
		}
		if ( str_contains( $database_error, 'savepoint' ) && str_contains( $database_error, 'does not exist' ) ) {
			return false;
		}
		return new \WP_Error( 'venue_transaction_state_unknown', 'Could not safely determine the database transaction state.', array( 'status' => 503 ) );
	}

	/**
	 * Return the canonical owner field map.
	 *
	 * @return array<string,string>
	 */
	private static function canonicalMetaFields(): array {
		return Venue_Taxonomy::$meta_fields;
	}

	/**
	 * Return the editable subset of the canonical owner field map.
	 *
	 * @return array<string,string>
	 */
	private static function editableMetaFields(): array {
		return array_intersect_key( self::canonicalMetaFields(), array_flip( self::EDITABLE_FIELDS ) );
	}

	/**
	 * Sanitize values owned by this contract.
	 *
	 * @param array $changes Raw changes.
	 * @return array
	 */
	private static function normalizeChanges( array $changes ): array {
		$normalized = array();
		foreach ( $changes as $field => $value ) {
			if ( ! is_scalar( $value ) && null !== $value ) {
				continue;
			}
			$value = (string) $value;
			if ( 'description' === $field ) {
				$normalized[ $field ] = wp_kses_post( $value );
			} elseif ( 'website' === $field ) {
				$normalized[ $field ] = esc_url_raw( $value );
			} else {
				$normalized[ $field ] = sanitize_text_field( $value );
			}
		}
		return $normalized;
	}

	/**
	 * Whether duplicate metadata contains any curated value.
	 *
	 * @param array $values Metadata values.
	 * @return bool
	 */
	private static function hasNonEmptyValue( array $values ): bool {
		foreach ( $values as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Clear stale location data and derive replacements when possible.
	 *
	 * @param int $term_id Venue term ID.
	 * @return array|\WP_Error
	 */
	private static function replaceDerivedLocation( int $term_id ): array|\WP_Error {
		foreach ( array( 'coordinates', 'timezone' ) as $field ) {
			$meta_key = self::canonicalMetaFields()[ $field ];
			if ( metadata_exists( 'term', $term_id, $meta_key ) && ! delete_term_meta( $term_id, $meta_key ) ) {
				return new \WP_Error( 'venue_meta_delete_failed', "Could not invalidate venue field '{$field}'.", array( 'status' => 500 ) );
			}
		}

		$updated     = array( 'coordinates', 'timezone' );
		$coordinates = Venue_Taxonomy::geocode_address( Venue_Taxonomy::get_venue_data( $term_id ) );
		if ( ! $coordinates ) {
			return $updated;
		}

		$result = update_term_meta( $term_id, self::canonicalMetaFields()['coordinates'], sanitize_text_field( $coordinates ) );
		if ( is_wp_error( $result ) || false === $result ) {
			return is_wp_error( $result ) ? $result : new \WP_Error( 'venue_coordinates_update_failed', 'Could not save derived venue coordinates.', array( 'status' => 500 ) );
		}

		$timezone_result = self::replaceTimezone( $term_id, $coordinates );
		return is_wp_error( $timezone_result ) ? $timezone_result : $updated;
	}

	/**
	 * Invalidate timezone and derive it for the supplied coordinates when possible.
	 *
	 * @param int    $term_id    Venue term ID.
	 * @param string $coordinates Coordinates as lat,lng.
	 * @return array|\WP_Error
	 */
	private static function replaceTimezone( int $term_id, string $coordinates ): array|\WP_Error {
		$meta_key = self::canonicalMetaFields()['timezone'];
		if ( metadata_exists( 'term', $term_id, $meta_key ) && ! delete_term_meta( $term_id, $meta_key ) ) {
			return new \WP_Error( 'venue_timezone_delete_failed', 'Could not invalidate venue timezone.', array( 'status' => 500 ) );
		}
		if ( '' === $coordinates || ! GeoNamesService::isConfigured() ) {
			return array( 'timezone' );
		}

		$timezone = GeoNamesService::getTimezoneFromCoordinates( $coordinates );
		if ( ! $timezone ) {
			return array( 'timezone' );
		}
		$result = update_term_meta( $term_id, $meta_key, $timezone );
		if ( is_wp_error( $result ) || false === $result ) {
			return is_wp_error( $result ) ? $result : new \WP_Error( 'venue_timezone_update_failed', 'Could not save the derived venue timezone.', array( 'status' => 500 ) );
		}
		return array( 'timezone' );
	}

	/**
	 * Fingerprint every canonical owner field and every duplicate row.
	 *
	 * @param \WP_Term $term Venue term.
	 * @return string
	 */
	private static function revision( \WP_Term $term ): string {
		$state = array(
			'blog_id'     => get_current_blog_id(),
			'term_id'     => (int) $term->term_id,
			'name'        => (string) $term->name,
			'description' => (string) $term->description,
			'meta'        => array(),
		);
		foreach ( self::canonicalMetaFields() as $field => $meta_key ) {
			$state['meta'][ $field ] = array_values( get_term_meta( $term->term_id, $meta_key, false ) );
		}
		return hash( 'sha256', (string) wp_json_encode( $state ) );
	}

	/**
	 * Roll back a failed mutation and quarantine an uncertain connection.
	 *
	 * @param int       $term_id                Venue term ID.
	 * @param \WP_Error $error                  Original error.
	 * @param bool      $transaction_open       Whether the transaction remains open.
	 * @param bool      $connection_quarantined Whether wpdb has been quarantined.
	 * @param bool      $quarantine_closed      Whether the uncertain session closed.
	 * @return \WP_Error
	 */
	private static function rollbackError( int $term_id, \WP_Error $error, bool &$transaction_open, bool &$connection_quarantined, bool &$quarantine_closed ): \WP_Error {
		if ( ! $transaction_open ) {
			return $error;
		}
		if ( false === static::query( 'ROLLBACK' ) ) {
			$transaction_open       = false;
			$connection_quarantined = true;
			$quarantine             = self::quarantineConnection( $term_id );
			$quarantine_closed      = $quarantine['closed'];
			return new \WP_Error(
				'venue_rollback_uncertain',
				'The database did not confirm the venue rollback; the connection was quarantined.',
				array(
					'status'               => 503,
					'original_error'       => $error->get_error_code(),
					'connection_closed'    => $quarantine['closed'],
					'connection_recovered' => $quarantine['recovered'],
				)
			);
		}
		$transaction_open = false;
		self::clearCaches( $term_id );
		return $error;
	}

	/**
	 * Close the uncertain server session, which ends its transaction and locks.
	 *
	 * A fresh wpdb connection is attempted only after the old session is closed.
	 * No RELEASE_LOCK query is sent against an uncertain transaction state.
	 *
	 * @param int $term_id Venue term ID.
	 * @return array{closed:bool,recovered:bool}
	 */
	private static function quarantineConnection( int $term_id ): array {
		global $wpdb;
		$closed = empty( $wpdb->dbh ) || ( is_callable( array( $wpdb, 'close' ) ) && true === call_user_func( array( $wpdb, 'close' ) ) );
		if ( ! $closed ) {
			return array(
				'closed'    => false,
				'recovered' => false,
			);
		}
		self::$native_term_locks = array();
		$recovered               = true === $wpdb->check_connection( false );
		if ( $recovered ) {
			self::clearCaches( $term_id );
		}
		return array(
			'closed'    => true,
			'recovered' => $recovered,
		);
	}

	/**
	 * Acquire a multisite-scoped MySQL advisory lock.
	 *
	 * @param int $term_id Venue term ID.
	 * @return string|\WP_Error
	 */
	private static function acquireLock( int $term_id ): string|\WP_Error {
		global $wpdb;
		$lock_key = self::lockName( $term_id );
		$timeout  = (int) apply_filters( 'data_machine_events_venue_lock_timeout', self::LOCK_TIMEOUT, $term_id );
		$result   = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_key, max( 0, $timeout ) ) );
		if ( '1' !== (string) $result ) {
			return new \WP_Error( 'venue_lock_unavailable', 'The venue is currently being updated; retry the request.', array( 'status' => 409 ) );
		}
		return $lock_key;
	}

	/**
	 * Release an acquired advisory lock.
	 *
	 * @param string $lock_key Lock key.
	 */
	private static function releaseLock( string $lock_key ): void {
		global $wpdb;
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key ) );
		if ( '1' !== (string) $result ) {
			do_action( 'datamachine_log', 'error', 'Venue mutation advisory lock release failed', array( 'lock_key' => $lock_key ) );
		}
	}

	/**
	 * Convert an owner guard error into a request-stopping native failure.
	 *
	 * @param \WP_Error $error Guard error.
	 */
	private static function abortNativeTermEdit( \WP_Error $error ): never {
		wp_die( esc_html( $error->get_error_message() ), 'Venue update rejected', array( 'response' => (int) ( $error->get_error_data()['status'] ?? 409 ) ) );
	}

	/**
	 * Read the mutable wpdb error channel after a WordPress write.
	 *
	 * @return string
	 */
	private static function databaseLastError(): string {
		global $wpdb;
		return (string) $wpdb->last_error;
	}

	/**
	 * Release native locks if WordPress aborts before saved_venue.
	 */
	private static function registerShutdownCleanup(): void {
		if ( self::$shutdown_registered ) {
			return;
		}
		self::$shutdown_registered = true;
		register_shutdown_function(
			static function (): void {
				while ( ! empty( self::$native_term_locks ) ) {
					self::endNativeTermEdit();
				}
			}
		);
	}

	/**
	 * Execute bounded transaction control SQL. Overridable for failure tests.
	 *
	 * @param string $sql Transaction statement.
	 * @return int|bool
	 */
	protected static function query( string $sql ): int|bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only fixed transaction statements reach this method.
		return $wpdb->query( $sql );
	}

	/**
	 * Purge term and metadata caches after transaction boundaries.
	 *
	 * @param int $term_id Venue term ID.
	 */
	private static function clearCaches( int $term_id ): void {
		wp_cache_delete( $term_id, 'term_meta' );
		clean_term_cache( $term_id, self::TAXONOMY );
	}
}
