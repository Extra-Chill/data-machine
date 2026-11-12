<?php
/**
 * One bounded, resumable database change.
 *
 * @package DataMachine\Core\Database\Migrations
 */

namespace DataMachine\Core\Database\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * A migration is inspectable without doing work and applyable in batches.
 *
 * Web requests must never call apply(). MigrationRunner enforces that.
 */
abstract class Migration {

	/**
	 * Stable identifier, e.g. jobs.status-reason-backfill.
	 */
	abstract public function id(): string;

	/**
	 * One-line operator description.
	 */
	abstract public function description(): string;

	/**
	 * Inspect durable progress without mutating rows.
	 *
	 * @return array<string,mixed>
	 */
	abstract public function inspect(): array;

	/**
	 * Process one bounded batch.
	 *
	 * @param int $limit Maximum candidate rows this invocation may touch.
	 * @return array<string,mixed>
	 */
	abstract public function apply( int $limit ): array;

	/**
	 * Whether this site has nothing left to do.
	 */
	abstract public function isComplete(): bool;
}
