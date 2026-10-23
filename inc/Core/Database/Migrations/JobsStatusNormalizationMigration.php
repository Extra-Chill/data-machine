<?php
/**
 * Registry adapter for the existing job-status normalization migration.
 *
 * @package DataMachine\Core\Database\Migrations
 */

namespace DataMachine\Core\Database\Migrations;

use DataMachine\Core\Database\Jobs\JobStatusMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces JobStatusMigration on the shared runner without rewriting it.
 */
class JobsStatusNormalizationMigration extends Migration {

	public function id(): string {
		return 'jobs.status-normalization';
	}

	public function description(): string {
		return 'Normalize legacy compound job statuses onto the canonical vocabulary.';
	}

	public function inspect(): array {
		return $this->withId( ( new JobStatusMigration() )->inspect() );
	}

	public function apply( int $limit ): array {
		return $this->withId( ( new JobStatusMigration() )->apply( $limit ) );
	}

	public function isComplete(): bool {
		return JobStatusMigration::isComplete();
	}

	/**
	 * @param array<string,mixed> $result Inner migration result.
	 * @return array<string,mixed>
	 */
	private function withId( array $result ): array {
		$result['id']      = $this->id();
		$result['success'] = $result['success'] ?? true;

		return $result;
	}
}
