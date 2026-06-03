<?php
/**
 * Pure-PHP smoke test for corpus refresh processed-item conventions.
 *
 * Run with: php tests/corpus-refresh-conventions-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../inc/Core/Corpus/CorpusRefreshConventions.php';

use DataMachine\Core\Corpus\CorpusRefreshConventions;

function datamachine_corpus_conventions_assert( bool $cond, string $msg ): void {
	if ( $cond ) {
		echo "  [PASS] {$msg}\n";
		return;
	}

	echo "  [FAIL] {$msg}\n";
	exit( 1 );
}

final class CorpusRefreshLedgerForSmoke {
	/** @var array<string,bool> */
	private array $processed = array();

	public function mark_processed( string $source_type, string $item_identifier ): void {
		$this->processed[ $this->key( $source_type, $item_identifier ) ] = true;
	}

	public function should_skip( string $source_type, string $item_identifier ): bool {
		return isset( $this->processed[ $this->key( $source_type, $item_identifier ) ] );
	}

	private function key( string $source_type, string $item_identifier ): string {
		return $source_type . '|' . $item_identifier;
	}
}

echo "=== corpus-refresh-conventions-smoke ===\n";

$doc_key       = CorpusRefreshConventions::document_key( 'Core Brain', 'Doc 42', 'SHA256:ABC123' );
$same_doc_key  = CorpusRefreshConventions::document_key( 'core brain', 'doc 42', 'sha256:abc123' );
$new_doc_key   = CorpusRefreshConventions::document_key( 'Core Brain', 'Doc 42', 'SHA256:DEF456' );
$chunk_key     = CorpusRefreshConventions::chunk_key( 'Core Brain', 'chunk/7', 'chunkhash' );
$embedding_key = CorpusRefreshConventions::embedding_key( 'Core Brain', 'OpenAI', 'text-embedding-3-large', 'chunkhash' );

datamachine_corpus_conventions_assert( $doc_key === $same_doc_key, 'document key is stable across casing' );
datamachine_corpus_conventions_assert( $doc_key !== $new_doc_key, 'document key changes when source hash changes' );
datamachine_corpus_conventions_assert( str_starts_with( $doc_key, 'corpus|doc|' ), 'document key uses corpus doc namespace' );
datamachine_corpus_conventions_assert( str_contains( $chunk_key, 'chunk=chunk/7' ), 'chunk key includes chunk id' );
datamachine_corpus_conventions_assert( str_contains( $embedding_key, 'provider=openai' ), 'embedding key includes provider' );
datamachine_corpus_conventions_assert( str_contains( $embedding_key, 'model=text-embedding-3-large' ), 'embedding key includes embedding model' );

$ledger = new CorpusRefreshLedgerForSmoke();
$ledger->mark_processed( CorpusRefreshConventions::SOURCE_DOCUMENT, $doc_key );
datamachine_corpus_conventions_assert( $ledger->should_skip( CorpusRefreshConventions::SOURCE_DOCUMENT, $same_doc_key ), 'unchanged document revision skips on later refresh run' );
datamachine_corpus_conventions_assert( ! $ledger->should_skip( CorpusRefreshConventions::SOURCE_DOCUMENT, $new_doc_key ), 'changed document source hash remains eligible on later refresh run' );

$metadata = CorpusRefreshConventions::batch_metadata(
	array(
		'corpus_id' => 'core-brain',
		'batch_id'  => 'refresh-2026-06-03-1',
		'selected'  => 10,
		'skipped'   => 4,
		'processed' => 5,
		'failed'    => 1,
		'retried'   => 2,
	)
);

datamachine_corpus_conventions_assert( 'corpus_refresh' === $metadata['workload'], 'batch metadata identifies corpus refresh workload' );
datamachine_corpus_conventions_assert( 'core-brain' === $metadata['corpus_id'], 'batch metadata carries corpus id' );
datamachine_corpus_conventions_assert( 10 === $metadata['counts']['selected'], 'batch metadata carries selected count' );
datamachine_corpus_conventions_assert( 4 === $metadata['counts']['skipped'], 'batch metadata carries skipped count' );
datamachine_corpus_conventions_assert( 5 === $metadata['counts']['processed'], 'batch metadata carries processed count' );
datamachine_corpus_conventions_assert( 1 === $metadata['counts']['failed'], 'batch metadata carries failed count' );
datamachine_corpus_conventions_assert( 2 === $metadata['counts']['retried'], 'batch metadata carries retried count' );

$source = file_get_contents( __DIR__ . '/../inc/Core/Corpus/CorpusRefreshConventions.php' );
datamachine_corpus_conventions_assert( ! str_contains( $source, 'Intelligence\\' ), 'conventions do not depend on Intelligence classes' );

echo "\n=== corpus-refresh-conventions-smoke: ALL PASS ===\n";
