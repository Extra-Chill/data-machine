<?php
/**
 * Canonical persistence policy for high-churn Data Machine tables.
 *
 * @package DataMachine\Core\Database
 */

namespace DataMachine\Core\Database;

defined( 'ABSPATH' ) || exit;

class CanonicalPersistencePolicy {

	/** Register optional policy consumed by file-backed database runtimes. */
	public static function register(): void {
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'markdown_db_table_persistence_policy', array( self::class, 'filterTablePolicy' ) );
		}
	}

	/**
	 * Declare stable row identities without replacing policies owned elsewhere.
	 *
	 * @param mixed $policy Existing table policy map.
	 * @return array<string,mixed>
	 */
	public static function filterTablePolicy( $policy ): array {
		$policy = is_array( $policy ) ? $policy : array();
		self::addDefaultIdentity( $policy, 'datamachine_jobs', 'job_id' );
		self::addDefaultIdentity( $policy, 'datamachine_logs', 'id' );
		return $policy;
	}

	/** @param array<string,mixed> $policy */
	private static function addDefaultIdentity( array &$policy, string $table, string $column ): void {
		if ( ! array_key_exists( $table, $policy ) ) {
			$policy[ $table ] = array( 'partition_by' => $column );
			return;
		}
		if ( is_array( $policy[ $table ] ) && ! array_key_exists( 'partition_by', $policy[ $table ] ) ) {
			$policy[ $table ]['partition_by'] = $column;
		}
	}
}
