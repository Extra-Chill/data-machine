<?php
/**
 * Operator-only runner for registered database migrations.
 *
 * @package DataMachine\Core\Database\Migrations
 */

namespace DataMachine\Core\Database\Migrations;

use DataMachine\Core\Bootstrap\RuntimeEnvironment;
use DataMachine\Core\OptionLeaseStore;

defined( 'ABSPATH' ) || exit;

/**
 * Discovers migrations, refuses web context, and serializes apply() with a lease.
 *
 * create_table() / ensure_all_tables() must never call apply(). Schema ALTERs
 * stay on that path; row walks come through here, from WP-CLI only.
 */
class MigrationRunner {

	public const LEASE_OPTION = 'datamachine_migration_lease';

	public const LEASE_TTL = 120;

	/**
	 * @return list<Migration>
	 */
	public static function registered(): array {
		$migrations = array(
			new JobsStatusReasonBackfill(),
			new JobsTaskTypeBackfill(),
			new JobsHandlerSlugBackfill(),
			new JobsStatusNormalizationMigration(),
		);

		/**
		 * Filter the registered Data Machine migrations.
		 *
		 * @param list<Migration> $migrations Default migrations.
		 */
		/**
		 * @var mixed $filtered
		 */
		$filtered = apply_filters( 'datamachine_migrations', $migrations );
		if ( ! is_array( $filtered ) ) {
			return $migrations;
		}

		$out = array();
		foreach ( $filtered as $item ) {
			if ( $item instanceof Migration ) {
				$out[] = $item;
			}
		}

		return $out;
	}

	/**
	 * Inspect every registered migration without mutating rows.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function status(): array {
		$rows = array();
		foreach ( self::registered() as $migration ) {
			$inspect          = $migration->inspect();
			$inspect['id']    = $migration->id();
			$inspect['label'] = $migration->description();
			$rows[]           = $inspect;
		}

		return $rows;
	}

	/**
	 * Apply one bounded batch of one migration.
	 *
	 * @param string   $id       Migration id.
	 * @param int      $limit    Batch size.
	 * @param bool|null $operator Override operator-context detection. Null uses the runtime.
	 * @return array<string,mixed>
	 */
	public static function run( string $id, int $limit = 250, ?bool $operator = null ): array {
		if ( ! ( $operator ?? self::isOperatorContext() ) ) {
			return array(
				'success' => false,
				'code'    => 'web_context',
				'error'   => 'Migrations must not run in a web request. Use `wp datamachine migrate run`.',
				'id'      => $id,
			);
		}

		$migration = self::find( $id );
		if ( null === $migration ) {
			return array(
				'success' => false,
				'code'    => 'unknown_migration',
				'error'   => sprintf( 'Unknown migration: %s', $id ),
				'id'      => $id,
			);
		}

		if ( $migration->isComplete() ) {
			$inspect            = $migration->inspect();
			$inspect['success'] = true;
			$inspect['id']      = $id;
			$inspect['code']    = 'already_complete';

			return $inspect;
		}

		$now     = time();
		$token   = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		$payload = array(
			'token'      => $token,
			'owner'      => $id,
			'started_at' => $now,
			'expires_at' => $now + self::LEASE_TTL,
			'ttl'        => self::LEASE_TTL,
		);
		$lease   = OptionLeaseStore::acquire( self::LEASE_OPTION, $payload, self::LEASE_TTL, $now, null, true );
		if ( ! $lease['acquired'] ) {
			return array(
				'success' => false,
				'code'    => 'locked',
				'error'   => 'Another migration invocation holds the lease. Retry after it finishes.',
				'id'      => $id,
				'holder'  => $lease['payload']['owner'] ?? '',
			);
		}

		try {
			$result         = $migration->apply( $limit );
			$result['id']   = $id;
			$result['code'] = ! empty( $result['complete'] ) ? 'complete' : 'in_progress';

			return $result;
		} finally {
			OptionLeaseStore::release( self::LEASE_OPTION, $token );
		}
	}

	/**
	 * Apply one batch of the first incomplete migration.
	 *
	 * @return array<string,mixed>
	 */
	public static function runNext( int $limit = 250, ?bool $operator = null ): array {
		foreach ( self::registered() as $migration ) {
			if ( ! $migration->isComplete() ) {
				return self::run( $migration->id(), $limit, $operator );
			}
		}

		return array(
			'success' => true,
			'code'    => 'all_complete',
			'error'   => '',
		);
	}

	public static function isOperatorContext(): bool {
		return RuntimeEnvironment::is_wp_cli() || RuntimeEnvironment::is_wordpress_tests();
	}

	public static function find( string $id ): ?Migration {
		foreach ( self::registered() as $migration ) {
			if ( $migration->id() === $id ) {
				return $migration;
			}
		}

		return null;
	}
}
