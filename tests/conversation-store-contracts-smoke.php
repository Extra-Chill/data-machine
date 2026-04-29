<?php
/**
 * Smoke tests for the split conversation store contracts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

function did_action( string $hook = '' ): int {
	return 0;
}

function doing_action( string $hook = '' ): bool {
	return false;
}

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	// no-op
}

function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	// no-op
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use DataMachine\Core\Database\Chat\Chat;
use DataMachine\Core\Database\Chat\ConversationReadStateInterface;
use DataMachine\Core\Database\Chat\ConversationReportingInterface;
use DataMachine\Core\Database\Chat\ConversationRetentionInterface;
use DataMachine\Core\Database\Chat\ConversationSessionIndexInterface;
use DataMachine\Core\Database\Chat\ConversationStoreFactory;
use DataMachine\Core\Database\Chat\ConversationStoreInterface;
use DataMachine\Core\Database\Chat\ConversationTranscriptStoreInterface;
use DataMachine\Tests\Unit\Core\Database\Chat\InMemoryConversationStore;

$failures    = array();
$assert_true = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}
	echo "FAIL: {$label}\n";
	$failures[] = $label;
};

$narrow_contracts = array(
	ConversationTranscriptStoreInterface::class,
	ConversationSessionIndexInterface::class,
	ConversationReadStateInterface::class,
	ConversationRetentionInterface::class,
	ConversationReportingInterface::class,
);

$aggregate = new ReflectionClass( ConversationStoreInterface::class );
foreach ( $narrow_contracts as $contract ) {
	$assert_true( $aggregate->implementsInterface( $contract ), "aggregate composes {$contract}" );
}

$transcript_ref = new ReflectionClass( ConversationTranscriptStoreInterface::class );
$assert_true( ! $transcript_ref->implementsInterface( ConversationSessionIndexInterface::class ), 'transcript contract does not include session index surface' );
$assert_true( ! $transcript_ref->implementsInterface( ConversationReadStateInterface::class ), 'transcript contract does not include read-state surface' );
$assert_true( ! $transcript_ref->implementsInterface( ConversationRetentionInterface::class ), 'transcript contract does not include retention surface' );
$assert_true( ! $transcript_ref->implementsInterface( ConversationReportingInterface::class ), 'transcript contract does not include reporting surface' );

$transcript_methods = array_map(
	static fn( ReflectionMethod $method ): string => $method->getName(),
	$transcript_ref->getMethods()
);
sort( $transcript_methods );
$assert_true(
	array(
		'create_session',
		'delete_session',
		'get_recent_pending_session',
		'get_session',
		'update_session',
		'update_title',
	) === $transcript_methods,
	'transcript contract stays limited to transcript/session CRUD methods'
);

foreach ( $narrow_contracts as $contract ) {
	$chat_ref = new ReflectionClass( Chat::class );
	$test_ref = new ReflectionClass( InMemoryConversationStore::class );
	$assert_true( $chat_ref->implementsInterface( $contract ), "Chat implements {$contract}" );
	$assert_true( $test_ref->implementsInterface( $contract ), "InMemoryConversationStore implements {$contract}" );
}

$assert_true( ( new ReflectionClass( Chat::class ) )->implementsInterface( ConversationStoreInterface::class ), 'Chat remains a ConversationStoreInterface aggregate' );
$assert_true( ( new ReflectionClass( InMemoryConversationStore::class ) )->implementsInterface( ConversationStoreInterface::class ), 'test adapter remains a ConversationStoreInterface aggregate' );

$factory_get = new ReflectionMethod( ConversationStoreFactory::class, 'get' );
$assert_true( ConversationStoreInterface::class === (string) $factory_get->getReturnType(), 'factory still returns the aggregate contract' );

$factory_transcript_get = new ReflectionMethod( ConversationStoreFactory::class, 'get_transcript_store' );
$assert_true( ConversationTranscriptStoreInterface::class === (string) $factory_transcript_get->getReturnType(), 'factory exposes the narrow transcript contract' );

echo "\n";
if ( empty( $failures ) ) {
	echo "All conversation store contract smoke tests passed.\n";
	exit( 0 );
}

echo sprintf( "%d failure(s):\n", count( $failures ) );
foreach ( $failures as $failure ) {
	echo "  - {$failure}\n";
}
exit( 1 );
