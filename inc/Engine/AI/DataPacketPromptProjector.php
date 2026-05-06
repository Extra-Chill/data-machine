<?php
/**
 * DataPacket prompt projection.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Builds compact, prompt-facing packet copies without changing canonical packets.
 */
class DataPacketPromptProjector {

	/**
	 * Project canonical DataPackets for AI prompt serialization.
	 *
	 * @param array $data_packets Canonical packets from storage/engine state.
	 * @return array Prompt-facing packet copies.
	 */
	public static function project( array $data_packets ): array {
		$projected_packets = array();

		foreach ( $data_packets as $packet ) {
			if ( ! is_array( $packet ) ) {
				$projected_packets[] = $packet;
				continue;
			}

			$projected_packets[] = self::projectPacket( $packet );
		}

		return $projected_packets;
	}

	/**
	 * Project one packet.
	 *
	 * @param array $packet Canonical packet.
	 * @return array Prompt-facing packet.
	 */
	private static function projectPacket( array $packet ): array {
		$data     = is_array( $packet['data'] ?? null ) ? $packet['data'] : array();
		$metadata = is_array( $packet['metadata'] ?? null ) ? $packet['metadata'] : array();

		if ( self::isMcpPacket( $data, $metadata ) ) {
			return self::projectMcpPacket( $packet, $data, $metadata );
		}

		$projected = $packet;
		if ( isset( $projected['data'] ) && is_array( $projected['data'] ) ) {
			$projected['data'] = self::sanitizePacketData( $projected['data'] );
		}

		return $projected;
	}

	/**
	 * Detect packets from MCP/MGS-style fetchers.
	 *
	 * @param array $data Packet data.
	 * @param array $metadata Packet metadata.
	 * @return bool
	 */
	private static function isMcpPacket( array $data, array $metadata ): bool {
		return isset( $metadata['mcp_raw_item'] )
			|| isset( $metadata['mcp_url'] )
			|| isset( $metadata['mcp_tool'] )
			|| isset( $metadata['mcp_provider'] )
			|| 'mcp' === ( $metadata['source_type'] ?? '' );
	}

	/**
	 * Project an MCP/MGS packet by flattening useful source fields and removing duplicates.
	 *
	 * @param array $packet Canonical packet.
	 * @param array $data Packet data.
	 * @param array $metadata Packet metadata.
	 * @return array Prompt-facing packet.
	 */
	private static function projectMcpPacket( array $packet, array $data, array $metadata ): array {
		$source = self::decodeJsonObject( $data['body'] ?? null );
		if ( null === $source && is_array( $metadata['mcp_raw_item'] ?? null ) ) {
			$source = $metadata['mcp_raw_item'];
		}
		$source = is_array( $source ) ? $source : array();

		$projected_data = array_filter(
			array(
				'title'            => self::firstString( $source, $data, $metadata, array( 'title', 'name', 'subject' ) ),
				'body'             => self::firstString( $source, $data, array(), array( 'content', 'body', 'text', 'summary', 'description' ) ),
				'url'              => self::firstString( $source, $metadata, $data, array( 'url', 'link', 'permalink', 'source_url', 'mcp_url' ) ),
				'date'             => self::firstString( $source, $metadata, array(), array( 'date', 'created_at', 'updated_at', 'modified_at', 'mcp_date' ) ),
				'author'           => self::firstString( $source, $metadata, array(), array( 'author', 'byline', 'user', 'mcp_author' ) ),
				'matching_content' => self::firstSnippetValue( $source, $metadata, array(), array( 'matching_content', 'snippet', 'excerpt' ) ),
				'tags'             => self::firstValue( $source, $metadata, array(), array( 'tags', 'mcp_tags' ) ),
				'source_id'        => self::firstString( $source, $metadata, array(), array( 'id', 'guid', 'item_identifier', 'source_id' ) ),
			),
			static fn( $value ) => null !== $value && '' !== $value && array() !== $value
		);

		$projected_data = self::sanitizePacketData( $projected_data );

		$projected_metadata = array_filter(
			array(
				'source_type'     => $metadata['source_type'] ?? null,
				'source_url'      => $metadata['source_url'] ?? ( $metadata['mcp_url'] ?? null ),
				'item_identifier' => $metadata['item_identifier'] ?? null,
				'source_label'    => $metadata['source_label'] ?? ( $metadata['mcp_provider'] ?? null ),
			),
			static fn( $value ) => null !== $value && '' !== $value && array() !== $value
		);

		$projected = array(
			'type'     => $packet['type'] ?? 'fetch',
			'data'     => $projected_data,
			'metadata' => $projected_metadata,
		);

		if ( array_key_exists( 'timestamp', $packet ) ) {
			$projected['timestamp'] = $packet['timestamp'];
		}

		return $projected;
	}

