<?php
/**
 * Pure-PHP smoke tests for memory bundle artifact policy (#1543-#1545).
 *
 * Run with: php tests/memory-bundle-policy-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

$GLOBALS['datamachine_memory_policy_filters']    = array();
$GLOBALS['datamachine_memory_policy_actions']    = array();
$GLOBALS['datamachine_memory_policy_transients'] = array();

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ?? '' );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return trim( wp_strip_all_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $filename ): string {
		return preg_replace( '/[^a-zA-Z0-9._\/-]/', '', $filename ) ?? '';
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ): string {
		return strip_tags( (string) $value );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 7;
	}
}

if ( ! function_exists( 'get_users' ) ) {
	function get_users( array $_args ): array {
		unset( $_args );
		return array( 7 );
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $_capability ): bool {
		unset( $_capability );
		return true;
	}
}

if ( ! function_exists( 'user_can' ) ) {
	function user_can( int $_user_id, string $_capability ): bool {
		unset( $_user_id, $_capability );
		return true;
	}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( int $bytes ): string {
		return $bytes . ' B';
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		static $i = 0;
		++$i;
		return sprintf( '00000000-0000-4000-8000-%012d', $i );
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $_expiration ): bool {
		unset( $_expiration );
		$GLOBALS['datamachine_memory_policy_transients'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		return $GLOBALS['datamachine_memory_policy_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['datamachine_memory_policy_transients'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['datamachine_memory_policy_filters'][ $hook ][ $priority ][] = array(
			'callback'      => $callback,
			'accepted_args' => $accepted_args,
		);
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		add_filter( $hook, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		$filters = $GLOBALS['datamachine_memory_policy_filters'][ $hook ] ?? array();
		ksort( $filters );
		foreach ( $filters as $callbacks ) {
			foreach ( $callbacks as $filter ) {
				$value = $filter['callback']( ...array_slice( array_merge( array( $value ), $args ), 0, $filter['accepted_args'] ) );
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$GLOBALS['datamachine_memory_policy_actions'][] = array(
			'hook' => $hook,
			'args' => $args,
		);
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( string $_hook = '' ): bool {
		unset( $_hook );
		return false;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $_hook = '' ): int {
		unset( $_hook );
		return 0;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $_thing ): bool {
		unset( $_thing );
		return false;
	}
}

require_once __DIR__ . '/../vendor/autoload.php';

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\FilesRepository\AgentMemoryListEntry;
use DataMachine\Core\FilesRepository\AgentMemoryReadResult;
use DataMachine\Core\FilesRepository\AgentMemoryScope;
use DataMachine\Core\FilesRepository\AgentMemoryStoreInterface;
use DataMachine\Core\FilesRepository\AgentMemoryWriteResult;
use DataMachine\Engine\AI\Actions\PendingActionStore;
use DataMachine\Engine\AI\Actions\ResolvePendingActionAbility;
use DataMachine\Engine\AI\Memory\MemorySectionArtifact;
use DataMachine\Engine\AI\Memory\MemorySectionPendingAction;
use DataMachine\Engine\AI\Memory\SelfMemoryWritePolicy;
use DataMachine\Engine\Bundle\AgentBundleArtifactStatus;
use DataMachine\Engine\Bundle\AgentBundleManifest;

class MemoryPolicyFakeStore implements AgentMemoryStoreInterface {

	/** @var array<string, string> */
	public array $files = array();

	public function read( AgentMemoryScope $scope ): AgentMemoryReadResult {
		if ( ! array_key_exists( $scope->key(), $this->files ) ) {
			return AgentMemoryReadResult::not_found();
		}

		$content = $this->files[ $scope->key() ];
		return new AgentMemoryReadResult( true, $content, sha1( $content ), strlen( $content ), 123 );
	}

	public function write( AgentMemoryScope $scope, string $content, ?string $_if_match = null ): AgentMemoryWriteResult {
		unset( $_if_match );
		$this->files[ $scope->key() ] = $content;
		return AgentMemoryWriteResult::ok( sha1( $content ), strlen( $content ) );
	}

	public function exists( AgentMemoryScope $scope ): bool {
		return array_key_exists( $scope->key(), $this->files );
	}

	public function delete( AgentMemoryScope $scope ): AgentMemoryWriteResult {
		unset( $this->files[ $scope->key() ] );
		return AgentMemoryWriteResult::ok( '', 0 );
	}

	public function list_layer( AgentMemoryScope $_scope_query ): array {
		unset( $_scope_query );
		return array();
	}

	public function list_subtree( AgentMemoryScope $_scope_query, string $_prefix ): array {
		unset( $_scope_query, $_prefix );
		return array();
	}
}

