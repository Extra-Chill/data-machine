<?php
/**
 * Behavioral multisite smoke for Action Scheduler lifecycle setup.
 *
 * @package DataMachine\Tests
 */

namespace {

	define( 'ABSPATH', __DIR__ . '/wordpress/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DATAMACHINE_VERSION', 'test-version' );

	$GLOBALS['datamachine_test_blog_id'] = 1;
	$GLOBALS['datamachine_test_hooks']   = array();
	$GLOBALS['datamachine_test_options'] = array();

	class WP_Site {
		public int $blog_id;

		public function __construct( int $blog_id ) {
			$this->blog_id = $blog_id;
		}
	}

	class DataMachineTestRepository {
		public static function __callStatic( string $name, array $arguments ) {
			unset( $name, $arguments );
		}

		public function __call( string $name, array $arguments ) {
			unset( $name, $arguments );
		}
	}

	#[\AllowDynamicProperties]
	class DataMachineActionSchedulerWpdb {
		public string $base_prefix = 'wp_';
		public string $prefix = 'wp_';
		public array $tables = array();
		public array $existing_tables = array();
		public array $schema_requirements = array();

		public function set_blog_id( int $blog_id ): void {
			$this->prefix = 1 === $blog_id ? 'wp_' : "wp_{$blog_id}_";
		}

		public function prepare( string $query, ...$args ): array {
			return array(
				'query' => $query,
				'args'  => $args,
			);
		}

		public function get_var( $query ) {
			if ( is_array( $query ) && false !== strpos( $query['query'], 'SHOW TABLES LIKE' ) ) {
				$table = str_replace( '\\_', '_', (string) $query['args'][0] );
				return isset( $this->existing_tables[ $table ] ) ? $table : null;
			}

			return null;
		}

		public function get_col( string $query ): array {
			if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $query, $matches ) ) {
				return isset( $this->existing_tables[ $matches[1] ] ) ? array( $matches[1] ) : array();
			}