	/**
	 * Remove internal fields from prompt-facing data.
	 *
	 * @param array $packet_data Packet data.
	 * @return array Sanitized packet data.
	 */
	private static function sanitizePacketData( array $packet_data ): array {
		if ( ! isset( $packet_data['file_info'] ) || ! is_array( $packet_data['file_info'] ) ) {
			return $packet_data;
		}

		$sanitized_file_info = $packet_data['file_info'];
		unset( $sanitized_file_info['file_path'] );

		if ( empty( $sanitized_file_info ) ) {
			unset( $packet_data['file_info'] );
			return $packet_data;
		}

		$packet_data['file_info'] = $sanitized_file_info;
		return $packet_data;
	}

	/**
	 * Decode a JSON object from a packet body.
	 *
	 * @param mixed $value Candidate JSON string.
	 * @return array|null
	 */
	private static function decodeJsonObject( mixed $value ): ?array {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Return the first scalar value as a string.
	 *
	 * @param array $primary Primary source.
	 * @param array $secondary Secondary source.
	 * @param array $tertiary Tertiary source.
	 * @param array $keys Candidate keys.
	 * @return string|null
	 */
	private static function firstString( array $primary, array $secondary, array $tertiary, array $keys ): ?string {
		foreach ( array( $primary, $secondary, $tertiary ) as $source ) {
			foreach ( $keys as $key ) {
				if ( ! array_key_exists( $key, $source ) || ! is_scalar( $source[ $key ] ) ) {
					continue;
				}

				$value = trim( (string) $source[ $key ] );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return null;
	}

	/**
	 * Return the first available value for any candidate key.
	 *
	 * @param array $primary Primary source.
	 * @param array $secondary Secondary source.
	 * @param array $tertiary Tertiary source.
	 * @param array $keys Candidate keys.
	 * @return mixed|null
	 */
	private static function firstValue( array $primary, array $secondary, array $tertiary, array $keys ): mixed {
		foreach ( array( $primary, $secondary, $tertiary ) as $source ) {
			foreach ( $keys as $key ) {
				if ( array_key_exists( $key, $source ) && null !== $source[ $key ] && '' !== $source[ $key ] ) {
					return $source[ $key ];
				}
			}
		}

		return null;
	}

	/**
	 * Return the first snippet value, preserving real MGS snippet arrays.
	 *
	 * @param array $primary Primary source.
	 * @param array $secondary Secondary source.
	 * @param array $tertiary Tertiary source.
	 * @param array $keys Candidate keys.
	 * @return string|array|null
	 */
	private static function firstSnippetValue( array $primary, array $secondary, array $tertiary, array $keys ): string|array|null {
		$value = self::firstValue( $primary, $secondary, $tertiary, $keys );

		if ( is_array( $value ) ) {
			$snippets = array();
			foreach ( $value as $snippet ) {
				if ( ! is_scalar( $snippet ) ) {
					continue;
				}

				$cleaned = self::cleanSnippet( (string) $snippet );
				if ( null !== $cleaned ) {
					$snippets[] = $cleaned;
				}
			}

			return empty( $snippets ) ? null : $snippets;
		}

		if ( is_scalar( $value ) ) {
			return self::cleanSnippet( (string) $value );
		}

		return null;
	}

	/**
	 * Remove search-highlight tags from snippets.
	 *
	 * @param string|null $snippet Snippet value.
	 * @return string|null
	 */
	private static function cleanSnippet( ?string $snippet ): ?string {
		if ( null === $snippet ) {
			return null;
		}

		$snippet = preg_replace( '#</?em\b[^>]*>#i', '', $snippet );
		$snippet = is_string( $snippet ) ? trim( $snippet ) : '';

		return '' === $snippet ? null : $snippet;
	}
}
