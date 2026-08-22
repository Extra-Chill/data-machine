<?php
/**
 * Smoke test for Blocks Engine RichText attributes and conservative repair.
 *
 * Run standalone for the CI source contract, and through WP-CLI for the real
 * transformer, WordPress parser, serializer, renderer, and registered schemas:
 * wp --path=/path/to/wordpress --skip-plugins --skip-themes eval-file tests/blocks-engine-rich-text-attributes-smoke.php
 *
 * @package DataMachine\Tests
 */

$root = dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'parse_blocks' ) ) {
	$repair_source  = file_get_contents( $root . '/inc/Core/Content/SourceDerivedBlockAttributeRepair.php' );
	$command_source = file_get_contents( $root . '/inc/Cli/Commands/BlocksCommand.php' );
	$checks         = array(
		'allowlists only three proven blocks' => false !== strpos( $repair_source, "'core/heading'" ) && false !== strpos( $repair_source, "'core/paragraph'" ) && false !== strpos( $repair_source, "'core/list-item'" ),
		'requires exact rich-text schema'     => 2 === substr_count( $repair_source, "'rich-text'" ),
		'requires exact canonical root markup' => false !== strpos( $repair_source, "\$root_content !== \$value" ) && false === strpos( $repair_source, "strpos( \$inner_html, \$value )" ),
		'uses optimistic conflict guard'      => false !== strpos( $repair_source, 'datamachine_blocks_repair_conflict' ),
		'uses an exact row lock'               => false !== strpos( $repair_source, 'SELECT post_content FROM %i WHERE ID = %d FOR UPDATE' ),
		'uses integrity round-trip guard'     => false !== strpos( $repair_source, 'datamachine_blocks_repair_integrity_failed' ),
		'bulk query is bounded'               => false !== strpos( $command_source, "'posts_per_page'" ) && false !== strpos( $command_source, "'no_found_rows'" ),
		'bulk query disables cache priming'   => false !== strpos( $command_source, "'cache_results'" ) && false !== strpos( $command_source, "'update_post_meta_cache'" ) && false !== strpos( $command_source, "'update_post_term_cache'" ),
		'JSON emits one findings envelope'    => false !== strpos( $command_source, "array( 'findings' => \$findings, 'summary' => \$summary )" ),
		'structured findings expose no preview' => false === strpos( $command_source, "'preview'" ) && false === strpos( $repair_source, "'preview'" ),
		'apply errors halt after output'       => strpos( $command_source, 'WP_CLI::halt( 1 )' ) > strpos( $command_source, "array( 'findings' => \$findings, 'summary' => \$summary )" ),
	);

	foreach ( $checks as $name => $passed ) {
		echo sprintf( "%s: %s\n", $passed ? 'PASS' : 'FAIL', $name );
		if ( ! $passed ) {
			exit( 1 );
		}
	}
	return;
}

require_once $root . '/vendor/autoload.php';
require_once $root . '/inc/Core/Content/SourceDerivedBlockAttributeRepair.php';
require_once $root . '/inc/Core/Content/ContentFormat.php';
require_once $root . '/inc/Cli/BaseCommand.php';
require_once $root . '/inc/Cli/Commands/BlocksCommand.php';

use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DataMachine\Cli\Commands\BlocksCommand;
use DataMachine\Core\Content\ContentFormat;
use DataMachine\Core\Content\SourceDerivedBlockAttributeRepair;

$GLOBALS['blocks_engine_rich_text_failed'] = 0;
$GLOBALS['blocks_engine_rich_text_total']  = 0;

function assert_blocks_engine_rich_text( string $name, bool $condition ): void {
	++$GLOBALS['blocks_engine_rich_text_total'];
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		return;
	}

	echo "  FAIL: {$name}\n";
	++$GLOBALS['blocks_engine_rich_text_failed'];
}

$html          = '<h3>Heading <em>rich text</em></h3><p>Paragraph <strong>rich text</strong></p>';
$direct_result = blocks_engine_php_transformer_convert_format( $html, 'html', 'blocks' );
$direct_saved  = $direct_result['serialized_blocks'] ?? '';
$saved         = ContentFormat::convert( $html, 'html', 'blocks' );
$blocks        = is_string( $saved ) ? parse_blocks( $saved ) : array();