function memory_policy_assert( bool $condition, string $message ): void {
	static $assertions = 0;
	++$assertions;
	if ( ! $condition ) {
		fwrite( STDERR, "Assertion failed: {$message}\n" );
		exit( 1 );
	}
	echo "ok {$assertions} - {$message}\n";
}

$store = new MemoryPolicyFakeStore();
add_filter(
	'datamachine_memory_store',
	static function ( $_default, AgentMemoryScope $_scope ) use ( $store ) {
		unset( $_default, $_scope );
		return $store;
	},
	10,
	2
);
MemorySectionPendingAction::register();
PermissionHelper::set_agent_context( 42, 7 );

$scope_key                  = 'agent:7:42:MEMORY.md';
$store->files[ $scope_key ] = "# MEMORY.md\n\n## Source quirks\nExisting note.\n";

echo "\n[1] Memory sections are bundle artifacts with ownership/status metadata\n";
$manifest = AgentBundleManifest::from_array(
	array(
		'schema_version' => 1,
		'bundle_slug'    => 'WooCommerce Brain',
		'bundle_version' => '2026.04.28',
		'exported_at'    => '2026-04-28T00:00:00Z',
		'exported_by'    => 'test',
		'agent'          => array(
			'slug'         => 'wc-agent',
			'label'        => 'WooCommerce Agent',
			'description'  => 'Maintains WooCommerce knowledge.',
			'agent_config' => array(),
		),
		'included'       => array(
			'memory'       => array( 'source-quirks' ),
			'pipelines'    => array(),
			'flows'        => array(),
			'handler_auth' => 'refs',
		),
	)
);
$artifact = MemorySectionArtifact::from_bundle_section( $manifest, 42, 'Source quirks', 'source_quirk', 'memory/source-quirks.md', "Existing note.\n", '2026-04-28T00:00:00Z' );
$row      = $artifact->to_array();
memory_policy_assert( 'bundle' === $row['owner'], 'bundle owner is tracked' );
memory_policy_assert( 42 === $row['agent_id'], 'agent identity is tracked' );
memory_policy_assert( 'source-quirks' === $row['section_id'], 'section id is normalized' );
memory_policy_assert( AgentBundleArtifactStatus::CLEAN === $row['local_status'], 'fresh bundle section is clean' );
memory_policy_assert( true === $artifact->can_auto_update_from_bundle(), 'clean bundle section can auto-update from bundle' );
memory_policy_assert( AgentBundleArtifactStatus::MODIFIED === $artifact->with_current_content( "Local edit.\n", '2026-04-28T01:00:00Z' )->local_status(), 'edited bundle section is modified' );
memory_policy_assert( true === $artifact->with_current_content( "Local edit.\n", '2026-04-28T01:00:00Z' )->should_stage_bundle_update(), 'modified bundle section should stage bundle update' );
memory_policy_assert( AgentBundleArtifactStatus::MISSING === $artifact->with_current_content( null, '2026-04-28T01:00:00Z' )->local_status(), 'missing bundle section is missing' );

echo "\n[2] Self-memory writes are scoped to current agent and operational section types\n";
$write = SelfMemoryWritePolicy::execute(
	array(
		'section'      => 'Run lessons',
		'section_type' => 'run_lesson',
		'content'      => 'MGS source needed narrower query terms.',
		'reason'       => 'Observed noisy result set.',
	)
);
memory_policy_assert( true === $write['success'], 'allowed self-memory write succeeds' );
memory_policy_assert( str_contains( $store->files[ $scope_key ], 'MGS source needed narrower query terms.' ), 'allowed self-memory write reaches active memory store' );

