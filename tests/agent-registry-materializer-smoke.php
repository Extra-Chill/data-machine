<?php
/**
 * Pure-PHP smoke test for agent registry/materializer split (#1566).
 *
 * Run with: php tests/agent-registry-materializer-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$GLOBALS['__agent_materializer_actions'] = array();
	$GLOBALS['__agent_materializer_hooks']   = array();
	$GLOBALS['__agent_materializer_current'] = array();
	$GLOBALS['__agent_materializer_done']    = array();

	if ( ! function_exists( 'sanitize_title' ) ) {
		function sanitize_title( string $value ): string {
			$value = strtolower( $value );
			$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
			return trim( (string) $value, '-' );
		}
	}

	if ( ! function_exists( 'sanitize_file_name' ) ) {
		function sanitize_file_name( string $value ): string {
			return basename( $value );
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $hook, ...$args ): void {
			$GLOBALS['__agent_materializer_current'][] = $hook;
			$GLOBALS['__agent_materializer_actions'][ $hook ][] = $args;
			$callbacks = $GLOBALS['__agent_materializer_hooks'][ $hook ] ?? array();
			ksort( $callbacks );

			foreach ( $callbacks as $priority_callbacks ) {
				foreach ( $priority_callbacks as $callback ) {
					call_user_func_array( $callback, $args );
				}
			}

			array_pop( $GLOBALS['__agent_materializer_current'] );
			$GLOBALS['__agent_materializer_done'][ $hook ] = ( $GLOBALS['__agent_materializer_done'][ $hook ] ?? 0 ) + 1;
		}
	}

	function doing_action( string $hook ): bool {
		return in_array( $hook, $GLOBALS['__agent_materializer_current'], true );
	}

	function did_action( string $hook ): int {
		return (int) ( $GLOBALS['__agent_materializer_done'][ $hook ] ?? 0 );
	}

	if ( ! function_exists( 'esc_html' ) ) {
		function esc_html( string $value ): string {
			return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
		}
	}

	if ( ! function_exists( 'wp_generate_uuid4' ) ) {
		function wp_generate_uuid4(): string {
			return '00000000-0000-4000-8000-000000000001';
		}
	}

	if ( ! function_exists( 'add_action' ) ) {
		function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
			unset( $accepted_args );
			$GLOBALS['__agent_materializer_hooks'][ $hook ][ $priority ][] = $callback;
		}
	}

	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
			add_action( $hook, $callback, $priority, $accepted_args );
		}
	}
}

namespace DataMachine\Core\Database\Agents {
	class Agents {
		/** @var array<string, array> */
		public static array $rows = array();

		public function get_by_slug( string $agent_slug ): ?array {
			foreach ( self::$rows as $row ) {
				if ( $agent_slug === ( $row['agent_slug'] ?? '' ) ) {
					return $row;
				}
			}
			return null;
		}

		public function get_by_identity_scope( string $agent_slug, int $owner_id, string $instance_key = 'default' ): ?array {
			return self::$rows[ "{$agent_slug}:{$owner_id}:{$instance_key}" ] ?? null;
		}

		public function get_agent( int $agent_id ): ?array {
			foreach ( self::$rows as $row ) {
				if ( (int) ( $row['agent_id'] ?? 0 ) === $agent_id ) {
					return $row;
				}
			}

			return null;
		}

		public function create_if_missing( string $agent_slug, string $agent_name, int $owner_id, array $agent_config = array() ): int {
			$existing = $this->get_by_slug( $agent_slug );
			if ( $existing ) {
				return (int) $existing['agent_id'];
			}
			return $this->create_identity_if_missing( $agent_slug, $agent_name, $owner_id, 'default', $agent_config )['agent_id'];
		}

		public function create_identity_if_missing( string $agent_slug, string $agent_name, int $owner_id, string $instance_key, array $agent_config = array() ): array {
			$key = "{$agent_slug}:{$owner_id}:{$instance_key}";
			if ( isset( self::$rows[ $key ] ) ) {
				return array( 'agent_id' => (int) self::$rows[ $key ]['agent_id'], 'created' => false );
			}
			$agent_id = count( self::$rows ) + 100;
			self::$rows[ $key ] = array(
				'agent_id'     => $agent_id,
				'agent_slug'   => $agent_slug,
				'agent_name'   => $agent_name,
				'owner_id'     => $owner_id,
				'instance_key' => $instance_key,
				'agent_config' => $agent_config,
				'provisioned_at' => null,
			);

			return array( 'agent_id' => $agent_id, 'created' => true );
		}

		public function claim_identity_provisioning( int $agent_id, string $token ): bool {
			foreach ( self::$rows as &$row ) {
				if ( (int) $row['agent_id'] === $agent_id && empty( $row['provisioned_at'] ) && empty( $row['provisioning_token'] ) ) {
					$row['provisioning_token'] = $token;
					return true;
				}
			}
			return false;
		}

		public function complete_identity_provisioning( int $agent_id, string $token ): bool {
			foreach ( self::$rows as &$row ) {
				if ( (int) $row['agent_id'] === $agent_id && $token === ( $row['provisioning_token'] ?? '' ) ) {
					$row['provisioned_at']     = '2026-07-28 00:00:00';
					$row['provisioning_token'] = '';
					return true;
				}
			}
			return false;
		}

		public function release_identity_provisioning( int $agent_id, string $token ): void {
			foreach ( self::$rows as &$row ) {
				if ( (int) $row['agent_id'] === $agent_id && $token === ( $row['provisioning_token'] ?? '' ) ) {
					$row['provisioning_token'] = '';
				}
			}
		}

		public function update_agent( int $agent_id, array $data ): bool {
			foreach ( self::$rows as &$row ) {
				if ( (int) ( $row['agent_id'] ?? 0 ) !== $agent_id ) {
					continue;
				}

				if ( array_key_exists( 'agent_config', $data ) ) {
					$row['agent_config'] = $data['agent_config'];
				}

				return true;
			}

			return false;
		}
	}

	class AgentAccess {
		/** @var array<int, array{agent_id:int, owner_id:int}> */
		public static array $bootstrapped = array();

		public function bootstrap_owner_access( int $agent_id, int $owner_id ): bool {
			self::$bootstrapped[] = array(
				'agent_id' => $agent_id,
				'owner_id' => $owner_id,
			);
			return true;
		}
	}
}

