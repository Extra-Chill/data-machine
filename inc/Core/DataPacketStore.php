<?php
/**
 * Content-addressed DataPacket storage and hydration.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Core\FilesRepository\FilesystemHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DataPacketStore {

	public const SCHEMA_VERSION = 1;
	public const HASH_ALGORITHM = 'sha256';

	/**
	 * Store a packet and return its hydration reference.
	 *
	 * @param array $packet Packet payload.
	 * @return array|false Packet ref on success.
	 */
	public static function store( array $packet ): array|false {
		$canonical = self::canonicalize_packet( $packet );
		$json      = self::encode_canonical_json( $canonical );

		if ( false === $json ) {
			return false;
		}

		$hash      = hash( self::HASH_ALGORITHM, $json );
		$directory = ( new DirectoryManager() )->get_data_packet_store_directory( self::SCHEMA_VERSION, $hash );

		if ( ! ( new DirectoryManager() )->ensure_directory_exists( $directory ) ) {
			return false;
		}

		$file_path = $directory . '/' . $hash . '.json';
		$fs        = FilesystemHelper::get();

		if ( ! $fs ) {
			return false;
		}

		if ( ! file_exists( $file_path ) ) {
			$envelope    = array(
				'schema_version' => self::SCHEMA_VERSION,
				'hash_algorithm' => self::HASH_ALGORITHM,
				'content_hash'   => $hash,
				'packet'         => $canonical,
			);
			$stored_json = wp_json_encode( $envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( false === $stored_json || ! $fs->put_contents( $file_path, $stored_json ) ) {
				return false;
			}
		}

		return self::ref( $hash, $file_path );
	}

	/**
	 * Store many packets.
	 *
	 * @param array $packets Packet list.
	 * @return array|false Ref list on success.
	 */
	public static function store_many( array $packets ): array|false {
		$refs = array();
		foreach ( $packets as $packet ) {
			if ( ! is_array( $packet ) ) {
				$refs[] = $packet;
				continue;
			}

			if ( self::is_ref( $packet ) ) {
				$refs[] = $packet;
				continue;
			}

			$ref = self::store( $packet );
			if ( false === $ref ) {
				return false;
			}
			$refs[] = $ref;
		}

		return $refs;
	}

	/**
	 * Hydrate a packet ref or return the given packet unchanged.
	 *
	 * @param array $packet_or_ref Packet payload or ref.
	 * @return array|null Hydrated packet, original packet, or null on failure.
	 */
	public static function hydrate( array $packet_or_ref ): ?array {
		if ( ! self::is_ref( $packet_or_ref ) ) {
			return $packet_or_ref;
		}

		$file_path = self::file_path_from_ref( $packet_or_ref );
		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return null;
		}

		$fs   = FilesystemHelper::get();
		$json = $fs ? $fs->get_contents( $file_path ) : false;
		if ( false === $json ) {
			return null;
		}

		$envelope = json_decode( $json, true );
		if ( ! is_array( $envelope ) || ! is_array( $envelope['packet'] ?? null ) ) {
			return null;
		}

		$packet = $envelope['packet'];
		$hash   = self::content_hash( $packet );
		if ( ! is_string( $hash ) || (string) ( $packet_or_ref['content_hash'] ?? '' ) !== $hash ) {
			return null;
		}

		return $packet;
	}

	/**
	 * Hydrate a packet list containing packets and/or refs.
	 *
	 * @param array $packets Packet/ref list.
	 * @return array Hydrated packets. Failed refs are omitted.
	 */
	public static function hydrate_many( array $packets ): array {
		$hydrated = array();
		foreach ( $packets as $packet ) {
			if ( ! is_array( $packet ) ) {
				$hydrated[] = $packet;
				continue;
			}

			$value = self::hydrate( $packet );
			if ( null !== $value ) {
				$hydrated[] = $value;
			}
		}

		return $hydrated;
	}

	/**
	 * Replace known packet collections in a value with refs.
	 *
	 * @param mixed $value Arbitrary value.
	 * @return mixed Value with packet collections referenced where possible.
	 */
	public static function reference_packet_collections_in_value( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( array( 'data_packets', 'packets' ) as $key ) {
			if ( isset( $value[ $key ] ) && is_array( $value[ $key ] ) ) {
				$refs = self::store_many( $value[ $key ] );
				if ( false !== $refs ) {
					$value[ $key ] = $refs;
				}
			}
		}

		return $value;
	}

	/**
	 * Hydrate known packet collections in a value.
	 *
	 * @param mixed $value Arbitrary value.
	 * @return mixed Value with packet collections hydrated.
	 */
	public static function hydrate_packet_collections_in_value( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( array( 'data_packets', 'packets' ) as $key ) {
			if ( isset( $value[ $key ] ) && is_array( $value[ $key ] ) ) {
				$value[ $key ] = self::hydrate_many( $value[ $key ] );
			}
		}

		return $value;
	}

	/**
	 * Determine whether an array is a packet ref.
	 *
	 * @param array $value Candidate value.
	 * @return bool Whether the value is a ref.
	 */
	public static function is_ref( array $value ): bool {
		return ! empty( $value['is_data_packet_ref'] )
			&& self::SCHEMA_VERSION === (int) ( $value['schema_version'] ?? 0 )
			&& self::HASH_ALGORITHM === (string) ( $value['hash_algorithm'] ?? '' )
			&& is_string( $value['content_hash'] ?? null )
			&& 64 === strlen( (string) $value['content_hash'] );
	}

	/**
	 * Compute the stable content hash for a packet.
	 *
	 * @param array $packet Packet payload.
	 * @return string|false Hash on success.
	 */
	public static function content_hash( array $packet ): string|false {
		$json = self::encode_canonical_json( self::canonicalize_packet( $packet ) );
		return false === $json ? false : hash( self::HASH_ALGORITHM, $json );
	}

	/**
	 * Create a ref array.
	 *
	 * @param string $hash Content hash.
	 * @param string $file_path Storage path.
	 * @return array Ref.
	 */
	private static function ref( string $hash, string $file_path ): array {
		return array(
			'is_data_packet_ref' => true,
			'schema_version'     => self::SCHEMA_VERSION,
			'hash_algorithm'     => self::HASH_ALGORITHM,
			'content_hash'       => $hash,
			'file_path'          => $file_path,
		);
	}

	/**
	 * Resolve a ref to an on-disk path.
	 *
	 * @param array $ref Packet ref.
	 * @return string File path.
	 */
	private static function file_path_from_ref( array $ref ): string {
		$file_path = (string) ( $ref['file_path'] ?? '' );
		if ( '' !== $file_path ) {
			return $file_path;
		}

		$hash = (string) ( $ref['content_hash'] ?? '' );
		if ( '' === $hash ) {
			return '';
		}

		$directory = ( new DirectoryManager() )->get_data_packet_store_directory( self::SCHEMA_VERSION, $hash );
		return $directory . '/' . $hash . '.json';
	}

	/**
	 * Normalize packet content before hashing/storing.
	 *
	 * The legacy DataPacket value object injects a top-level runtime timestamp;
	 * content addressing excludes it so identical packet content hashes the same.
	 *
	 * @param array $packet Packet payload.
	 * @return array Canonical packet.
	 */
	private static function canonicalize_packet( array $packet ): array {
		unset( $packet['timestamp'] );
		self::ksort_recursive( $packet );
		return $packet;
	}

	/**
	 * Recursively sort associative keys for stable JSON hashing.
	 *
	 * @param mixed $value Value to sort.
	 * @return void
	 */
	private static function ksort_recursive( mixed &$value ): void {
		if ( ! is_array( $value ) ) {
			return;
		}

		foreach ( $value as &$child ) {
			self::ksort_recursive( $child );
		}
		unset( $child );

		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}
	}

	/**
	 * Encode canonical JSON for hashing.
	 *
	 * @param array $value Canonical value.
	 * @return string|false JSON string.
	 */
	private static function encode_canonical_json( array $value ): string|false {
		return wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
}