			return array();
		}

		public function get_results( $query, $format = null ): array {
			unset( $format );
			if ( ! is_array( $query ) ) {
				return array();
			}
			$table = (string) ( $query['args'][0] ?? '' );
			if ( false !== strpos( $query['query'], 'SHOW COLUMNS FROM' ) ) {
				return array_map( static fn( string $column ): array => array( 'Field' => $column ), $this->schema_requirements[ $table ]['columns'] ?? array() );
			}
			if ( false !== strpos( $query['query'], 'SHOW INDEX FROM' ) ) {
				return array_map( static fn( string $index ): array => array( 'Key_name' => $index ), $this->schema_requirements[ $table ]['indexes'] ?? array() );
			}
			return array();
		}

		public function query( string $query ): int {
			unset( $query );
			return 0;
		}

		public function get_charset_collate(): string {
			return '';
		}
	}

	$GLOBALS['wpdb'] = new DataMachineActionSchedulerWpdb();

	function datamachine_test_callback_id( $callback ): string {
		if ( is_array( $callback ) ) {
			$owner = is_object( $callback[0] ) ? spl_object_hash( $callback[0] ) : (string) $callback[0];
			return $owner . '::' . $callback[1];
		}

		return $callback instanceof \Closure ? spl_object_hash( $callback ) : (string) $callback;
	}

	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$id = datamachine_test_callback_id( $callback );
		$GLOBALS['datamachine_test_hooks'][ $hook ][ $priority ][ $id ] = array( $callback, $accepted_args );
		return true;
	}

	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return add_action( $hook, $callback, $priority, $accepted_args );
	}

	function remove_filter( string $hook, $callback, int $priority = 10 ): bool {
		return remove_action( $hook, $callback, $priority );
	}

	function remove_action( string $hook, $callback, int $priority = 10 ): bool {
		$id = datamachine_test_callback_id( $callback );
		if ( ! isset( $GLOBALS['datamachine_test_hooks'][ $hook ][ $priority ][ $id ] ) ) {
			return false;
		}

		unset( $GLOBALS['datamachine_test_hooks'][ $hook ][ $priority ][ $id ] );
		return true;
	}

	function do_action( string $hook, ...$args ): void {
		$priorities = $GLOBALS['datamachine_test_hooks'][ $hook ] ?? array();
		ksort( $priorities );
		foreach ( $priorities as $callbacks ) {
			foreach ( $callbacks as list( $callback, $accepted_args ) ) {
				$callback( ...array_slice( $args, 0, $accepted_args ) );
			}
		}
	}

	function apply_filters( string $hook, $value ) {
		$priorities = $GLOBALS['datamachine_test_hooks'][ $hook ] ?? array();
		ksort( $priorities );
		foreach ( $priorities as $callbacks ) {
			foreach ( $callbacks as list( $callback ) ) {
				$value = $callback( $value );
			}
		}
		return $value;
	}

	function datamachine_test_hook_count( string $hook ): int {
		$count = 0;
		foreach ( $GLOBALS['datamachine_test_hooks'][ $hook ] ?? array() as $callbacks ) {
			$count += count( $callbacks );
		}
		return $count;
	}

	function datamachine_test_has_callback( string $hook, $callback, int $priority = 10 ): bool {
		$id = datamachine_test_callback_id( $callback );
		return isset( $GLOBALS['datamachine_test_hooks'][ $hook ][ $priority ][ $id ] );
	}

	function get_option( string $name, $default = false ) {
		$blog_id = $GLOBALS['datamachine_test_blog_id'];
		return $GLOBALS['datamachine_test_options'][ $blog_id ][ $name ] ?? $default;
	}

	function get_current_blog_id(): int {
		return $GLOBALS['datamachine_test_blog_id'];
	}

	function absint( $value ): int {
		return abs( (int) $value );
	}

	function add_option( string $name, $value ): bool {
		$blog_id = $GLOBALS['datamachine_test_blog_id'];
		if ( isset( $GLOBALS['datamachine_test_options'][ $blog_id ][ $name ] ) ) {
			return false;
		}

		$GLOBALS['datamachine_test_options'][ $blog_id ][ $name ] = $value;
		return true;
	}

	function update_option( string $name, $value, $autoload = null ): bool {
		unset( $autoload );
		$GLOBALS['datamachine_test_options'][ $GLOBALS['datamachine_test_blog_id'] ][ $name ] = $value;
		return true;
	}

	function delete_option( string $name ): bool {
		unset( $GLOBALS['datamachine_test_options'][ $GLOBALS['datamachine_test_blog_id'] ][ $name ] );
		return true;
	}

	function switch_to_blog( int $blog_id ): bool {
		$GLOBALS['datamachine_test_blog_stack'][] = $GLOBALS['datamachine_test_blog_id'];
		$GLOBALS['datamachine_test_blog_id']       = $blog_id;
		$GLOBALS['wpdb']->set_blog_id( $blog_id );
		if ( function_exists( 'datamachine_test_seed_current_schema' ) ) {
			datamachine_test_seed_current_schema();
		}
		return true;
	}

	function restore_current_blog(): bool {
		$blog_id = array_pop( $GLOBALS['datamachine_test_blog_stack'] );
		$GLOBALS['datamachine_test_blog_id'] = $blog_id;
		$GLOBALS['wpdb']->set_blog_id( $blog_id );
		return true;
	}

	function is_plugin_active_for_network( string $plugin ): bool {
		unset( $plugin );
		return true;
	}

	function plugin_basename( string $file ): string {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}

	function get_role( string $role_name ): object {
		unset( $role_name );
		return new class() {
			public function add_cap( string $capability ): void {
				unset( $capability );
			}
		};
	}

	function set_transient( string $name, $value, int $expiration ): bool {
		unset( $name, $value, $expiration );
		return true;
	}

	function datamachine_ensure_default_memory_files(): bool {
		return true;
	}
	function datamachine_mark_flow_schedule_reconciliation(): void {}

	function dbDelta( string $definition ): array {
		if ( ! preg_match( '/CREATE TABLE\s+([^\s(]+)/i', $definition, $matches ) ) {
			return array();
		}

		$table = trim( $matches[1], '`' );
		$GLOBALS['wpdb']->existing_tables[ $table ] = true;
		return array( $table => "Created table {$table}" );
	}
}

namespace DataMachine\Core {
	class PluginSettings {
		public static function getDefaultQueueTuning(): array {
			return array( 'concurrent_batches' => 3, 'batch_size' => 25, 'time_limit' => 60 );
		}

		public static function get( string $key, array $default ): array {
			unset( $key );
			return $GLOBALS['datamachine_test_queue_tuning'][ $GLOBALS['datamachine_test_blog_id'] ] ?? $default;
		}
	}
}

namespace DataMachine\Core\Database {
	abstract class BaseRepository extends \DataMachineTestRepository {
		public static function database_table_exists( string $table, $wpdb = null ): bool {
			$wpdb = $wpdb ?? $GLOBALS['wpdb'];
			return isset( $wpdb->existing_tables[ $table ] );
		}
	}
}

namespace DataMachine\Core\Database\Agents {
	class Agents extends \DataMachineTestRepository {}
	class AgentAccess extends \DataMachineTestRepository {}
	class AgentTokens extends \DataMachineTestRepository {}
}