assert_blocks_engine_rich_text( 'real transformer reports success', 'success' === ( $direct_result['status'] ?? '' ) );
assert_blocks_engine_rich_text( 'Data Machine ContentFormat uses real transformer output', $direct_saved === $saved );
assert_blocks_engine_rich_text( 'heading RichText remains in saved HTML', '<h3 class="wp-block-heading">Heading <em>rich text</em></h3>' === ( $blocks[0]['innerHTML'] ?? '' ) );
assert_blocks_engine_rich_text( 'paragraph RichText remains in saved HTML', '<p>Paragraph <strong>rich text</strong></p>' === ( $blocks[1]['innerHTML'] ?? '' ) );
assert_blocks_engine_rich_text( 'heading RichText is absent from delimiter attrs', ! array_key_exists( 'content', $blocks[0]['attrs'] ?? array() ) );
assert_blocks_engine_rich_text( 'paragraph RichText is absent from delimiter attrs', ! array_key_exists( 'content', $blocks[1]['attrs'] ?? array() ) );
assert_blocks_engine_rich_text( 'legitimate heading level remains', 3 === ( $blocks[0]['attrs']['level'] ?? null ) );

$runtime    = new Runtime();
$ordinary   = $blocks[0];
$ordinary['attrs']['content']    = 'duplicate';
$ordinary['attrs']['className']  = 'kept-class';
$ordinary['attrs']['customFlag'] = 'kept-value';
$canonical  = parse_blocks( $runtime->serializeBlocks( array( $ordinary ) ) )[0] ?? array();

assert_blocks_engine_rich_text( 'runtime removes source-derived content', ! array_key_exists( 'content', $canonical['attrs'] ?? array() ) );
assert_blocks_engine_rich_text( 'runtime preserves ordinary className', 'kept-class' === ( $canonical['attrs']['className'] ?? null ) );
assert_blocks_engine_rich_text( 'runtime preserves unknown ordinary attrs', 'kept-value' === ( $canonical['attrs']['customFlag'] ?? null ) );

$incorrect_usage = array();
$notice_listener = static function ( string $function_name, string $message ) use ( &$incorrect_usage ): void {
	if ( 'rest_validate_value_from_schema' === $function_name ) {
		$incorrect_usage[] = $message;
	}
};
add_action( 'doing_it_wrong_run', $notice_listener, 10, 2 );
$rendered = $runtime->renderBlocks( parse_blocks( (string) $saved ) );
remove_action( 'doing_it_wrong_run', $notice_listener, 10 );

assert_blocks_engine_rich_text( 'real WordPress renders transformed RichText', false !== strpos( $rendered, 'Heading <em>rich text</em>' ) );
assert_blocks_engine_rich_text( 'render emits no REST schema incorrect-usage notice', array() === $incorrect_usage );

$fixture = '<aside data-free="x&amp;y">Loose &amp; free</aside>'
	. '<!-- wp:group {"className":"outer","customFlag":"stay"} --><div class="wp-block-group">'
	. '<!-- wp:heading {"content":"Heading &amp; <em>odd</em>","level":4,"anchor":"keep"} --><h4 class="wp-block-heading" id="keep">Heading &amp; <em>odd</em></h4><!-- /wp:heading -->'
	. '<!-- wp:paragraph {"content":"Not represented","className":"skip"} --><p class="skip">Different saved text</p><!-- /wp:paragraph -->'
	. '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item {"content":"Nested &amp; odd","className":"nested"} --><li class="nested">Nested &amp; odd</li><!-- /wp:list-item --></ul><!-- /wp:list -->'
	. '<!-- wp:image {"id":9,"url":"https://example.com/keep.jpg","className":"image-keep"} --><figure class="wp-block-image image-keep"><img src="https://example.com/keep.jpg" alt="" class="wp-image-9"/></figure><!-- /wp:image -->'
	. '</div><!-- /wp:group --><span data-tail="1">Tail</span>';
$expected = '<aside data-free="x&amp;y">Loose &amp; free</aside>'
	. '<!-- wp:group {"className":"outer","customFlag":"stay"} --><div class="wp-block-group">'
	. '<!-- wp:heading {"level":4,"anchor":"keep"} --><h4 class="wp-block-heading" id="keep">Heading &amp; <em>odd</em></h4><!-- /wp:heading -->'
	. '<!-- wp:paragraph {"content":"Not represented","className":"skip"} --><p class="skip">Different saved text</p><!-- /wp:paragraph -->'
	. '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item {"className":"nested"} --><li class="nested">Nested &amp; odd</li><!-- /wp:list-item --></ul><!-- /wp:list -->'
	. '<!-- wp:image {"id":9,"url":"https://example.com/keep.jpg","className":"image-keep"} --><figure class="wp-block-image image-keep"><img src="https://example.com/keep.jpg" alt="" class="wp-image-9"/></figure><!-- /wp:image -->'
	. '</div><!-- /wp:group --><span data-tail="1">Tail</span>';
