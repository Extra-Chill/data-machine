<?php
/**
 * Job-table backfill with a persisted job_id cursor.
 *
 * @package DataMachine\Core\Database\Migrations
 */

namespace DataMachine\Core\Database\Migrations;

use DataMachine\Core\Database\Jobs\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Shared cursor/batch machinery for jobs-table data backfills.
 */
abstract class CursorJobBackfill extends Migration {

	public const MAX_BATCH = 1000;

	/**
	 * Option that stores cursor state for this backfill.
	 */
	abstract protected function stateOption(): string;

	/**
	 * Legacy completion flag written by the old create_table() path, if any.
	 */
	abstract protected function legacyCompleteOption(): string;

	/**
	 * @param int $cursor Exclusive lower bound on job_id.
	 * @param int $limit  Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	abstract protected function fetchBatch( int $cursor, int $limit ): array;

	/**
	 * @param array<string,mixed> $row Candidate row.
	 */
	abstract protected function processRow( array $row ): void;

	abstract protected function countRemaining(): int;

	public function inspect(): array {
		if ( $this->legacyComplete() ) {
			return $this->inspectPayload(
				array(
					'cursor'   => 0,
					'migrated' => 0,
					'scanned'  => 0,
				),
				0,
				true
			);
		}

		$state     = $this->state();
		$remaining = $this->countRemaining();

		return $this->inspectPayload( $state, $remaining, 0 === $remaining );
	}

	public function apply( int $limit ): array {
		if ( $this->legacyComplete() ) {
			return $this->inspect();
		}

		$limit             = max( 1, min( self::MAX_BATCH, $limit ) );
		$state             = $this->state();
		$state['complete'] = false;
		$rows              = $this->fetchBatch( (int) $state['cursor'], $limit );

		foreach ( $rows as $row ) {
			$job_id           = (int) ( $row['job_id'] ?? 0 );
			$state['cursor']  = max( (int) $state['cursor'], $job_id );
			$state['scanned'] = (int) $state['scanned'] + 1;
			$this->processRow( $row );
			$state['migrated'] = (int) $state['migrated'] + 1;
		}

		$state['updated_at'] = gmdate( 'c' );
		update_option( $this->stateOption(), $state, false );

		if ( count( $rows ) < $limit ) {
			$remaining = $this->countRemaining();
			if ( 0 === $remaining ) {
				$state['complete'] = true;
				update_option( $this->stateOption(), $state, false );
				update_option( $this->legacyCompleteOption(), 1, false );
			} elseif ( $remaining > 0 && (int) $state['cursor'] > 0 ) {
				$state['cursor'] = 0;
				update_option( $this->stateOption(), $state, false );
			}
		}

		$payload               = $this->inspectPayload( $state, $this->countRemaining(), ! empty( $state['complete'] ) );
		$payload['batch_size'] = count( $rows );

		return $payload;
	}

	public function isComplete(): bool {
		if ( $this->legacyComplete() ) {
			return true;
		}

		$state = $this->state();

		return ! empty( $state['complete'] );
	}

	protected function table(): string {
		global $wpdb;

		return $wpdb->prefix . Jobs::TABLE_NAME;
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function state(): array {
		$stored = get_option( $this->stateOption(), array() );

		return array_merge(
			array(
				'cursor'     => 0,
				'scanned'    => 0,
				'migrated'   => 0,
				'updated_at' => null,
				'complete'   => false,
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	protected function legacyComplete(): bool {
		$legacy = $this->legacyCompleteOption();

		return '' !== $legacy && (bool) get_option( $legacy );
	}

	/**
	 * @param array<string,mixed> $state Current cursor state.
	 * @return array<string,mixed>
	 */
	private function inspectPayload( array $state, int $remaining, bool $complete ): array {
		return array_merge(
			$state,
			array(
				'success'    => true,
				'id'         => $this->id(),
				'table'      => $this->table(),
				'remaining'  => $remaining,
				'complete'   => $complete,
				'status'     => $complete ? 'complete' : ( (int) $state['cursor'] > 0 ? 'in_progress' : 'migration_required' ),
				'batch_size' => 0,
			)
		);
	}
}