namespace DataMachine\Core\Database\Logs {
	class LogRepository extends \DataMachineTestRepository {}
}

namespace DataMachine\Core\Database\Pipelines {
	class Pipelines extends \DataMachineTestRepository {}
}

namespace DataMachine\Core\Database\Flows {
	class Flows extends \DataMachineTestRepository {}
}

namespace DataMachine\Core\Database\Jobs {
	class Jobs extends \DataMachineTestRepository {}
}

namespace DataMachine\Core\Database\ProcessedItems {
	class ProcessedItems extends \DataMachineTestRepository {}
}

namespace DataMachine\Core\Database\BatchItems {
	class BatchItems extends \DataMachineTestRepository {}
}

namespace DataMachine\Core\Database\TrackedItems {
	class TrackedItems extends \DataMachineTestRepository {}
}

namespace DataMachine\Core\Database\PostIdentityIndex {
	class PostIdentityIndex extends \DataMachineTestRepository {}
}

namespace DataMachine\Core\Database\PostIdentityReservations {
	class PostIdentityReservations extends \DataMachineTestRepository {}
}

namespace DataMachine\Core\Database\BundleArtifacts {
	class InstalledBundleArtifacts extends \DataMachineTestRepository {}
}

namespace DataMachine\Core\Database\RunMetadata {
	class RunMetadata extends \DataMachineTestRepository {}
}

namespace DataMachine\Core\Database\Chat {
	class Chat extends \DataMachineTestRepository {}
}

namespace DataMachine\Engine\AI\Actions {
	class PendingActionStore extends \DataMachineTestRepository {}
}

namespace DataMachine\Engine\AI {
	class ComposableFileGenerator extends \DataMachineTestRepository {}
}

namespace {
	$root = dirname( __DIR__, 2 );
	require_once $root . '/vendor/woocommerce/action-scheduler/classes/abstracts/ActionScheduler_Abstract_Schema.php';
	require_once $root . '/vendor/woocommerce/action-scheduler/classes/schema/ActionScheduler_StoreSchema.php';
	require_once $root . '/vendor/woocommerce/action-scheduler/classes/schema/ActionScheduler_LoggerSchema.php';
	require_once $root . '/inc/Core/Bootstrap/ActivationServiceProvider.php';
	require_once $root . '/inc/Core/ActionScheduler/GroupRegistrar.php';

	function datamachine_test_seed_current_schema(): void {
		global $wpdb;
		$requirements = \DataMachine\Core\Bootstrap\ActivationServiceProvider::current_schema_requirements();
		foreach ( $requirements as $table => $requirement ) {
			$wpdb->existing_tables[ $table ]    = true;
			$wpdb->schema_requirements[ $table ] = $requirement;
		}
	}
	datamachine_test_seed_current_schema();

	class DataMachineTestQueueRunner {
		public int $dispatches = 0;
		public int $runs = 0;

		public function hook_queue_runner(): void {
			add_action( 'action_scheduler_run_queue', array( $this, 'run' ) );
		}

		public function run(): void {
			++$this->runs;
		}

		public function hook_dispatch_async_request(): void {
			add_action( 'shutdown', array( $this, 'maybe_dispatch_async_request' ) );
		}

		public function unhook_dispatch_async_request(): void {
			remove_action( 'shutdown', array( $this, 'maybe_dispatch_async_request' ) );
		}

		public function maybe_dispatch_async_request(): void {
			++$this->dispatches;
		}
	}

	class DataMachineTestActionSchedulerLock {
		public function is_locked( string $key ): bool {
			unset( $key );
			return false;
		}

		public function set( string $key ): bool {
			unset( $key );
			return true;
		}
	}

	class ActionScheduler {
		private static ?DataMachineTestQueueRunner $runner = null;

		public static function runner(): DataMachineTestQueueRunner {
			self::$runner ??= new DataMachineTestQueueRunner();
			return self::$runner;
		}

		public static function lock(): DataMachineTestActionSchedulerLock {
			return new DataMachineTestActionSchedulerLock();
		}
	}

	$failures = 0;
	$passes   = 0;

	function datamachine_action_scheduler_assert( bool $condition, string $message ): void {
		global $failures, $passes;

		if ( $condition ) {
			++$passes;
			fwrite( STDOUT, "PASS: {$message}\n" );
			return;
		}

		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}