$repair  = new SourceDerivedBlockAttributeRepair();
$updates = array();
$dry_run = $repair->processPost(
	321,
	$fixture,
	false,
	static function () use ( &$updates ): int {
		$updates[] = 'unexpected';
		return 321;
	}
);

assert_blocks_engine_rich_text( 'whole-document repair output is exact', $expected === $dry_run['content'] );
assert_blocks_engine_rich_text( 'repair identifies only proven represented RichText', 2 === $dry_run['repairable_count'] && 1 === $dry_run['skipped_count'] );
assert_blocks_engine_rich_text( 'unrepresented content is skipped', 'content_not_exact_root_rich_text' === ( $dry_run['findings'][1]['reason'] ?? '' ) );
assert_blocks_engine_rich_text( 'dry run never invokes updater', array() === $updates && false === $dry_run['applied'] );
assert_blocks_engine_rich_text( 'findings redact full values', ! array_key_exists( 'value', $dry_run['findings'][0] ) && 64 === strlen( $dry_run['findings'][0]['value_sha256'] ?? '' ) );
assert_blocks_engine_rich_text( 'findings expose no preview content', ! array_key_exists( 'preview', $dry_run['findings'][0] ) );

$applied = $repair->processPost(
	321,
	$fixture,
	true,
	static function ( int $post_id, string $content, string $inspected ) use ( &$updates ): int {
		$updates[] = compact( 'post_id', 'content', 'inspected' );
		return $post_id;
	}
);
assert_blocks_engine_rich_text( 'apply invokes updater once with exact expected document', 1 === count( $updates ) && $expected === $updates[0]['content'] && $fixture === $updates[0]['inspected'] && true === $applied['applied'] );

$database = new class( $fixture ) {
	public string $posts = 'wp_posts';
	public array $queries = array();
	public string $current;
	public bool $fail_commit = false;
	public function __construct( string $current ) {
		$this->current = $current;
	}
	public function query( string $query ) {
		$this->queries[] = $query;
		if ( 'COMMIT' === $query && $this->fail_commit ) {
			return false;
		}
		return 1;
	}
	public function prepare( string $query, ...$args ): string {
		return str_replace( array( '%i', '%d' ), array( $args[0], (string) $args[1] ), $query );
	}
	public function get_var( string $query ): string {
		$this->queries[] = $query;
		return $this->current;
	}
};
$atomic = $repair->updatePostAtomically( 321, $expected, $fixture, $database, static fn (): int => 321 );
assert_blocks_engine_rich_text( 'atomic update locks exact row before commit', 321 === $atomic && array( 'START TRANSACTION', 'SELECT post_content FROM wp_posts WHERE ID = 321 FOR UPDATE', 'COMMIT' ) === $database->queries );

$conflict_database = clone $database;
$conflict_database->queries = array();
$conflict_database->current = 'concurrent edit';
$conflict = $repair->updatePostAtomically(
	321,
	$expected,
	$fixture,
	$conflict_database,
	static function (): void {
		throw new RuntimeException( 'Conflict must abort before the writer.' );
	}
);
assert_blocks_engine_rich_text( 'atomic conflict rolls back with stable error', is_wp_error( $conflict ) && 'datamachine_blocks_repair_conflict' === $conflict->get_error_code() && 'ROLLBACK' === end( $conflict_database->queries ) );

$writer_failure_database = clone $database;
$writer_failure_database->queries = array();
$writer_failure = $repair->updatePostAtomically( 321, $expected, $fixture, $writer_failure_database, static fn (): bool => false );
assert_blocks_engine_rich_text( 'atomic writer failure rolls back without leaking transaction', is_wp_error( $writer_failure ) && 'datamachine_blocks_repair_update_failed' === $writer_failure->get_error_code() && 'ROLLBACK' === end( $writer_failure_database->queries ) );

