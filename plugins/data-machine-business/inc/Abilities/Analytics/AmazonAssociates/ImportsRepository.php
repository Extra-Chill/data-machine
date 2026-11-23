<?php
/**
 * Import-window ledger for Amazon Associates reports.
 *
 * Coverage cannot be derived from report lines alone: a day with no sales has
 * no rows but is still covered by the import. Each import records the report
 * type, marketplace and inclusive date window it replaced.
 *
 * @package DataMachineBusiness\Abilities\Analytics\AmazonAssociates
 */

namespace DataMachineBusiness\Abilities\Analytics\AmazonAssociates;

use DataMachine\Core\Database\BaseRepository;

defined( 'ABSPATH' ) || exit;

class ImportsRepository extends BaseRepository {

	const TABLE_NAME = 'datamachine_amazon_associates_imports';

	protected static function get_table_prefix(): string {
		global $wpdb;
		return $wpdb->base_prefix;
	}

	public static function create_table(): void {
		global $wpdb;
		$table   = $wpdb->base_prefix . self::TABLE_NAME;
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			import_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			import_batch VARCHAR(64) NOT NULL,
			report_type VARCHAR(20) NOT NULL,
			marketplace VARCHAR(64) NOT NULL DEFAULT '',
			window_start DATE NOT NULL,
			window_end DATE NOT NULL,
			row_count INT(11) NOT NULL DEFAULT 0,
			tracking_ids TEXT NULL,
			source_label VARCHAR(191) NOT NULL DEFAULT '',
			imported_at DATETIME NOT NULL,
			PRIMARY KEY  (import_id),
			KEY type_window (report_type, marketplace, window_start, window_end),
			KEY import_batch (import_batch)
		) {$charset};";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );
	}

	/** Drop ledger entries fully superseded by a new import window. */
	public function delete_contained( string $report_type, string $marketplace, string $start, string $end ): void {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned ledger maintenance; fully prepared.
		$this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE FROM %i WHERE report_type = %s AND marketplace = %s AND window_start >= %s AND window_end <= %s',
				$this->table_name,
				$report_type,
				$marketplace,
				$start,
				$end
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$this->log_db_error( 'delete superseded Amazon Associates imports' );
	}

	public function record( array $import ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned ledger insert.
		$result = $this->wpdb->insert(
			$this->table_name,
			array(
				'import_batch' => $import['import_batch'],
				'report_type'  => $import['report_type'],
				'marketplace'  => $import['marketplace'],
				'window_start' => $import['window_start'],
				'window_end'   => $import['window_end'],
				'row_count'    => (int) $import['row_count'],
				'tracking_ids' => (string) wp_json_encode( array_values( $import['tracking_ids'] ) ),
				'source_label' => substr( (string) $import['source_label'], 0, 191 ),
				'imported_at'  => $import['imported_at'],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		$this->log_db_error( 'record Amazon Associates import' );
		return false !== $result;
	}

	/**
	 * Ledger entries, optionally restricted to a type/marketplace and to windows overlapping a date range.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function find( array $filters = array() ): array {
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['report_type'] ) ) {
			$where[] = 'report_type = %s';
			$args[]  = (string) $filters['report_type'];
		}
		if ( ! empty( $filters['marketplace'] ) ) {
			$where[] = 'marketplace = %s';
			$args[]  = (string) $filters['marketplace'];
		}
		if ( ! empty( $filters['start'] ) ) {
			$where[] = 'window_end >= %s';
			$args[]  = (string) $filters['start'];
		}
		if ( ! empty( $filters['end'] ) ) {
			$where[] = 'window_start <= %s';
			$args[]  = (string) $filters['end'];
		}
		$sql = "SELECT * FROM {$this->table_name} WHERE " . implode( ' AND ', $where ) . ' ORDER BY report_type, marketplace, window_start, import_id';
		if ( ! empty( $args ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed fragments with placeholders.
			$sql = $this->wpdb->prepare( $sql, $args ); // @phpstan-ignore argument.type
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}