	function datamachine_action_scheduler_tables_for_blog( int $blog_id ): array {
		$prefix = 1 === $blog_id ? 'wp_' : "wp_{$blog_id}_";
		return array(
			$prefix . ActionScheduler_StoreSchema::ACTIONS_TABLE,
			$prefix . ActionScheduler_StoreSchema::CLAIMS_TABLE,
			$prefix . ActionScheduler_StoreSchema::GROUPS_TABLE,
			$prefix . ActionScheduler_LoggerSchema::LOG_TABLE,
		);
	}

	function datamachine_assert_blog_setup( int $blog_id, string $context ): void {
		global $wpdb;

		foreach ( datamachine_action_scheduler_tables_for_blog( $blog_id ) as $table ) {
			datamachine_action_scheduler_assert( isset( $wpdb->existing_tables[ $table ] ), "{$context}: creates {$table}" );
		}

		$options = $GLOBALS['datamachine_test_options'][ $blog_id ] ?? array();
		datamachine_action_scheduler_assert( isset( $options['schema-ActionScheduler_StoreSchema'] ), "{$context}: records store schema version" );
		datamachine_action_scheduler_assert( isset( $options['schema-ActionScheduler_LoggerSchema'] ), "{$context}: records logger schema version" );
		datamachine_action_scheduler_assert( DATAMACHINE_VERSION === ( $options['datamachine_db_version'] ?? null ), "{$context}: records Data Machine version" );
	}

	use DataMachine\Core\Bootstrap\ActivationServiceProvider;

	// Existing-site activation runs both registered activation callbacks.
	ActivationServiceProvider::activate_defaults_for_site();
	ActivationServiceProvider::activate_for_site();
	datamachine_assert_blog_setup( 1, 'existing site' );
	datamachine_action_scheduler_assert( isset( $GLOBALS['datamachine_test_options'][1]['datamachine_settings'] ), 'existing site: records defaults' );

	// A newly created site must receive the same per-blog schema and options.
	ActivationServiceProvider::on_new_site( new WP_Site( 2 ) );
	datamachine_assert_blog_setup( 2, 'new site' );
	datamachine_action_scheduler_assert( isset( $GLOBALS['datamachine_test_options'][2]['datamachine_settings'] ), 'new site: records defaults' );
	datamachine_action_scheduler_assert( 1 === $GLOBALS['datamachine_test_blog_id'], 'new-site setup restores the original blog' );

	// Repeating both lifecycle paths must not duplicate registrations or callbacks.
	ActivationServiceProvider::activate_for_site();
	ActivationServiceProvider::on_new_site( new WP_Site( 2 ) );
	foreach ( array( ActionScheduler_StoreSchema::ACTIONS_TABLE, ActionScheduler_StoreSchema::CLAIMS_TABLE, ActionScheduler_StoreSchema::GROUPS_TABLE, ActionScheduler_LoggerSchema::LOG_TABLE ) as $table ) {
		datamachine_action_scheduler_assert( 1 === count( array_keys( $GLOBALS['wpdb']->tables, $table, true ) ), "repeated setup registers {$table} once" );
	}
	datamachine_action_scheduler_assert( 0 === datamachine_test_hook_count( 'action_scheduler_before_schema_update' ), 'repeated setup removes schema migration callbacks' );
	datamachine_assert_blog_setup( 1, 'repeated existing site' );
	datamachine_assert_blog_setup( 2, 'repeated new site' );

	// A first initialization with missing schema leaves no queue runner or dispatcher callbacks.
	$runner = ActionScheduler::runner();
	$missing_table = 'wp_' . ActionScheduler_StoreSchema::ACTIONS_TABLE;
	unset( $GLOBALS['wpdb']->existing_tables[ $missing_table ] );
	$runner->hook_queue_runner();
	$runner->hook_dispatch_async_request();
	require_once $root . '/inc/Core/ActionScheduler/QueueTuning.php';
	do_action( 'action_scheduler_init' );
	datamachine_action_scheduler_assert( 0 === datamachine_test_hook_count( 'action_scheduler_run_queue' ), 'first missing-schema initialization removes all queue runner callbacks' );
	datamachine_action_scheduler_assert( 0 === datamachine_test_hook_count( 'shutdown' ), 'first missing-schema initialization removes all dispatchers' );

