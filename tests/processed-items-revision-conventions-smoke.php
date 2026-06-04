<?php
/**
 * Pure-PHP smoke test for processed-item revision-key documentation boundaries.
 *
 * Run with: php tests/processed-items-revision-conventions-smoke.php
 *
 * @package DataMachine\Tests
 */

$docs = file_get_contents( __DIR__ . '/../docs/api/endpoints/processed-items.md' );

function datamachine_processed_items_revision_assert( bool $cond, string $msg ): void {
	if ( $cond ) {
		echo "  [PASS] {$msg}\n";
		return;
	}

	echo "  [FAIL] {$msg}\n";
	exit( 1 );
}

echo "=== processed-items-revision-conventions-smoke ===\n";

datamachine_processed_items_revision_assert( str_contains( $docs, '## Revision-Key Conventions' ), 'processed-items docs include revision-key conventions' );
datamachine_processed_items_revision_assert( str_contains( $docs, '`source_type`' ), 'docs keep source type as caller-owned namespace' );
datamachine_processed_items_revision_assert( str_contains( $docs, '`item_identifier`' ), 'docs keep item identifier as caller-owned revision key' );
datamachine_processed_items_revision_assert( str_contains( $docs, '`selected`, `skipped`, `processed`, `failed`, and `retried`' ), 'docs cover generic batch counters' );
datamachine_processed_items_revision_assert( ! str_contains( $docs, 'CorpusRefreshConventions' ), 'docs do not advertise a Data Machine corpus helper' );
datamachine_processed_items_revision_assert( ! str_contains( $docs, 'Intelligence' ), 'docs do not reference Intelligence product semantics' );

echo "\n=== processed-items-revision-conventions-smoke: ALL PASS ===\n";
