<?php
/** Executable identity, redaction, cache, and idempotency contract coverage. */

define( 'ABSPATH', __DIR__ . '/' );

$projection_filter = null;
function apply_filters( string $hook, mixed $value, ...$args ): mixed {
	global $projection_filter;
	if ( 'datamachine_ai_project_data_packet' === $hook && is_callable( $projection_filter ) ) {
		return $projection_filter( $value, ...$args );
	}
	return $value;
}
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }

require_once __DIR__ . '/../inc/Core/Database/BaseRepository.php';
require_once __DIR__ . '/../inc/Core/Database/ProcessedItems/ProcessedItems.php';
require_once __DIR__ . '/../inc/Engine/AI/DataPacketPromptProjector.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolExecutor.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolManager.php';

use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Engine\AI\DataPacketPromptProjector;
use DataMachine\Engine\AI\Tools\ToolExecutor;
use DataMachine\Engine\AI\Tools\ToolManager;

$failures = 0;
$passes   = 0;
$assert = static function ( bool $condition, string $label ) use ( &$failures, &$passes ): void {
	if ( $condition ) { ++$passes; echo "  [PASS] {$label}\n"; return; }
	++$failures; echo "  [FAIL] {$label}\n";
};

$claim = array(
	'identity_scope'  => 'scope',
	'source_type'     => 'source',
	'item_identifier' => 'item',
	'ownership_token' => 'opaque-owner-token',
);
$derived = ProcessedItems::disposition_identity( 'scope', 'source', 'item' );
$resolved = ProcessedItems::resolve_disposition_claim( array( ProcessedItems::CLAIM_METADATA_KEY => $claim ) );
$assert( $derived === ( $resolved['disposition_id'] ?? '' ), 'legacy single-item claim infers derived identity' );

$forged = $claim;
$forged['disposition_id'] = 'opaque-owner-token';
$assert( null === ProcessedItems::resolve_disposition_claim( array( ProcessedItems::CLAIM_METADATA_KEY => $forged ) ), 'mismatched supplied identity is rejected' );

$claim['disposition_id'] = $derived;
$canonical = array( array( 'metadata' => array( ProcessedItems::CLAIM_METADATA_KEY => $claim ) ) );
$projection_filter = static function ( array $projected, array $packet ): array {
	unset( $projected );
	$packet['metadata']['filter_nested'] = array( 'ownership_token' => 'filter-secret' );
	return $packet;
};
$projected = DataPacketPromptProjector::project( $canonical );
$encoded   = (string) wp_json_encode( $projected );
$assert( ! str_contains( $encoded, 'opaque-owner-token' ) && ! str_contains( $encoded, 'filter-secret' ), 'recursive ownership tokens are redacted after filters' );
$assert( $derived === ( $projected[0]['metadata'][ ProcessedItems::DISPOSITION_ID_METADATA_KEY ] ?? '' ), 'safe derived identity is restored after filtering' );

$prior = array(
	array(
		'tool_name' => 'handler_tool',
		'parameters' => array( 'title' => 'old payload', 'disposition_id' => $derived ),
		'result' => array( 'success' => true, 'disposition_id' => $derived ),
	),
);
$duplicate = ToolExecutor::existingPacketExecutionResult( 'handler_tool', $derived, array( 'prior_tool_results' => $prior ) );
$assert( true === ( $duplicate['success'] ?? false ) && true === ( $duplicate['already_dispositioned'] ?? false ), 'successful handler identity blocks changed-payload repeat before execution' );

$cache_key = new ReflectionMethod( ToolManager::class, 'buildHandlerToolsCacheKey' );
$unbound   = $cache_key->invoke( null, 'scope', 'handler', array(), array( 'job_id' => 10 ) );
$bound     = $cache_key->invoke( null, 'scope', 'handler', array(), array( 'job_id' => 10, ProcessedItems::CLAIM_METADATA_KEY => $claim ) );
$assert( $unbound !== $bound, 'handler-tool cache distinguishes bound and unbound schemas' );

echo "packet-disposition-contract-smoke: {$passes} passed, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
