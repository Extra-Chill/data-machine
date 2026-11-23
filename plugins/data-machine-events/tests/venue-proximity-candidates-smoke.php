<?php
/** Run: php tests/venue-proximity-candidates-smoke.php (no WordPress needed). */
define( 'ABSPATH', __DIR__ );
require_once dirname( __DIR__ ) . '/inc/Core/Venue_Taxonomy.php';

// Execute the production SQL against an in-memory database rather than
// mocking the candidate query result.
$wpdb = new class {
	public string $termmeta = 'termmeta';
	public string $term_taxonomy = 'term_taxonomy';
	public PDO $db;
	public function __construct() {
		$this->db = new PDO( 'sqlite::memory:' );
		$this->db->sqliteCreateFunction( 'SUBSTRING_INDEX', static function ( $value, $delimiter, $count ) {
			$parts = explode( $delimiter, $value );
			return $count > 0 ? $parts[0] : end( $parts );
		}, 3 );
		$this->db->exec( 'CREATE TABLE termmeta (term_id INTEGER, meta_key TEXT, meta_value TEXT)' );
		$this->db->exec( 'CREATE TABLE term_taxonomy (term_id INTEGER, taxonomy TEXT)' );
	}
	public function prepare( $sql, ...$args ) {
		return preg_replace_callback( '/%[sf]/', function ( $match ) use ( &$args ) {
			$value = array_shift( $args );
			return '%s' === $match[0] ? $this->db->quote( $value ) : sprintf( '%F', $value );
		}, $sql );
	}
	public function get_col( $sql ) {
		return $this->db->query( $sql )->fetchAll( PDO::FETCH_COLUMN );
	}
	public function get_results( $sql ) {
		return $this->db->query( $sql )->fetchAll( PDO::FETCH_OBJ );
	}
};
$insert = $wpdb->db->prepare( 'INSERT INTO termmeta VALUES (?, ?, ?)' );
for ( $i = 1; $i <= 7500; ++$i ) {
	$insert->execute( array( $i, '_venue_coordinates', '40,-100' ) );
	$wpdb->db->exec( "INSERT INTO term_taxonomy VALUES ($i, 'venue')" );
}
$wpdb->db->exec( "INSERT INTO term_taxonomy VALUES (7501, 'venue')" );
$method = new ReflectionMethod( DataMachineEvents\Core\Venue_Taxonomy::class, 'proximity_candidate_ids' );
$cases = array(
	array( array( 12, 34 ), '12.0001,34' ),
	array( array( 0, 179.99995 ), '0,-179.99995' ),
	array( array( 0, -179.99995 ), '0,179.99995' ),
	array( array( 90, 0 ), '89.9999,150' ),
	array( array( -90, 0 ), '-89.9999,-150' ),
	array( array( 12, 34 ), ' 1.20001e1, 3.4e1 ' ),
	array( array( 12, 34 ), '12.0002248,34' ),
);
foreach ( $cases as list( $point, $stored ) ) {
	$wpdb->db->exec( 'DELETE FROM termmeta WHERE term_id = 7501' );
	$insert->execute( array( 7501, '_venue_coordinates', $stored ) );
	$ids = $method->invoke( null, $point );
	if ( array( 7501 ) !== $ids ) {
		throw new RuntimeException( 'Box lost nearby venue or loaded distant venues: ' . json_encode( array( $point, $stored, $ids ) ) );
	}
}
$wpdb->db->exec( "UPDATE termmeta SET meta_value = '40,-100' WHERE term_id = 7501" );
if ( array() !== $method->invoke( null, array( 12, 34 ) ) ) {
	throw new RuntimeException( 'Coordinate update returned stale candidates' );
}
echo "PASS: proximity boxes exclude 7500 distant venues; ordinary, dateline, poles, numeric formatting, radius boundary, and updates.\n";