	// A healthy re-initialization restores exactly one runner, dispatcher, and each filter.
	$GLOBALS['wpdb']->existing_tables[ $missing_table ] = true;
	$runner->hook_queue_runner();
	$runner->hook_dispatch_async_request();
	do_action( 'action_scheduler_init' );
	datamachine_action_scheduler_assert( 1 === datamachine_test_hook_count( 'action_scheduler_run_queue' ), 'healthy initialization installs one Data Machine queue runner' );
	datamachine_action_scheduler_assert( datamachine_test_has_callback( 'action_scheduler_run_queue', 'DataMachine\\Core\\ActionScheduler\\datamachine_run_queue_with_deadlock_retries' ), 'healthy initialization installs the retry queue runner' );
	datamachine_action_scheduler_assert( 1 === datamachine_test_hook_count( 'shutdown' ), 'healthy initialization installs one Data Machine dispatcher' );
	datamachine_action_scheduler_assert( datamachine_test_has_callback( 'shutdown', 'DataMachine\\Core\\ActionScheduler\\datamachine_dispatch_async_request' ), 'healthy initialization installs the Data Machine dispatcher' );
	foreach ( array( 'action_scheduler_queue_runner_concurrent_batches', 'action_scheduler_queue_runner_batch_size', 'action_scheduler_queue_runner_time_limit' ) as $filter ) {
		datamachine_action_scheduler_assert( 1 === datamachine_test_hook_count( $filter ), "healthy initialization installs one {$filter} callback" );
	}

	$runner->hook_queue_runner();
	$runner->hook_dispatch_async_request();
	do_action( 'action_scheduler_init' );
	datamachine_action_scheduler_assert( 1 === datamachine_test_hook_count( 'action_scheduler_run_queue' ), 'healthy to healthy keeps one Data Machine queue runner' );
	datamachine_action_scheduler_assert( datamachine_test_has_callback( 'action_scheduler_run_queue', 'DataMachine\\Core\\ActionScheduler\\datamachine_run_queue_with_deadlock_retries' ), 'healthy to healthy keeps the retry queue runner' );
	datamachine_action_scheduler_assert( 1 === datamachine_test_hook_count( 'shutdown' ), 'healthy to healthy keeps one Data Machine dispatcher' );
	datamachine_action_scheduler_assert( datamachine_test_has_callback( 'shutdown', 'DataMachine\\Core\\ActionScheduler\\datamachine_dispatch_async_request' ), 'healthy to healthy keeps the Data Machine dispatcher' );
	foreach ( array( 'action_scheduler_queue_runner_concurrent_batches', 'action_scheduler_queue_runner_batch_size', 'action_scheduler_queue_runner_time_limit' ) as $filter ) {
		datamachine_action_scheduler_assert( 1 === datamachine_test_hook_count( $filter ), "healthy to healthy keeps one {$filter} callback" );
	}

	// Tuning remains scoped to the active blog while callbacks remain process-global.
	$GLOBALS['datamachine_test_queue_tuning'][1] = array( 'concurrent_batches' => 4, 'batch_size' => 30, 'time_limit' => 70 );
	do_action( 'action_scheduler_init' );
	switch_to_blog( 2 );
	$GLOBALS['datamachine_test_queue_tuning'][2] = array( 'concurrent_batches' => 2, 'batch_size' => 15, 'time_limit' => 45 );
	do_action( 'action_scheduler_init' );
	datamachine_action_scheduler_assert( 2 === apply_filters( 'action_scheduler_queue_runner_concurrent_batches', 1 ), 'blog 2 uses its own queue tuning' );
	restore_current_blog();
	datamachine_action_scheduler_assert( 4 === apply_filters( 'action_scheduler_queue_runner_concurrent_batches', 1 ), 'restored blog uses its own queue tuning' );

	// A later missing schema removes both AS and Data Machine dispatchers and all tuning filters.
	unset( $GLOBALS['wpdb']->existing_tables[ $missing_table ] );
	$runner->hook_queue_runner();
	$runner->hook_dispatch_async_request();
	do_action( 'action_scheduler_init' );
	datamachine_action_scheduler_assert( 0 === datamachine_test_hook_count( 'action_scheduler_run_queue' ), 'healthy to missing removes all queue runner callbacks' );
	datamachine_action_scheduler_assert( 0 === datamachine_test_hook_count( 'shutdown' ), 'missing schema unhooks the default shutdown dispatcher' );
	foreach ( array( 'action_scheduler_queue_runner_concurrent_batches', 'action_scheduler_queue_runner_batch_size', 'action_scheduler_queue_runner_time_limit' ) as $filter ) {
		datamachine_action_scheduler_assert( 0 === datamachine_test_hook_count( $filter ), "healthy to missing removes {$filter} callbacks" );
	}
	do_action( 'shutdown' );
	datamachine_action_scheduler_assert( 0 === $runner->dispatches, 'missing schema does not dispatch an async request' );

	fwrite( STDOUT, "\n{$passes} passed, {$failures} failed.\n" );
	exit( $failures > 0 ? 1 : 0 );
}
