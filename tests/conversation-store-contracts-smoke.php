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

$failures = array();

function assert_true( bool $condition, string $label ): void {
	global $failures;
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}
	echo "FAIL: {$label}\n";
	$failures[] = $label;
}

$narrow_contracts = array(
	ConversationTranscriptStoreInterface::class,
	ConversationSessionIndexInterface::class,
	ConversationReadStateInterface::class,
	ConversationRetentionInterface::class,
	ConversationReportingInterface::class,
);

$aggregate = new ReflectionClass( ConversationStoreInterface::class );
foreach ( $narrow_contracts as $contract ) {
	assert_true( $aggregate->implementsInterface( $contract ), "aggregate composes {$contract}" );
}

foreach ( $narrow_contracts as $contract ) {
	assert_true( is_subclass_of( Chat::class, $contract ), "Chat implements {$contract}" );
	assert_true( is_subclass_of( InMemoryConversationStore::class, $contract ), "InMemoryConversationStore implements {$contract}" );
}

assert_true( is_subclass_of( Chat::class, ConversationStoreInterface::class ), 'Chat remains a ConversationStoreInterface aggregate' );
assert_true( is_subclass_of( InMemoryConversationStore::class, ConversationStoreInterface::class ), 'test adapter remains a ConversationStoreInterface aggregate' );

$factory_get = new ReflectionMethod( ConversationStoreFactory::class, 'get' );
assert_true( ConversationStoreInterface::class === (string) $factory_get->getReturnType(), 'factory still returns the aggregate contract' );

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