$exception_database = clone $database;
$exception_database->queries = array();
$exception_result = $repair->updatePostAtomically(
	321,
	$expected,
	$fixture,
	$exception_database,
	static function (): void {
		throw new RuntimeException( 'writer failed' );
	}
);
assert_blocks_engine_rich_text( 'atomic writer exception rolls back without leaking transaction', is_wp_error( $exception_result ) && 'datamachine_blocks_repair_exception' === $exception_result->get_error_code() && 'ROLLBACK' === end( $exception_database->queries ) );

$commit_failure_database = clone $database;
$commit_failure_database->queries = array();
$commit_failure_database->fail_commit = true;
$commit_failure = $repair->updatePostAtomically( 321, $expected, $fixture, $commit_failure_database, static fn (): int => 321 );
assert_blocks_engine_rich_text( 'atomic commit failure attempts rollback', is_wp_error( $commit_failure ) && 'datamachine_blocks_repair_commit_failed' === $commit_failure->get_error_code() && array( 'COMMIT', 'ROLLBACK' ) === array_slice( $commit_failure_database->queries, -2 ) );

$false_update  = $repair->processPost( 321, $fixture, true, static fn (): bool => false );
$failed_update = $repair->processPost( 321, $fixture, true, static fn (): int => 0 );
$wrong_update  = $repair->processPost( 321, $fixture, true, static fn (): int => 999 );
assert_blocks_engine_rich_text( 'false and zero updater results are errors', false === $false_update['applied'] && false === $failed_update['applied'] && 'datamachine_blocks_repair_update_failed' === ( $false_update['error_code'] ?? '' ) && 'datamachine_blocks_repair_update_failed' === ( $failed_update['error_code'] ?? '' ) );
assert_blocks_engine_rich_text( 'unexpected updater post ID is an error', false === $wrong_update['applied'] && 'datamachine_blocks_repair_update_failed' === ( $wrong_update['error_code'] ?? '' ) );

$integrity_failure = new class() extends SourceDerivedBlockAttributeRepair {
	protected function serialize( array $blocks ): string {
		return parent::serialize( $blocks ) . '<div>unexpected drift</div>';
	}
};
$integrity_result = $integrity_failure->processPost( 321, $fixture, true, static fn (): int => 321 );
assert_blocks_engine_rich_text( 'integrity mismatch aborts apply with stable error', false === $integrity_result['applied'] && 'datamachine_blocks_repair_integrity_failed' === ( $integrity_result['error_code'] ?? '' ) );

$equivalence_fixture = '<!-- wp:heading {"content":"secret"} --><h2 class="wp-block-heading">not secret anymore</h2><!-- /wp:heading -->'
	. '<!-- wp:paragraph {"content":"prefix"} --><p>prefix suffix</p><!-- /wp:paragraph -->'
	. '<!-- wp:list-item {"content":"suffix"} --><li>prefix suffix</li><!-- /wp:list-item -->'
	. '<!-- wp:paragraph {"content":"Fish \u0026 Chips"} --><p>Fish &amp; Chips</p><!-- /wp:paragraph -->'
	. '<!-- wp:paragraph {"content":"Fish &amp; Chips"} --><p>Fish &amp; Chips</p><!-- /wp:paragraph -->'
	. '<!-- wp:heading {"content":"Inline <strong>markup</strong>"} --><h3 class="wp-block-heading">Inline <strong>markup</strong></h3><!-- /wp:heading -->'
	. '<!-- wp:list-item {"content":"<strong><em>Nested</em></strong>"} --><li><strong><em>Nested</em></strong></li><!-- /wp:list-item -->';
$equivalence_expected = '<!-- wp:heading {"content":"secret"} --><h2 class="wp-block-heading">not secret anymore</h2><!-- /wp:heading -->'
	. '<!-- wp:paragraph {"content":"prefix"} --><p>prefix suffix</p><!-- /wp:paragraph -->'
	. '<!-- wp:list-item {"content":"suffix"} --><li>prefix suffix</li><!-- /wp:list-item -->'
	. '<!-- wp:paragraph {"content":"Fish \u0026 Chips"} --><p>Fish &amp; Chips</p><!-- /wp:paragraph -->'
	. '<!-- wp:paragraph --><p>Fish &amp; Chips</p><!-- /wp:paragraph -->'
	. '<!-- wp:heading --><h3 class="wp-block-heading">Inline <strong>markup</strong></h3><!-- /wp:heading -->'
	. '<!-- wp:list-item --><li><strong><em>Nested</em></strong></li><!-- /wp:list-item -->';
