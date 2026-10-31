<?php
/**
 * Static schema-conformance guard for Sendy DB reads.
 *
 * Nothing in this repo runs against a live Sendy MySQL schema, so a query
 * that selects a column the real `campaigns` or `links` table does not have
 * (issue #137: `campaigns.clicks`) only surfaces in production, after the
 * "Unknown column" error has already broken every campaign read.
 *
 * This test statically parses every `SELECT ... FROM campaigns|links` string
 * literal in SendyClient.php and asserts each projected column exists on the
 * real table schema below. The schema is transcribed verbatim from
 * `SHOW COLUMNS FROM campaigns` / `SHOW COLUMNS FROM links` on the live
 * Sendy install (see issue #137) — update it only after re-verifying against
 * the live schema, never from assumption.
 *
 * Run with: php tests/sendy-real-schema-columns-smoke.php
 */

$root     = dirname( __DIR__ );
$source   = file_get_contents( $root . '/inc/Sendy/SendyClient.php' );
$failures = array();
$passes   = 0;

function assert_schema( bool $condition, string $name ): void {
	global $failures, $passes;
	if ( $condition ) {
		++$passes;
		return;
	}
	$failures[] = $name;
}

// Verbatim `SHOW COLUMNS FROM <table>` output from the live Sendy install.
$real_columns = array(
	'campaigns' => array(
		'id', 'userID', 'app', 'from_name', 'from_email', 'reply_to', 'title',
		'label', 'plain_text', 'html_text', 'query_string', 'sent', 'to_send',
		'to_send_lists', 'recipients', 'timeout_check', 'opens', 'wysiwyg',
		'quota_deducted', 'send_date', 'lists', 'lists_excl', 'segs',
		'segs_excl', 'timezone', 'errors', 'bounce_setup', 'complaint_setup',
		'opens_tracking', 'links_tracking', 'web_version_lang',
		'campaign_stopped', 'ignore_checks',
	),
	'links'     => array( 'id', 'campaign_id', 'ares_emails_id', 'link', 'clicks' ),
);

// `campaigns.clicks` does not exist. Any query that ever selects it — this
// name would obviously pass a naive "column belongs to some table" check —
// must be rejected specifically against the campaigns table.
assert_schema( ! in_array( 'clicks', $real_columns['campaigns'], true ), 'campaigns table has no clicks column (fixture sanity check)' );

preg_match_all( '/SELECT\s+(.*?)\s+FROM\s+(campaigns|links)\b/is', $source, $matches, PREG_SET_ORDER );

assert_schema( count( $matches ) >= 5, 'source contains the expected number of campaigns/links SELECT statements' );

foreach ( $matches as $match ) {
	$column_list = trim( $match[1] );
	$table       = $match[2];

	// SELECT * and aggregate-only projections (COUNT(*)) reference no
	// column names to validate.
	if ( '*' === $column_list || 0 === stripos( $column_list, 'COUNT(*)' ) ) {
		continue;
	}

	foreach ( explode( ',', $column_list ) as $column ) {
		$column = trim( $column );
		// Strip a bare `table.` alias prefix, if present (none of the
		// current campaigns/links queries use one, but stay defensive).
		if ( false !== strpos( $column, '.' ) ) {
			$column = substr( $column, strrpos( $column, '.' ) + 1 );
		}

		assert_schema(
			in_array( $column, $real_columns[ $table ], true ),
			"'{$column}' is a real column on the {$table} table (found in: SELECT {$column_list} FROM {$table})"
		);
	}
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "Sendy real-schema column checks passed ({$passes} assertions).\n";
