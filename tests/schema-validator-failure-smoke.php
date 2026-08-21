<?php
/** Post-convergence table/column/index validation regression. */

declare( strict_types=1 );

namespace DataMachine\Core\Database {
	abstract class BaseRepository {
		public static function database_table_exists( string $table, $wpdb = null ): bool { unset( $wpdb ); return isset( $GLOBALS['schema_tables'][ $table ] ); }
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );

	class SchemaValidatorWpdb {
		public string $prefix = 'wp_2_';
		public string $base_prefix = 'wp_';
		public string $last_error = '';
		public function prepare( string $query, ...$args ): string {
			foreach ( $args as $arg ) { $query = preg_replace( '/%i/', (string) $arg, $query, 1 ); }
			return $query;
		}
		public function get_results( string $query, $format = null ): array {
			unset( $format );
			if ( preg_match( '/SHOW COLUMNS FROM (\S+)/', $query, $match ) ) {
				return array_map( static fn( string $name ): array => array( 'Field' => $name ), $GLOBALS['schema_tables'][ $match[1] ]['columns'] ?? array() );
			}
			if ( preg_match( '/SHOW INDEX FROM (\S+)/', $query, $match ) ) {
				return array_map( static fn( string $name ): array => array( 'Key_name' => $name ), $GLOBALS['schema_tables'][ $match[1] ]['indexes'] ?? array() );
			}
			return array();
		}
	}

	$GLOBALS['wpdb'] = new SchemaValidatorWpdb();
	function do_action( string $hook, ...$args ): void { unset( $hook, $args ); }

	require_once dirname( __DIR__ ) . '/inc/Core/Bootstrap/ActivationServiceProvider.php';

	use DataMachine\Core\Bootstrap\ActivationServiceProvider;

	$GLOBALS['schema_tables'] = ActivationServiceProvider::current_schema_requirements();
	$assert = static function ( bool $condition, string $message ): void {
		if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
	};

	$assert( ActivationServiceProvider::validate_current_schema(), 'complete schema validates' );

	$missing_table = 'wp_2_datamachine_jobs';
	$saved_table   = $GLOBALS['schema_tables'][ $missing_table ];
	unset( $GLOBALS['schema_tables'][ $missing_table ] );
	$assert( ! ActivationServiceProvider::validate_current_schema(), 'actual missing table fails setup validation' );
	$GLOBALS['schema_tables'][ $missing_table ] = $saved_table;

	$column_table = 'wp_datamachine_chat_sessions';
	$column_key   = array_search( 'owner_key_hash', $GLOBALS['schema_tables'][ $column_table ]['columns'], true );
	unset( $GLOBALS['schema_tables'][ $column_table ]['columns'][ $column_key ] );
	$assert( ! ActivationServiceProvider::validate_current_schema(), 'actual missing canonical column fails setup validation' );
	$GLOBALS['schema_tables'][ $column_table ] = ActivationServiceProvider::current_schema_requirements()[ $column_table ];

	$index_table = 'wp_2_datamachine_processed_items';
	$index_key   = array_search( 'status_claim_expires', $GLOBALS['schema_tables'][ $index_table ]['indexes'], true );
	unset( $GLOBALS['schema_tables'][ $index_table ]['indexes'][ $index_key ] );
	$assert( ! ActivationServiceProvider::validate_current_schema(), 'actual missing ensured index fails setup validation' );

	echo "Schema validator failure smoke passed.\n";
}
