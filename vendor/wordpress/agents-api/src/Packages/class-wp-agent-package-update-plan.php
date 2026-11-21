<?php
/**
 * WP_Agent_Package_Update_Plan value object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Package_Update_Plan' ) ) {
	/**
	 * Bucketed, read-only package artifact update plan.
	 */
	final class WP_Agent_Package_Update_Plan {

		private const BUCKETS = array( 'auto_apply', 'needs_approval', 'warnings', 'no_op' );

		/**
		 * @var array<string,array<int,array<string,mixed>>>
		 */
		private array $buckets;

		/**
		 * @var array<string,mixed>
		 */
		private array $meta;

		/**
		 * Constructor.
		 *
		 * @param array<string,array<int,array<string,mixed>>> $buckets Plan buckets.
		 * @param array<string,mixed>                          $meta Optional metadata.
		 */
		public function __construct( array $buckets = array(), array $meta = array() ) {
			$this->buckets = $this->prepare_buckets( $buckets );
			$this->meta    = $meta;
		}

		/**
		 * Retrieves all buckets.
		 *
		 * @return array<string,array<int,array<string,mixed>>>
		 */
		public function get_buckets(): array {
			return $this->buckets;
		}

		/**
		 * Retrieves a bucket by name.
		 *
		 * @param string $bucket Bucket name.
		 * @return array<int,array<string,mixed>>
		 */
		public function get_bucket( string $bucket ): array {
			return $this->buckets[ $bucket ] ?? array();
		}

		/**
		 * Retrieves metadata.
		 *
		 * @return array<string,mixed>
		 */
		public function get_meta(): array {
			return $this->meta;
		}

		/**
		 * Checks whether the plan needs manual review.
		 *
		 * @return bool
		 */
		public function needs_approval(): bool {
			return ! empty( $this->buckets['needs_approval'] );
		}

		/**
		 * Exports the plan.
		 *
		 * @return array<string,mixed>
		 */
		public function to_array(): array {
			return array(
				'buckets' => $this->buckets,
				'meta'    => $this->meta,
			);
		}

		/**
		 * @param array<string,array<int,array<string,mixed>>> $buckets Raw buckets.
		 * @return array<string,array<int,array<string,mixed>>>
		 */
		private function prepare_buckets( array $buckets ): array {
			$prepared = array_fill_keys( self::BUCKETS, array() );

			foreach ( self::BUCKETS as $bucket ) {
				if ( ! is_array( $buckets[ $bucket ] ?? null ) ) {
					continue;
				}

				foreach ( $buckets[ $bucket ] as $entry ) {
					$prepared[ $bucket ][] = $entry;
				}
			}

			return $prepared;
		}
	}
}
