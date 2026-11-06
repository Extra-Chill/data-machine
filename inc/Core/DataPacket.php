<?php
/**
 * Data Packet Management
 *
 * Provides unified data packet creation and management across the entire pipeline system.
 * Replaces scattered array creation with centralized, consistent data packet handling.
 *
 * @package DataMachine\Core
 * @since 0.2.1
 */

namespace DataMachine\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DataPacket {
	private array $data;
	private array $metadata;
	private string $type;

	/**
	 * Create a new data packet.
	 *
	 * @param array  $data     Content data (title, body, file_info, etc.)
	 * @param array  $metadata Metadata (source_type, timestamps, etc.)
	 * @param string $type     Packet type (fetch, ai_response, tool_result, etc.)
	 */
	public function __construct( array $data, array $metadata, string $type ) {
		$this->data     = $data;
		$this->metadata = $metadata;
		$this->type     = $type;
	}

	/**
	 * Add this packet to the data packets array.
	 *
	 * Maintains pipeline workflow: adds to front of array so most recent
	 * contributions are easily accessible by subsequent steps.
	 *
	 * @param array $dataPackets Current data packets array
	 * @return array Updated data packets array
	 */
	public function addTo( array $dataPackets ): array {
		$packet = array(
			'type'      => $this->type,
			'timestamp' => time(),
			'data'      => $this->data,
			'metadata'  => $this->metadata,
		);

		array_unshift( $dataPackets, $packet );
		return $dataPackets;
	}
}
