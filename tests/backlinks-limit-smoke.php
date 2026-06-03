<?php
/**
 * Smoke tests for bounded backlink ability responses.
 *
 * Runs via:
 *
 *   php tests/backlinks-limit-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

$GLOBALS['datamachine_permalink_calls'] = array();
$GLOBALS['datamachine_link_graph']      = array(
	'post_type'    => 'wiki',
	'_id_to_title' => array(
		11 => 'Most linked',
		22 => 'Least linked',
		33 => 'Second linked',
	),
	'_all_links'   => array(
		array( 'source_id' => 11, 'target_id' => 100, 'edge_type' => 'wikilink' ),
		array( 'source_id' => 11, 'target_id' => 100, 'edge_type' => 'wikilink' ),
		array( 'source_id' => 11, 'target_id' => 100, 'edge_type' => 'html_anchor' ),
		array( 'source_id' => 22, 'target_id' => 100, 'edge_type' => 'wikilink' ),
		array( 'source_id' => 33, 'target_id' => 100, 'edge_type' => 'wikilink' ),
		array( 'source_id' => 33, 'target_id' => 100, 'edge_type' => 'wikilink' ),
		array( 'source_id' => 44, 'target_id' => 999, 'edge_type' => 'wikilink' ),
	),
);
$GLOBALS['datamachine_filters']         = array();

class Datamachine_Backlinks_Limit_Wpdb {
	/**
	 * Posts table name.
	 *
	 * @var string
	 */
	public string $posts = 'wp_posts';

	/**
	 * Source rows.
	 *
	 * @var array<int, object>
	 */
	public array $rows = array();

	/**
	 * Prepare query args for the fake get_results implementation.
	 *
	 * @param string $query SQL query.
	 * @param mixed  ...$args Query args.
	 * @return array{query:string,args:array}
	 */
	public function prepare( string $query, ...$args ): array {
		return array(
			'query' => $query,
			'args'  => $args,
		);
	}

	/**
	 * Return a post chunk matching ID > last_id.
	 *
	 * @param array $prepared Prepared query payload.
	 * @return array<int, object>
	 */
	public function get_results( array $prepared ): array {
		$last_id = (int) ( $prepared['args'][2] ?? 0 );
		$limit   = (int) ( $prepared['args'][3] ?? 100 );
		$rows    = array_values(
			array_filter(
				$this->rows,
				static fn( $row ) => (int) $row->ID > $last_id
			)
		);

		usort(
			$rows,
			static fn( $a, $b ) => (int) $a->ID <=> (int) $b->ID
		);

		return array_slice( $rows, 0, $limit );
	}
}

$GLOBALS['wpdb'] = new Datamachine_Backlinks_Limit_Wpdb();

function absint( $value ): int {
	return max( 0, (int) $value );
}

function sanitize_text_field( $value ): string {
	return trim( (string) $value );
}

function sanitize_key( $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
}

function get_transient( string $_key ) {
	return $GLOBALS['datamachine_link_graph'];
}

function set_transient( string $_key, $_value, int $_ttl ): bool {
	return true;
}

function get_permalink( $post_id ) {
	$GLOBALS['datamachine_permalink_calls'][] = (int) $post_id;
	return 'https://example.test/wiki/' . (int) $post_id . '/';
}

function home_url( string $path = '' ): string {
	return 'https://example.test' . $path;
}

function wp_parse_url( string $url, int $component = -1 ) {
	$parsed = parse_url( $url );
	if ( -1 === $component ) {
		return $parsed;
	}
	if ( PHP_URL_HOST === $component ) {
		return $parsed['host'] ?? null;
	}
	return null;
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/' );
}

function trailingslashit( string $value ): string {
	return rtrim( $value, '/' ) . '/';
}

function url_to_postid( string $url ): int {
	return false !== strpos( $url, '/wiki/100' ) ? 100 : 0;
}

function apply_filters( string $tag, $value ) {
	return $GLOBALS['datamachine_filters'][ $tag ] ?? $value;
}