namespace DataMachine\Core\FilesRepository {
	class DirectoryManager {
		public static int $default_user_id = 0;

		/** @var string[] */
		public static array $ensured = array();

		public static function get_default_agent_user_id(): int {
			return self::$default_user_id;
		}

		public function get_agent_identity_directory( string $slug ): string {
			return '/agents/' . $slug;
		}

		public function resolve_agent_directory( array $context ): string {
			foreach ( \DataMachine\Core\Database\Agents\Agents::$rows as $row ) {
				if ( (int) $row['agent_id'] === (int) ( $context['agent_id'] ?? 0 ) ) {
					return $this->get_agent_identity_directory( $row['agent_slug'] );
				}
			}
			return '/agents/unknown';
		}

		public function ensure_directory_exists( string $path ): bool {
			self::$ensured[] = $path;
			return true;
		}
	}
}

namespace DataMachine\Abilities\File {
	class ScaffoldAbilities {
		public static ?ScaffoldAbilityStub $ability = null;

		public static function get_ability(): ?ScaffoldAbilityStub {
			return self::$ability;
		}

		public static function execute( array $args ): void {
			self::$ability?->execute( $args );
		}
	}

	class ScaffoldAbilityStub {
		/** @var array<int, array> */
		public array $calls = array();

		public function execute( array $args ): void {
			$this->calls[] = $args;
		}
	}
}

namespace {
	require_once __DIR__ . '/agents-api-loader.php';
	datamachine_tests_require_agents_api();
	require_once __DIR__ . '/../inc/Core/Identity/AgentIdentityStoreAdapter.php';
	require_once __DIR__ . '/../inc/Engine/Agents/AgentMaterializer.php';
	require_once __DIR__ . '/../inc/Engine/Agents/AgentRegistry.php';
	require_once __DIR__ . '/../inc/Engine/Agents/datamachine-register-agents.php';

