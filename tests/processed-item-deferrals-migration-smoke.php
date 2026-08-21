<?php

/** Pure-PHP migration fixture for durable processed-item deferrals. */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['deferral_migration_options'] = array();
$GLOBALS['deferral_migration_schema']  = array( 'deferral_count', 'last_deferral_job_id', 'deferred_at', 'last_seen_at', 'status_deferred_at' );
$GLOBALS['deferral_migration_valid']   = false;
$GLOBALS['deferral_migration_rows']    = array( array( 'id' => 7, 'item_identifier' => 'existing-row' ) );
$GLOBALS['deferral_migration_repairs'] = 0;
$GLOBALS['deferral_migration_succeed'] = false;

function get_option( string $name, mixed $default = false ): mixed {
	return $GLOBALS['deferral_migration_options'][ $name ] ?? $default;
}

function update_option( string $name, mixed $value, bool $autoload = true ): bool {
	unset( $autoload );
	$GLOBALS['deferral_migration_options'][ $name ] = $value;
	return true;
}

eval(
	'namespace DataMachine\Core\Database\ProcessedItems;
	class ProcessedItems {
		public function create_table(): void {
			++$GLOBALS["deferral_migration_repairs"];
		}
		public function get_table_name(): string {
			return "wp_datamachine_processed_items";
		}
		public static function ensure_deferral_schema(string $table): void {
			unset($table);
			if ($GLOBALS["deferral_migration_succeed"]) {
				$GLOBALS["deferral_migration_schema"] = array("deferral_count", "last_deferral_job_id", "deferred_at", "last_seen_at", "status_deferred_at");
				$GLOBALS["deferral_migration_valid"] = true;
			}
		}
		public static function validate_deferral_schema(string $table): bool {
			unset($table);
			$required = array("deferral_count", "last_deferral_job_id", "deferred_at", "last_seen_at", "status_deferred_at");
			return $GLOBALS["deferral_migration_valid"] && array() === array_diff($required, $GLOBALS["deferral_migration_schema"]);
		}
	}'
);

require dirname( __DIR__ ) . '/inc/migrations/processed-item-claims.php';

datamachine_migrate_processed_item_deferrals();
if ( get_option( 'datamachine_processed_item_deferrals_migrated' ) || 1 !== $GLOBALS['deferral_migration_repairs'] ) {
	throw new RuntimeException( 'Partial schema incorrectly completed migration.' );
}
if ( array( array( 'id' => 7, 'item_identifier' => 'existing-row' ) ) !== $GLOBALS['deferral_migration_rows'] ) {
	throw new RuntimeException( 'Migration changed an existing ledger row.' );
}

$GLOBALS['deferral_migration_succeed'] = true;
datamachine_migrate_processed_item_deferrals();
if ( true !== get_option( 'datamachine_processed_item_deferrals_migrated' ) || 2 !== $GLOBALS['deferral_migration_repairs'] ) {
	throw new RuntimeException( 'Partial schema was not retried through successful validation.' );
}
if ( array( array( 'id' => 7, 'item_identifier' => 'existing-row' ) ) !== $GLOBALS['deferral_migration_rows'] ) {
	throw new RuntimeException( 'Successful retry changed an existing ledger row.' );
}

$GLOBALS['deferral_migration_valid']   = false;
$GLOBALS['deferral_migration_succeed'] = true;
datamachine_migrate_processed_item_deferrals();
if ( 3 !== $GLOBALS['deferral_migration_repairs'] ) {
	throw new RuntimeException( 'A stale completion option hid a partial schema.' );
}

echo "processed-item-deferrals-migration-smoke: PASS\n";