$equivalence = $repair->inspect( $equivalence_fixture );
assert_blocks_engine_rich_text( 'substring prefix suffix and decoded-entity matches are skipped', 3 === $equivalence['repairable_count'] && 4 === $equivalence['skipped_count'] );
assert_blocks_engine_rich_text( 'escaped inline and nested exact markup is repairable', $equivalence_expected === $equivalence['content'] );
assert_blocks_engine_rich_text( 'all false positives use exact-root mismatch reason', array( 'content_not_exact_root_rich_text' ) === array_values( array_unique( array_column( array_slice( $equivalence['findings'], 0, 4 ), 'reason' ) ) ) );

$formula = $repair->inspect( '<!-- wp:paragraph {"content":"=HYPERLINK(\"https://bad.example\",\"secret\")"} --><p>=HYPERLINK(&quot;https://bad.example&quot;,&quot;secret&quot;)</p><!-- /wp:paragraph -->' );
$formula_json = wp_json_encode( $formula['findings'] );
$formula_table = implode( "\t", array_values( $formula['findings'][0] ) );
$csv_stream   = fopen( 'php://memory', 'w+' );
fputcsv( $csv_stream, array_keys( $formula['findings'][0] ), ',', '"', '' );
fputcsv( $csv_stream, array_values( $formula['findings'][0] ), ',', '"', '' );
rewind( $csv_stream );
$formula_csv = stream_get_contents( $csv_stream );
fclose( $csv_stream );
assert_blocks_engine_rich_text( 'table JSON and CSV leak no formula or source text', false === strpos( $formula_table, 'HYPERLINK' ) && false === strpos( $formula_table, 'secret' ) && false === strpos( $formula_json, 'HYPERLINK' ) && false === strpos( $formula_json, 'secret' ) && false === strpos( $formula_csv, 'HYPERLINK' ) && false === strpos( $formula_csv, 'secret' ) );

$parse_integer = new ReflectionMethod( BlocksCommand::class, 'parsePositiveInteger' );
$bulk_args     = new ReflectionMethod( BlocksCommand::class, 'bulkQueryArgs' );
$exit_code     = new ReflectionMethod( BlocksCommand::class, 'applyExitCode' );
$valid_id      = $parse_integer->invoke( null, '42', '--post_id' );
$invalid_ids   = array( '0', '-1', '01', '1.0', '', ' 1', '1 ' );
$rejected      = 0;
foreach ( $invalid_ids as $invalid_id ) {
	try {
		$parse_integer->invoke( null, $invalid_id, '--post_id' );
	} catch ( ReflectionException | InvalidArgumentException $exception ) {
		++$rejected;
	}
}
$query_args = $bulk_args->invoke( null, array( 'page' ), 'any', 100, 2 );

assert_blocks_engine_rich_text( 'command accepts positive canonical post ID', 42 === $valid_id );
assert_blocks_engine_rich_text( 'command rejects every malformed post ID', count( $invalid_ids ) === $rejected );
assert_blocks_engine_rich_text( 'bulk query is bounded and ordered', 100 === $query_args['posts_per_page'] && 2 === $query_args['paged'] && 'ID' === $query_args['orderby'] && 'ASC' === $query_args['order'] );
assert_blocks_engine_rich_text( 'bulk query disables all cache priming', false === $query_args['cache_results'] && false === $query_args['update_post_meta_cache'] && false === $query_args['update_post_term_cache'] );
assert_blocks_engine_rich_text( 'partial apply failures exit nonzero', 1 === $exit_code->invoke( null, true, array( 'errors' => 1, 'repairable' => 2 ) ) );
assert_blocks_engine_rich_text( 'total apply failures exit nonzero', 1 === $exit_code->invoke( null, true, array( 'errors' => 3, 'repairable' => 0 ) ) );
assert_blocks_engine_rich_text( 'dry-run findings never force failure exit', 0 === $exit_code->invoke( null, false, array( 'errors' => 3 ) ) );

$total  = $GLOBALS['blocks_engine_rich_text_total'];
$failed = $GLOBALS['blocks_engine_rich_text_failed'];
echo "\nBlocks Engine RichText attributes smoke: {$total} assertions, {$failed} failures.\n";
exit( min( 1, $failed ) );