	use DataMachine\Abilities\File\ScaffoldAbilities;
	use DataMachine\Abilities\File\ScaffoldAbilityStub;
	use DataMachine\Core\Database\Agents\AgentAccess;
	use DataMachine\Core\Database\Agents\Agents;
	use DataMachine\Core\FilesRepository\DirectoryManager;
	use DataMachine\Core\Identity\AgentIdentityStoreAdapter;
	use DataMachine\Engine\Agents\AgentRegistry;

	$failures = array();
	$passes   = 0;

	function assert_agent_materializer_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
		if ( $expected === $actual ) {
			++$passes;
			echo "  PASS {$name}\n";
			return;
		}

		$failures[] = $name;
		echo "  FAIL {$name}\n";
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
	}

	function reset_agent_materializer_smoke(): void {
		AgentRegistry::reset_for_tests();
		Agents::$rows                                    = array();
		AgentAccess::$bootstrapped                       = array();
		DirectoryManager::$default_user_id               = 0;
		DirectoryManager::$ensured                       = array();
		ScaffoldAbilities::$ability                      = new ScaffoldAbilityStub();
		$GLOBALS['__agent_materializer_actions'] = array();
		$GLOBALS['__agent_materializer_hooks']   = array();
		$GLOBALS['__agent_materializer_current'] = array();
		$GLOBALS['__agent_materializer_done']    = array();
		add_action( 'init', array( 'WP_Agents_Registry', 'init' ), 10 );
	}

	echo "agent-registry-materializer-smoke\n";

	echo "\n[1] WordPress-shaped registry vocabulary collects definitions without materializing rows:\n";
	reset_agent_materializer_smoke();
	add_action(
		'wp_agents_api_init',
		static function (): void {
			wp_register_agent(
				new WP_Agent(
					'Example Agent!',
					array(
						'label'          => 'Example Agent',
						'description'    => 'Collect only',
						'memory_seeds'   => array( '../SOUL.md' => '/tmp/seed-soul.md' ),
						'owner_resolver' => static fn() => 7,
						'default_config' => array( 'default_provider' => 'openai' ),
					)
				)
			);
		}
	);
	do_action( 'init' );
	$definitions = AgentRegistry::get_all();
	assert_agent_materializer_equals( true, class_exists( 'WP_Agent' ), 'WP_Agent definition object is available', $failures, $passes );
	assert_agent_materializer_equals( true, class_exists( 'WP_Agents_Registry' ), 'WP_Agents_Registry facade is available', $failures, $passes );
	assert_agent_materializer_equals( array( 'example-agent' ), array_keys( $definitions ), 'definition slug is normalized', $failures, $passes );
	assert_agent_materializer_equals( 'Example Agent', $definitions['example-agent']['label'] ?? '', 'definition label is preserved', $failures, $passes );
	assert_agent_materializer_equals( array(), Agents::$rows, 'collecting definitions does not create agent rows', $failures, $passes );

	echo "\n[1b] wp_agents_api_init is the primary collection hook while legacy hook remains available:\n";
	reset_agent_materializer_smoke();
	add_action(
		'wp_agents_api_init',
		static function (): void {
			wp_register_agent(
				'hook-agent',
				array(
					'label'          => 'Hook Agent',
					'owner_resolver' => static fn() => 9,
				)
			);
		}
	);
	add_action(
		'datamachine_register_agents',
		static function (): void {
			datamachine_register_agent(
				'legacy-agent',
				array(
					'label'          => 'Legacy Agent',
					'owner_resolver' => static fn() => 11,
				)
			);
		}
	);
	do_action( 'init' );
	$definitions = AgentRegistry::get_all();
	assert_agent_materializer_equals( array( 'hook-agent', 'legacy-agent' ), array_keys( $definitions ), 'new and in-repo legacy hooks both contribute definitions', $failures, $passes );
	assert_agent_materializer_equals( array(), Agents::$rows, 'hook collection remains side-effect free', $failures, $passes );

	echo "\n[2] reconciliation creates rows, access grants, directories, scaffold calls, and action hooks:\n";
	reset_agent_materializer_smoke();
	add_action(
		'wp_agents_api_init',
		static function (): void {
			wp_register_agent(
				'Example Agent!',
				array(
					'label'          => 'Example Agent',
					'description'    => 'Collect only',
					'memory_seeds'   => array( '../SOUL.md' => '/tmp/seed-soul.md' ),
					'owner_resolver' => static fn() => 7,
					'default_config' => array( 'default_provider' => 'openai' ),
				)
			);
		}
	);
	do_action( 'init' );
	$summary = AgentRegistry::reconcile();
	assert_agent_materializer_equals( array( 'created' => array( 'example-agent' ), 'existing' => array(), 'definition_only' => array(), 'skipped' => array() ), $summary, 'created summary matches pre-split registry behavior', $failures, $passes );
	assert_agent_materializer_equals( 7, Agents::$rows['example-agent:7:default']['owner_id'] ?? 0, 'owner resolver controls created row owner', $failures, $passes );
	assert_agent_materializer_equals( array( 'default_provider' => 'openai' ), Agents::$rows['example-agent:7:default']['agent_config'] ?? array(), 'default config persists on creation', $failures, $passes );
	assert_agent_materializer_equals( array( array( 'agent_id' => 100, 'owner_id' => 7 ) ), AgentAccess::$bootstrapped, 'owner access is bootstrapped', $failures, $passes );
	assert_agent_materializer_equals( array( '/agents/example-agent' ), DirectoryManager::$ensured, 'agent directory is ensured', $failures, $passes );
	assert_agent_materializer_equals( array( array( 'layer' => 'agent', 'agent_slug' => 'example-agent', 'agent_id' => 100, 'owner_user_id' => 7, 'instance_key' => 'default' ) ), ScaffoldAbilities::$ability->calls, 'agent scaffold ability is executed', $failures, $passes );
	assert_agent_materializer_equals( 'example-agent', $GLOBALS['__agent_materializer_actions']['datamachine_registered_agent_reconciled'][0][1] ?? '', 'reconciled action still fires with slug', $failures, $passes );

	echo "\n[3] existing rows are adopted and mutable fields stay DB-owned:\n";
	$summary = AgentRegistry::reconcile();
	assert_agent_materializer_equals( array( 'created' => array(), 'existing' => array( 'example-agent' ), 'definition_only' => array(), 'skipped' => array() ), $summary, 'second reconcile reports existing row', $failures, $passes );
	assert_agent_materializer_equals( 1, count( AgentAccess::$bootstrapped ), 'existing row does not bootstrap access again', $failures, $passes );
	assert_agent_materializer_equals( 1, count( ScaffoldAbilities::$ability->calls ), 'existing row does not scaffold again', $failures, $passes );

	echo "\n[4] definition-only registration skips default reconciliation but supports exact explicit scopes:\n";
	reset_agent_materializer_smoke();
	DirectoryManager::$default_user_id = 19;
	add_action(
		'wp_agents_api_init',
		static function (): void {
			wp_register_agent(
				'definition-only-agent',
				array(
					'label'          => 'Definition Only Agent',
					'default_config' => array( 'default_provider' => 'openai' ),
					'meta'           => array( 'datamachine_default_materialization' => false ),
				)
			);
		}
	);
	do_action( 'init' );
	$summary = AgentRegistry::reconcile();
	assert_agent_materializer_equals( array( 'created' => array(), 'existing' => array(), 'definition_only' => array( 'definition-only-agent' ), 'skipped' => array() ), $summary, 'definition-only summary exposes the opt-out', $failures, $passes );
	assert_agent_materializer_equals( array(), Agents::$rows, 'definition-only reconciliation creates no default row', $failures, $passes );
	assert_agent_materializer_equals( array(), AgentAccess::$bootstrapped, 'definition-only reconciliation grants no owner access', $failures, $passes );
	assert_agent_materializer_equals( array(), DirectoryManager::$ensured, 'definition-only reconciliation creates no directory', $failures, $passes );
	assert_agent_materializer_equals( array(), ScaffoldAbilities::$ability->calls, 'definition-only reconciliation runs no scaffold', $failures, $passes );

	$identity = wp_materialize_agent_identity(
		'definition-only-agent',
		new AgentIdentityStoreAdapter(),
		array(
			'owner_user_id' => 23,
			'instance_key'  => 'workspace:one',
			'meta'          => array( 'label' => 'Explicit Agent' ),
		)
	);
	$repeat = wp_materialize_agent_identity(
		'definition-only-agent',
		new AgentIdentityStoreAdapter(),
		array(
			'owner_user_id' => 23,
			'instance_key'  => 'workspace:one',
		)
	);
	assert_agent_materializer_equals( 23, $identity->scope->owner_user_id ?? 0, 'explicit materialization preserves exact owner scope', $failures, $passes );
	assert_agent_materializer_equals( 'workspace:one', $identity->scope->instance_key ?? '', 'explicit materialization preserves exact instance scope', $failures, $passes );
	assert_agent_materializer_equals( $identity->id ?? 0, $repeat->id ?? -1, 'explicit materialization is idempotent', $failures, $passes );
	assert_agent_materializer_equals( 1, count( Agents::$rows ), 'explicit materialization creates only the requested identity', $failures, $passes );
	assert_agent_materializer_equals( array( array( 'agent_id' => 100, 'owner_id' => 23 ) ), AgentAccess::$bootstrapped, 'explicit materialization provisions owner access', $failures, $passes );
	assert_agent_materializer_equals( 1, count( ScaffoldAbilities::$ability->calls ), 'explicit materialization provisions one scaffold', $failures, $passes );

	\WP_Agents_Registry::get_instance()->register(
		'runtime-bundle-agent',
		array(
			'label'          => 'Runtime Bundle Agent',
			'owner_resolver' => static fn() => 31,
		)
	);
	$result = datamachine_reconcile_runtime_agent_bundle_import( array( 'success' => true, 'agent_slug' => 'runtime-bundle-agent' ) );
	assert_agent_materializer_equals( 'runtime-bundle-agent', $result['agent_slug'] ?? '', 'runtime import result passes through reconciliation', $failures, $passes );
	assert_agent_materializer_equals( 31, Agents::$rows['runtime-bundle-agent:31:default']['owner_id'] ?? 0, 'runtime bundle definitions retain default materialization', $failures, $passes );
	assert_agent_materializer_equals( false, isset( Agents::$rows['definition-only-agent:19:default'] ), 'bundle reconciliation does not materialize definition-only defaults', $failures, $passes );

	echo "\n[5] materializer owns Data Machine side-effect dependencies:\n";
	$registration_source = (string) file_get_contents( AGENTS_API_PATH . 'src/Registry/register-agents.php' );
	$registry_source     = (string) file_get_contents( __DIR__ . '/../inc/Engine/Agents/AgentRegistry.php' );
	$materializer_source = (string) file_get_contents( __DIR__ . '/../inc/Engine/Agents/AgentMaterializer.php' );
	assert_agent_materializer_equals( false, false !== strpos( $registration_source, 'datamachine_register_agent' ), 'Agents API registration helper does not define Data Machine legacy wrapper', $failures, $passes );
	assert_agent_materializer_equals( false, false !== strpos( $registry_source, 'Core\\Database\\Agents' ), 'registry no longer imports agent database repositories', $failures, $passes );
	assert_agent_materializer_equals( false, false !== strpos( $registry_source, 'ScaffoldAbilities' ), 'registry no longer imports scaffold ability', $failures, $passes );
	$identity_store_source = (string) file_get_contents( __DIR__ . '/../inc/Core/Identity/AgentIdentityStoreAdapter.php' );
	assert_agent_materializer_equals( false, false !== strpos( $materializer_source, 'Core\\Database\\Agents' ), 'materializer delegates database storage to identity store adapter', $failures, $passes );
	assert_agent_materializer_equals( true, false !== strpos( $identity_store_source, 'Core\\Database\\Agents' ), 'identity store adapter owns agent database repositories', $failures, $passes );
	assert_agent_materializer_equals( true, false !== strpos( $identity_store_source, 'ScaffoldAbilities' ), 'identity store adapter owns scaffold ability', $failures, $passes );

	if ( $failures ) {
		echo "\nFAILED: " . count( $failures ) . " agent registry/materializer assertions failed.\n";
		exit( 1 );
	}

	echo "\nAll {$passes} agent registry/materializer assertions passed.\n";
}
