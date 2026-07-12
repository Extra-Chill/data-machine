<?php
/**
 * Smoke test for packet-provided engine data sanitation.
 *
 * @package DataMachine
 */

define( 'ABSPATH', __DIR__ . '/' );

function do_action() {}

require_once dirname( __DIR__ ) . '/inc/Core/PacketEngineData.php';

use DataMachine\Core\PacketEngineData;

$result = PacketEngineData::sanitize(
	array(
		'source_url'      => 'https://example.com/source',
		'image_file_path' => '/tmp/image.jpg',
		'job'             => array( 'job_id' => 999 ),
		'flow_config'     => array( 'unsafe' => true ),
		'batch_id'        => 'unsafe',
	),
	42
);

$expected = array(
	'source_url'      => 'https://example.com/source',
	'image_file_path' => '/tmp/image.jpg',
);

if ( $expected !== $result ) {
	fwrite( STDERR, "Packet engine data sanitation failed.\n" );
	exit( 1 );
}

echo "Packet engine data smoke test passed.\n";
