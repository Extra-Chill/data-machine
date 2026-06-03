<?php
/**
 * Pure-PHP smoke test for generic corpus system tasks (#2488).
 */

declare( strict_types=1 );

namespace DataMachine\Core\Database\Jobs {
	final class Jobs {
		public function retrieve_engine_data( int $job_id ): array {
			return $GLOBALS['datamachine_corpus_task_jobs'][ $job_id ] ?? array();
		}

		public function store_engine_data( int $job_id, array $engine_data ): void {
			$GLOBALS['datamachine_corpus_task_jobs'][ $job_id ] = $engine_data;
		}

		public function complete_job( int $job_id, string $status ): void {
			$GLOBALS['datamachine_corpus_task_status'][ $job_id ] = $status;
		}
	}
}

namespace {
	const ABSPATH = __DIR__;

	if ( ! function_exists( 'current_time' ) ) {
		function current_time( string $type ): string {
			unset( $type );
			return '2026-06-03 00:00:00';
		}
	}

	require_once __DIR__ . '/../inc/Engine/AI/System/Tasks/SystemTask.php';
	require_once __DIR__ . '/../inc/Core/JobStatus.php';
	require_once __DIR__ . '/../inc/Engine/AI/System/Tasks/Corpus/CorpusPacketTask.php';
	require_once __DIR__ . '/../inc/Engine/AI/System/Tasks/Corpus/CorpusIndexTask.php';
	require_once __DIR__ . '/../inc/Engine/AI/System/Tasks/Corpus/CorpusRefreshTask.php';
	require_once __DIR__ . '/../inc/Engine/AI/System/Tasks/Corpus/CorpusEmbedChunksTask.php';
	require_once __DIR__ . '/../inc/Engine/AI/System/Tasks/Corpus/CorpusRetrieveEvalTask.php';

	use DataMachine\Engine\AI\System\Tasks\Corpus\CorpusEmbedChunksTask;
	use DataMachine\Engine\AI\System\Tasks\Corpus\CorpusIndexTask;
	use DataMachine\Engine\AI\System\Tasks\Corpus\CorpusRefreshTask;
	use DataMachine\Engine\AI\System\Tasks\Corpus\CorpusRetrieveEvalTask;

	$failures = array();
	$passes   = 0;

	$assert = static function ( string $label, bool $condition ) use ( &$failures, &$passes ): void {
		if ( $condition ) {
			++$passes;
			echo "PASS: {$label}\n";
			return;
		}

		$failures[] = $label;
		echo "FAIL: {$label}\n";
	};

	$GLOBALS['datamachine_corpus_task_jobs']   = array();
	$GLOBALS['datamachine_corpus_task_status'] = array();

	$index = new CorpusIndexTask();
	$index->executeTask(
		101,
		array(
			'corpus_id' => 'docs',
			'documents' => array(
				array( 'document_id' => 'doc-1', 'title' => 'One' ),
				array( 'id' => 'doc-2', 'title' => 'Two' ),
			),
		)
	);
	$index_data = $GLOBALS['datamachine_corpus_task_jobs'][101] ?? array();
	$assert( 'corpus_index emits one packet per document', 2 === count( $index_data['output_data_packets'] ?? array() ) );
	$assert( 'corpus_index packet type is corpus_document', 'corpus_document' === ( $index_data['output_data_packets'][0]['type'] ?? '' ) );
	$assert( 'corpus_index summary exposes corpus id', 'docs' === ( $index_data['corpus_id'] ?? '' ) );
	$assert( 'corpus_index summary exposes document identifiers', array( 'doc-1', 'doc-2' ) === ( $index_data['document_ids'] ?? array() ) );
	$assert( 'corpus_index completes job', 'completed' === ( $GLOBALS['datamachine_corpus_task_status'][101] ?? '' ) );

	$embed = new CorpusEmbedChunksTask();
	$embed->executeTask(
		102,
		array(
			'corpus' => array( 'ref' => 'example/ref' ),
			'chunks' => array(
				array( 'document_id' => 'doc-1', 'chunk_id' => 'chunk-1', 'text' => 'Alpha' ),
			),
		)
	);
	$embed_data = $GLOBALS['datamachine_corpus_task_jobs'][102] ?? array();
	$assert( 'corpus_embed_chunks emits chunk packets', 'corpus_chunk' === ( $embed_data['output_data_packets'][0]['type'] ?? '' ) );
	$assert( 'corpus_embed_chunks summary exposes chunk identifiers', array( 'chunk-1' ) === ( $embed_data['chunk_ids'] ?? array() ) );
	$assert( 'corpus_embed_chunks metadata carries corpus ref', 'example/ref' === ( $embed_data['output_data_packets'][0]['metadata']['corpus_ref'] ?? '' ) );

	$refresh = new CorpusRefreshTask();
	$refresh->executeTask( 103, array( 'corpus_id' => 'docs' ) );
	$refresh_data = $GLOBALS['datamachine_corpus_task_jobs'][103] ?? array();
	$assert( 'empty corpus_refresh completes with no-items status marker', 'completed_no_items' === ( $refresh_data['job_status'] ?? '' ) );

	$eval = new CorpusRetrieveEvalTask();
	$eval->executeTask( 104, array( 'query' => array( 'id' => 'q-1', 'text' => 'What changed?' ) ) );
	$eval_data = $GLOBALS['datamachine_corpus_task_jobs'][104] ?? array();
	$assert( 'corpus_retrieve_eval emits eval packets', 'corpus_retrieval_eval' === ( $eval_data['output_data_packets'][0]['type'] ?? '' ) );
	$assert( 'corpus_retrieve_eval does not treat query IDs as document IDs', array() === ( $eval_data['document_ids'] ?? array() ) );
	$assert( 'corpus_retrieve_eval does not treat query IDs as chunk IDs', array() === ( $eval_data['chunk_ids'] ?? array() ) );

	$provider_source = (string) file_get_contents( __DIR__ . '/../inc/Engine/AI/System/SystemAgentServiceProvider.php' );
	$assert( 'provider registers corpus_index', str_contains( $provider_source, "\$tasks['corpus_index']" ) );
	$assert( 'provider registers corpus_refresh', str_contains( $provider_source, "\$tasks['corpus_refresh']" ) );
	$assert( 'provider registers corpus_embed_chunks', str_contains( $provider_source, "\$tasks['corpus_embed_chunks']" ) );
	$assert( 'provider registers corpus_retrieve_eval', str_contains( $provider_source, "\$tasks['corpus_retrieve_eval']" ) );

	if ( ! empty( $failures ) ) {
		echo "\nFAILED: " . count( $failures ) . " corpus system task assertion(s) failed.\n";
		exit( 1 );
	}

	echo "\nAll {$passes} corpus system task assertions passed.\n";
}