function datamachine_assert( bool $condition, string $message ): void {
	if ( $condition ) {
		echo "  [PASS] {$message}\n";
		return;
	}

	echo "  [FAIL] {$message}\n";
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/inc/Abilities/InternalLinkingAbilities.php';

echo "Backlinks limit smoke\n";

$result = DataMachine\Abilities\InternalLinkingAbilities::getBacklinks(
	array(
		'post_id'   => 100,
		'post_type' => 'wiki',
		'limit'     => 2,
	)
);

datamachine_assert( true === $result['success'], 'limited backlink lookup succeeds' );
datamachine_assert( 3 === $result['backlink_count'], 'total backlink source count is preserved' );
datamachine_assert( 2 === count( $result['backlinks'] ), 'returned backlinks are limited' );
datamachine_assert( 11 === $result['backlinks'][0]['source_id'], 'highest link-count source is first' );
datamachine_assert( 33 === $result['backlinks'][1]['source_id'], 'second highest source is second' );
datamachine_assert( array( 11, 33 ) === $GLOBALS['datamachine_permalink_calls'], 'only returned sources are permalink-hydrated' );

$GLOBALS['datamachine_permalink_calls'] = array();
$filtered                             = DataMachine\Abilities\InternalLinkingAbilities::getBacklinks(
	array(
		'post_id'   => 100,
		'post_type' => 'wiki',
		'types'     => array( 'html_anchor' ),
		'limit'     => 2,
	)
);

datamachine_assert( 1 === $filtered['backlink_count'], 'type filtering still affects the total count' );
datamachine_assert( 1 === count( $filtered['backlinks'] ), 'type filtering still limits returned rows' );
datamachine_assert( 11 === $filtered['backlinks'][0]['source_id'], 'type-filtered source is returned' );
datamachine_assert( array( 11 ) === $GLOBALS['datamachine_permalink_calls'], 'type-filtered lookup hydrates only returned rows' );

$GLOBALS['datamachine_link_graph']      = false;
$GLOBALS['datamachine_permalink_calls'] = array();
$GLOBALS['datamachine_filters']         = array(
	'datamachine_link_extractors' => array(
		'html_anchor' => array(
			'callback' => array( DataMachine\Abilities\InternalLinkingAbilities::class, 'extractHtmlAnchorEdges' ),
		),
	),
	'datamachine_link_resolvers'  => array(
		'html_anchor' => array(
			'callback' => array( DataMachine\Abilities\InternalLinkingAbilities::class, 'resolveHtmlAnchor' ),
		),
	),
);
$GLOBALS['wpdb']->rows                  = array(
	(object) array(
		'ID'           => 201,
		'post_title'   => 'Cold most linked',
		'post_content' => '<a href="https://example.test/wiki/100/">Target</a><a href="https://example.test/wiki/100/">Target again</a>',
	),
	(object) array(
		'ID'           => 202,
		'post_title'   => 'Cold least linked',
		'post_content' => '<a href="https://example.test/wiki/100/">Target</a>',
	),
	(object) array(
		'ID'           => 203,
		'post_title'   => 'Other target',
		'post_content' => '<a href="https://example.test/wiki/999/">Other</a>',
	),
);

$cold = DataMachine\Abilities\InternalLinkingAbilities::getBacklinks(
	array(
		'post_id'   => 100,
		'post_type' => 'wiki',
		'limit'     => 1,
	)
);

datamachine_assert( true === $cold['success'], 'cold-cache backlink lookup succeeds without graph cache' );
datamachine_assert( false === $cold['from_cache'], 'cold-cache lookup reports from_cache=false' );
datamachine_assert( 2 === $cold['backlink_count'], 'cold-cache total source count is preserved' );
datamachine_assert( 1 === count( $cold['backlinks'] ), 'cold-cache returned backlinks are limited' );
datamachine_assert( 201 === $cold['backlinks'][0]['source_id'], 'cold-cache sources sort by link count' );
datamachine_assert( 'Cold most linked' === $cold['backlinks'][0]['title'], 'cold-cache source titles are hydrated from scanned rows' );
datamachine_assert( array( 100, 201 ) === $GLOBALS['datamachine_permalink_calls'], 'cold-cache hydrates target plus returned sources only' );

echo "Backlinks limit smoke passed.\n";