$cross_agent = SelfMemoryWritePolicy::execute(
	array(
		'agent_id'     => 99,
		'section'      => 'Run lessons',
		'section_type' => 'run_lesson',
		'content'      => 'Should not write.',
	)
);
memory_policy_assert( false === $cross_agent['success'] && 'cross_agent_denied' === $cross_agent['error_code'], 'cross-agent write is denied by default' );

$durable_fact = SelfMemoryWritePolicy::execute(
	array(
		'section'      => 'Facts',
		'section_type' => 'domain_fact',
		'content'      => 'WooCommerce was founded in 2008.',
	)
);
memory_policy_assert( false === $durable_fact['success'] && 'durable_fact_denied' === $durable_fact['error_code'], 'durable fact writes are rejected' );

echo "\n[3] Bundle-owned section overwrites are staged with redacted PendingAction preview\n";
add_filter(
	'datamachine_memory_section_artifact',
	static function ( $_artifact, array $context ) use ( $artifact ) {
		unset( $_artifact );
		return 'Source quirks' === $context['section'] ? $artifact : null;
	},
	10,
	2
);

$staged = SelfMemoryWritePolicy::execute(
	array(
		'section'      => 'Source quirks',
		'section_type' => 'source_quirk',
		'content'      => 'token=super-secret-value should not appear in preview.',
		'mode'         => 'set',
		'reason'       => 'Bundle seed needs local approval before overwrite.',
	)
);
memory_policy_assert( true === $staged['staged'], 'bundle-owned overwrite is staged instead of applied' );
memory_policy_assert( ! str_contains( $store->files[ $scope_key ], 'super-secret-value' ), 'staged overwrite does not mutate memory immediately' );
memory_policy_assert( str_contains( $staged['preview']['diff'], '[redacted]' ), 'preview diff redacts secret-like values' );
memory_policy_assert( ! str_contains( $staged['preview']['diff'], 'super-secret-value' ), 'preview diff does not expose secret-like value' );

$payload = PendingActionStore::get( $staged['action_id'] );
memory_policy_assert( is_array( $payload ) && MemorySectionPendingAction::KIND === $payload['kind'], 'pending action stores memory section kind' );

echo "\n[4] PendingAction accept applies only the approved section; reject leaves memory untouched\n";
$accepted = ResolvePendingActionAbility::execute(
	array(
		'action_id' => $staged['action_id'],
		'decision'  => 'accepted',
	)
);
memory_policy_assert( true === $accepted['success'], 'accepted memory PendingAction resolves successfully' );
memory_policy_assert( str_contains( $store->files[ $scope_key ], 'super-secret-value' ), 'accepted memory PendingAction applies raw content to the target section' );
memory_policy_assert( null === PendingActionStore::get( $staged['action_id'] ), 'accepted PendingAction is deleted after resolution' );

$before_reject = $store->files[ $scope_key ];
$rejectable    = SelfMemoryWritePolicy::execute(
	array(
		'section'      => 'Source quirks',
		'section_type' => 'source_quirk',
		'content'      => 'Rejected content.',
		'mode'         => 'set',
	)
);
$rejected = ResolvePendingActionAbility::execute(
	array(
		'action_id' => $rejectable['action_id'],
		'decision'  => 'rejected',
	)
);
memory_policy_assert( true === $rejected['success'], 'rejected memory PendingAction resolves successfully' );
memory_policy_assert( sha1( $before_reject ) === sha1( $store->files[ $scope_key ] ), 'rejected memory PendingAction leaves memory untouched' );
memory_policy_assert( ! str_contains( $store->files[ $scope_key ], 'Rejected content.' ), 'rejected memory PendingAction does not apply proposed content' );

PermissionHelper::clear_agent_context();

echo "Memory bundle policy smoke passed.\n";
