<?php
/**
 * Atomic Calendar generation and publication revision owner.
 *
 * @package DataMachineEvents\Blocks\Calendar\Cache
 */

namespace DataMachineEvents\Blocks\Calendar\Cache;

use DataMachine\Core\Database\TransactionScope;

defined( 'ABSPATH' ) || exit;

class CalendarGenerationFence {

	public const OPTION = 'data_machine_events_calendar_generation_fence';

	private const MAX_CAS_ATTEMPTS = 8;

	/** Whether the active database supports the conditional owner transition. */
	public static function isSupported(): bool {
		global $wpdb;
		$db_engine     = defined( 'DB_ENGINE' ) ? strtolower( (string) constant( 'DB_ENGINE' ) ) : '';
		$database_type = defined( 'DATABASE_TYPE' ) ? strtolower( (string) constant( 'DATABASE_TYPE' ) ) : '';

		if ( 'sqlite' === $db_engine || 'sqlite' === $database_type ) {
			return false;
		}

		return (bool) apply_filters( 'data_machine_events_calendar_generation_fence_supported', true === $wpdb->is_mysql );
	}

	/** Return the current canonical state, initializing it from the legacy generation when needed. */
	public static function currentState(): array|false {
		if ( ! self::isSupported() ) {
			return false;
		}

		self::ensureState();
		$current = self::readRawState();
		return false === $current ? false : $current['state'];
	}

	/** Reserve the next globally monotonic site revision without rotating the generation. */
	public static function reserveRevision( ?callable $query = null ): int|false {
		$result = self::mutate(
			static function ( array $state ): array {
				$state['revision']         = (int) $state['revision'] + 1;
				$state['publisher_job_id'] = 0;
				$state['warmer_scheduled'] = false;
				return $state;
			},
			$query
		);

		return false === $result ? false : (int) $result['state']['revision'];
	}

	/** Atomically supersede every queued publisher and rotate the public generation. */
	public static function invalidate( string $generation, ?callable $query = null ): array|false {
		if ( '' === $generation || ! self::isSupported() ) {
			return false;
		}

		$result = self::mutate(
			static function ( array $state ) use ( $generation ): array {
				$state['revision']         = (int) $state['revision'] + 1;
				$state['generation']       = $generation;
				$state['publisher_job_id'] = 0;
				$state['warmer_scheduled'] = false;
				return $state;
			},
			$query
		);

		return false === $result ? self::invalidateWithRowLock( $generation ) : $result;
	}

	/**
	 * Publish only the currently-owned revision and elect one publisher job.
	 *
	 * @return array{status:string,state:array,schedule_warmer:bool}|false
	 */
	public static function publish( int $revision, string $generation, int $job_id, ?callable $query = null ): array|false {
		if ( $revision <= 0 || '' === $generation || $job_id <= 0 || ! self::isSupported() ) {
			return false;
		}

		for ( $attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; ++$attempt ) {
			$current = self::readRawState();
			if ( false === $current ) {
				return false;
			}

			$state = $current['state'];
			if ( (int) $state['revision'] > $revision ) {
				return array(
					'status'          => 'superseded',
					'state'           => $state,
					'schedule_warmer' => false,
				);
			}
			if ( (int) $state['revision'] < $revision ) {
				return false;
			}

			$winner = (int) $state['publisher_job_id'];
			if ( $winner > 0 ) {
				if ( ! hash_equals( (string) $state['generation'], $generation ) ) {
					return false;
				}

				return array(
					'status'          => $winner === $job_id ? 'replay' : 'duplicate_publisher',
					'state'           => $state,
					'schedule_warmer' => $winner === $job_id && empty( $state['warmer_scheduled'] ),
				);
			}

			$next                     = $state;
			$next['generation']       = $generation;
			$next['publisher_job_id'] = $job_id;
			$next['warmer_scheduled'] = false;
			if ( self::compareAndSwap( $current['raw'], $next, $query ) ) {
				self::afterStateChange( $state, $next );
				return array(
					'status'          => 'published',
					'state'           => $next,
					'schedule_warmer' => true,
				);
			}
		}

		return false;
	}

	/** Persist the elected publisher's successful warmer handoff. */
	public static function markWarmerScheduled( int $revision, string $generation, int $job_id, ?callable $query = null ): bool {
		for ( $attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; ++$attempt ) {
			$current = self::readRawState();
			if ( false === $current ) {
				return false;
			}

			$state = $current['state'];
			if ( (int) $state['revision'] > $revision ) {
				return true;
			}
			if (
				(int) $state['revision'] !== $revision
				|| (int) $state['publisher_job_id'] !== $job_id
				|| ! hash_equals( (string) $state['generation'], $generation )
			) {
				return false;
			}
			if ( ! empty( $state['warmer_scheduled'] ) ) {
				return true;
			}

			$next                     = $state;
			$next['warmer_scheduled'] = true;
			if ( self::compareAndSwap( $current['raw'], $next, $query ) ) {
				self::clearOptionCaches();
				return true;
			}
		}

		return false;
	}

