<?php
/**
 * Read-only agent bundle upgrade plan value object.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable machine-readable upgrade plan grouped by resolution bucket.
 */
final class AgentBundleUpgradePlan {

	/** @var array<string,array<int,array<string,mixed>>> */
	private array $buckets;
	private array $metadata;

	/**
	 * @param array<string,array<int,array<string,mixed>>> $buckets Plan buckets.
	 * @param array<string,mixed>                          $metadata Plan metadata.
	 */
	public function __construct( array $buckets, array $metadata = array() ) {
		$this->buckets  = array(
			'auto_apply'     => self::sort_entries( $buckets['auto_apply'] ?? array() ),
			'needs_approval' => self::sort_entries( $buckets['needs_approval'] ?? array() ),
			'warnings'       => self::sort_entries( $buckets['warnings'] ?? array() ),
			'no_op'          => self::sort_entries( $buckets['no_op'] ?? array() ),
		);
		$this->metadata = $metadata;
	}

	/** @return array<int,array<string,mixed>> */
	public function bucket( string $bucket ): array {
		return $this->buckets[ $bucket ] ?? array();
	}

	public function has_pending_approval(): bool {
		return ! empty( $this->buckets['needs_approval'] );
	}

	public function to_array(): array {
		return array_merge( $this->metadata, $this->buckets, array( 'counts' => $this->counts() ) );
	}

	/** @return array<string,int> */
	private function counts(): array {
		return array(
			'auto_apply'     => count( $this->buckets['auto_apply'] ),
			'needs_approval' => count( $this->buckets['needs_approval'] ),
			'warnings'       => count( $this->buckets['warnings'] ),
			'no_op'          => count( $this->buckets['no_op'] ),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $entries Entries to sort.
	 * @return array<int,array<string,mixed>>
	 */
	private static function sort_entries( array $entries ): array {
		usort(
			$entries,
			static function ( array $a, array $b ): int {
				return strcmp( (string) ( $a['artifact_key'] ?? '' ), (string) ( $b['artifact_key'] ?? '' ) );
			}
		);

		return array_values( $entries );
	}
}