	/** Apply a CAS mutation and return both previous and current state. */
	private static function mutate( callable $mutation, ?callable $query ): array|false {
		if ( ! self::isSupported() ) {
			return false;
		}

		self::ensureState();
		for ( $attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; ++$attempt ) {
			$current = self::readRawState();
			if ( false === $current ) {
				return false;
			}
			$next = $mutation( $current['state'] );
			if ( self::compareAndSwap( $current['raw'], $next, $query ) ) {
				self::afterStateChange( $current['state'], $next );
				return array(
					'previous' => $current['state'],
					'state'    => $next,
				);
			}
		}

		return false;
	}

	/** Atomically replace the exact state row that was read. */
	private static function compareAndSwap( string $expected_raw, array $next, ?callable $query ): bool {
		global $wpdb;

		$sql = $wpdb->prepare(
			'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
			$wpdb->options,
			maybe_serialize( $next ),
			self::OPTION,
			$expected_raw
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Exact option-row CAS is the concurrency boundary.
		$affected = null === $query ? $wpdb->query( $sql ) : $query( $sql );
		return 1 === (int) $affected;
	}

	/** Fall back from exhausted CAS contention to an exact row-locked transition. */
	private static function invalidateWithRowLock( string $generation ): array|false {
		global $wpdb;

		$scope = TransactionScope::begin( $wpdb );
		if ( null === $scope ) {
			return false;
		}

		$committed = false;
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact option row is locked until generation and revision move together.
			$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1 FOR UPDATE', $wpdb->options, self::OPTION ) );
			if ( ! is_string( $raw ) ) {
				return false;
			}
			$previous = self::normalizeState( maybe_unserialize( $raw ) );
			if ( false === $previous ) {
				return false;
			}

			$next                     = $previous;
			$next['revision']         = (int) $previous['revision'] + 1;
			$next['generation']       = $generation;
			$next['publisher_job_id'] = 0;
			$next['warmer_scheduled'] = false;
			$sql                      = $wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				maybe_serialize( $next ),
				self::OPTION,
				$raw
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The locked row still uses an exact expected-value predicate.
			if ( 1 !== (int) $wpdb->query( $sql ) || ! $scope->commit() ) {
				return false;
			}
			$committed = true;
			self::afterStateChange( $previous, $next );
			return array(
				'previous' => $previous,
				'state'    => $next,
			);
		} finally {
			if ( ! $committed ) {
				$scope->rollback();
			}
		}
	}

	/** Read the uncached row required for an exact affected-row CAS. */
	private static function readRawState(): array|false {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The CAS must read current database state, not object cache state.
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, self::OPTION ) );
		if ( ! is_string( $raw ) ) {
			return false;
		}
		$state = self::normalizeState( maybe_unserialize( $raw ) );
		return false === $state
			? false
			: array(
				'raw'   => $raw,
				'state' => $state,
			);
	}

	/** Create the non-autoloaded state row once, preserving the existing generation. */
	private static function ensureState(): void {
		if ( false !== self::readRawState() ) {
			return;
		}
		self::clearOptionCaches();

		$generation = (string) get_option( CalendarCache::GENERATION_OPTION, '' );
		if ( '' === $generation ) {
			$generation = wp_generate_uuid4();
			add_option( CalendarCache::GENERATION_OPTION, $generation, '', false );
			$generation = (string) get_option( CalendarCache::GENERATION_OPTION, $generation );
		}
		add_option(
			self::OPTION,
			array(
				'revision'         => 0,
				'generation'       => $generation,
				'publisher_job_id' => 0,
				'warmer_scheduled' => false,
			),
			'',
			false
		);
		self::clearOptionCaches();
	}

	private static function normalizeState( $state ): array|false {
		if ( ! is_array( $state ) || empty( $state['generation'] ) ) {
			return false;
		}

		return array(
			'revision'         => max( 0, (int) ( $state['revision'] ?? 0 ) ),
			'generation'       => (string) $state['generation'],
			'publisher_job_id' => max( 0, (int) ( $state['publisher_job_id'] ?? 0 ) ),
			'warmer_scheduled' => ! empty( $state['warmer_scheduled'] ),
		);
	}

	/** Keep WordPress option caches and the legacy generation observer coherent. */
	private static function afterStateChange( array $previous, array $next ): void {
		self::clearOptionCaches();
		if ( hash_equals( (string) $previous['generation'], (string) $next['generation'] ) ) {
			return;
		}

		update_option( CalendarCache::GENERATION_OPTION, (string) $next['generation'], false );
	}

	private static function clearOptionCaches(): void {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
