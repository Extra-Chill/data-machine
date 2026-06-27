<?php
/**
 * Behavior smoke test for the generic import-agent ability slice (#1306).
 *
 * Run with: php tests/import-agent-ability-smoke.php
 */

namespace {
	// Use a throwaway ABSPATH so BundleSource's require_once resolves to
	// a stub file in /tmp instead of polluting the plugin tree.
	$datamachine_test_abspath = sys_get_temp_dir() . '/datamachine-import-agent-test-' . uniqid() . '/';
	@mkdir( $datamachine_test_abspath . 'wp-admin/includes', 0777, true );
	@file_put_contents( $datamachine_test_abspath . 'wp-admin/includes/file.php', "<?php\n// test stub\n" );
	define( 'ABSPATH', $datamachine_test_abspath );

	register_shutdown_function( function () use ( $datamachine_test_abspath ) {
		if ( ! is_dir( $datamachine_test_abspath ) ) {
			return;
		}
		foreach ( new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $datamachine_test_abspath, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		) as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}
		@rmdir( $datamachine_test_abspath );
	} );

	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code, string $message ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	class WP_Ability {
		public static array $last_execute = array();

		public function execute( array $input ): array {
			self::$last_execute = $input;

			return array(
				'success'    => true,
				'agent_id'   => 123,
				'agent_slug' => (string) ( $input['slug'] ?? $input['bundle']['agent']['agent_slug'] ?? '' ),
			);
		}
	}

	if ( ! function_exists( '__' ) ) {
		function __( string $text, string $domain = 'default' ): string {
			unset( $domain );
			return $text;
		}
	}

	if ( ! function_exists( 'sanitize_title' ) ) {
		function sanitize_title( string $title ): string {
			$title = strtolower( trim( $title ) );
			$title = preg_replace( '/[^a-z0-9]+/', '-', $title ) ?? '';
			return trim( $title, '-' );
		}
	}

	if ( ! function_exists( 'get_current_user_id' ) ) {
		function get_current_user_id(): int {
			return (int) ( $GLOBALS['datamachine_test_current_user_id'] ?? 0 );
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $name, mixed $default_value = false ): mixed {
			return $GLOBALS['datamachine_test_options'][ $name ] ?? $default_value;
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
			if ( 'datamachine_auth_ref_to_handler_config' !== $hook_name ) {
				return $value;
			}

			$resolver = $GLOBALS['datamachine_test_auth_resolver'] ?? null;
			return is_callable( $resolver ) ? $resolver( $value, ...$args ) : $value;
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( mixed $thing ): bool {
			return $thing instanceof WP_Error;
		}
	}

	function wp_delete_file( string $path ): void {
		if ( file_exists( $path ) ) {
			unlink( $path ); // phpcs:ignore
		}
	}

	function download_url( string $url, int $timeout = 30 ): mixed {
		return new WP_Error( 'no_stub', 'download_url is not stubbed.' );
	}

	function wp_get_ability( string $name ): ?WP_Ability {
		return 'datamachine/import-agent' === $name ? new WP_Ability() : null;
	}
	eval(
		<<<'PHP'
		namespace DataMachine\Core\Agents;

		class AgentBundler {
			public static array $last_import = array();
			public static int $from_directory_calls = 0;
			public static int $from_zip_calls = 0;
			public static int $from_json_calls = 0;

			public function from_directory( string $source ): ?array {
				++self::$from_directory_calls;
				return null;
			}

			public function from_zip( string $source ): ?array {
				++self::$from_zip_calls;
				return null;
			}

			public function from_json( string $json ): ?array {
				++self::$from_json_calls;
				$bundle = json_decode( $json, true );
				return is_array( $bundle ) ? $bundle : null;
			}

			public function import( array $bundle, ?string $new_slug = null, int $owner_id = 0, bool $dry_run = false, array $options = array() ): array {
				self::$last_import = compact( 'bundle', 'new_slug', 'owner_id', 'dry_run', 'options' );

				return array(
					'success' => true,
					'summary' => array(
						'agent_id'           => 123,
						'agent_slug'         => (string) ( $bundle['agent']['agent_slug'] ?? '' ),
						'pipelines_imported' => count( $bundle['pipelines'] ?? array() ),
						'flows_imported'     => count( $bundle['flows'] ?? array() ),
						'files'              => count( $bundle['files'] ?? array() ),
					),
				);
			}
		}
		PHP
	);

	eval(
		<<<'PHP'
		namespace DataMachine\Core\Database\Agents;

		class Agents {
			public static array $by_slug = array();

			public function get_by_slug( string $slug ): ?array {
				return self::$by_slug[ $slug ] ?? null;
			}
		}

		class AgentAccess {}
		PHP
	);

	eval( 'namespace DataMachine\\Core\\FilesRepository; class DirectoryManager {}' );

	eval(
		<<<'PHP'
		namespace DataMachine\Abilities;

		class PermissionHelper {
			public static array $context = array();

			public static function set_agent_context( int $agent_id, int $owner_id, array $scope, ?int $token_id = null ): void {
				self::$context = compact( 'agent_id', 'owner_id', 'scope', 'token_id' );
			}

			public static function clear_agent_context(): void {
				self::$context = array();
			}
		}
		PHP
	);
}

namespace DataMachine\Engine\Bundle {
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleSourceAuthResolverInterface.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleSourceResolverInterface.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/GitHubBundleSourceAuthResolver.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/GitHubBundleSourceResolver.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleSourceResolverRegistry.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleSourceAuth.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleSource.php';
}

namespace DataMachine\Abilities {
	require_once dirname( __DIR__ ) . '/inc/Abilities/AgentAbilities.php';
}

namespace {
	use DataMachine\Abilities\AgentAbilities;
	use DataMachine\Core\Agents\AgentBundler;
	use DataMachine\Core\Database\Agents\Agents;

	$assertions = 0;

	$assert = function ( string $label, bool $condition ) use ( &$assertions ): void {
		++$assertions;
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$label}\n" );
			exit( 1 );
		}
		echo "ok - {$label}\n";
	};

	$write_bundle = function ( array $bundle ): string {
		$path = tempnam( sys_get_temp_dir(), 'datamachine-import-agent-' );
		if ( false === $path ) {
			fwrite( STDERR, "FAIL: unable to create temp bundle\n" );
			exit( 1 );
		}
		$json_path = $path . '.json';
		rename( $path, $json_path );
		file_put_contents( $json_path, json_encode( $bundle ) );
		return $json_path;
	};

	$base_bundle = function ( string $slug = 'portable-agent' ): array {
		return array(
			'bundle_version' => '1',
			'agent'          => array(
				'agent_slug' => $slug,
				'agent_name' => 'Portable Agent',
			),
			'pipelines'      => array( array( 'pipeline_name' => 'Pipeline' ) ),
			'flows'          => array(
				array(
					'portable_slug'     => 'flow-one',
					'flow_name'         => 'Flow One',
					'scheduling_config' => array(
						'enabled'  => true,
						'interval' => 'hourly',
					),
					'flow_config'       => array(
						'step-one' => array(
							'handler_configs' => array(
								'resolvable' => array( 'auth_ref' => 'provider:local' ),
							),
						),
					),
				),
			),
			'files'          => array(),
		);
	};

	$reset = function (): void {
		// @phpstan-ignore-next-line smoke-test stub property shadows production class.
		AgentBundler::$last_import                   = array();
		AgentBundler::$from_directory_calls          = 0;
		AgentBundler::$from_zip_calls                = 0;
		AgentBundler::$from_json_calls               = 0;
		// @phpstan-ignore-next-line smoke-test stub property shadows production class.
		Agents::$by_slug                             = array();
		WP_Ability::$last_execute                    = array();
		$GLOBALS['datamachine_test_current_user_id'] = 42;
		$GLOBALS['datamachine_test_auth_resolver']   = null;
	};

	$agent_abilities_source = file_get_contents( dirname( __DIR__ ) . '/inc/Abilities/AgentAbilities.php' );
	$assert( 'import-agent execution validates source-or-bundle requirement', false !== strpos( $agent_abilities_source, 'Bundle source or inline bundle is required.' ) && false === strpos( $agent_abilities_source, "array( 'required' => array( 'bundle' ) )" ) );
	$assert( 'import-agent schema exposes inline bundle input', false !== strpos( $agent_abilities_source, "'bundle'      => array(" ) && false !== strpos( $agent_abilities_source, 'Already-parsed portable Data Machine agent bundle array' ) );
	$assert( 'import-agent schema keeps source as canonical path field', false !== strpos( $agent_abilities_source, "'source'            => array(" ) && false === strpos( $agent_abilities_source, "'source_path'" ) );
	$assert( 'runtime importer does not stage bundles through temp files', false === strpos( $agent_abilities_source, 'tempnam' ) && false === strpos( $agent_abilities_source, 'wp_tempnam' ) );

	echo "=== Import Agent Ability Behavior Smoke (#1306) ===\n";

	echo "\n[1] Conflict modes\n";
	$reset();
	// @phpstan-ignore-next-line smoke-test stub property shadows production class.
	Agents::$by_slug['portable-agent'] = array( 'agent_id' => 77 );
	$bundle_path                       = $write_bundle( $base_bundle() );
	$result                            = AgentAbilities::importAgent(
		array(
			'source'      => $bundle_path,
			'on_conflict' => 'error',
		)
	);
	$assert( 'conflict error fails', false === $result['success'] );
	// @phpstan-ignore-next-line smoke-test stub property shadows production class.
	$assert( 'conflict error happens before import writes', array() === AgentBundler::$last_import );

	$result = AgentAbilities::importAgent(
		array(
			'source'      => $bundle_path,
			'on_conflict' => 'skip',
		)
	);
	$assert( 'conflict skip succeeds', true === $result['success'] );
	$assert( 'conflict skip marks skipped', true === $result['skipped'] );
	// @phpstan-ignore-next-line smoke-test stub property shadows production class.
	$assert( 'conflict skip does not import', array() === AgentBundler::$last_import );

	$result = AgentAbilities::importAgent(
		array(
			'source'      => $bundle_path,
			'on_conflict' => 'overwrite',
		)
	);
	$assert( 'unsupported overwrite policy is rejected', false === $result['success'] );
	$assert( 'overwrite policy is not claimed', str_contains( $result['error'], 'error, skip, upgrade' ) );

	$result = AgentAbilities::importAgent(
		array(
			'source'      => $bundle_path,
			'on_conflict' => 'upgrade',
		)
	);
	$assert( 'conflict upgrade succeeds', true === $result['success'] );
	// @phpstan-ignore-next-line smoke-test stub property shadows production class.
	$assert( 'conflict upgrade reaches bundler import', ! empty( AgentBundler::$last_import ) );
	// @phpstan-ignore-next-line smoke-test stub property shadows production class.
	$assert( 'conflict upgrade passes upgrade option', true === AgentBundler::$last_import['options']['is_upgrade'] );
	// @phpstan-ignore-next-line smoke-test stub property shadows production class.
	$assert( 'conflict upgrade reconciles runtime artifacts', true === AgentBundler::$last_import['options']['reconcile_runtime'] );

	echo "\n[2] Accepted import path\n";
	$reset();
	$bundle_path = $write_bundle( $base_bundle() );
	$result      = AgentAbilities::importAgent(
		array(
			'source'   => $bundle_path,
			'slug'     => 'Renamed Agent',
			'owner_id' => 99,
		)
	);
	$assert( 'accepted import succeeds', true === $result['success'] );
	// @phpstan-ignore-next-line smoke-test stub property shadows production class.
	$assert( 'accepted import reaches bundler import', ! empty( AgentBundler::$last_import ) );
	// @phpstan-ignore-next-line smoke-test stub property shadows production class.
	$assert( 'slug override rewrites bundle before import', 'renamed-agent' === AgentBundler::$last_import['bundle']['agent']['agent_slug'] );
	// @phpstan-ignore-next-line smoke-test stub property shadows production class.
	$assert( 'owner_id passes through to bundler import', 99 === AgentBundler::$last_import['owner_id'] );
	// @phpstan-ignore-next-line smoke-test stub property shadows production class.
	$assert( 'ability lets bundler use rewritten bundle slug, not new_slug', null === AgentBundler::$last_import['new_slug'] );

	$reset();
	$missing_source = sys_get_temp_dir() . '/datamachine-missing-runtime-bundle-' . uniqid() . '.json';
	$result         = AgentAbilities::importAgent( array( 'source' => $missing_source ) );
	$assert( 'missing source import fails', false === $result['success'] );
	$assert( 'missing source diagnostic reports attempted source', $missing_source === ( $result['diagnostics']['source'] ?? '' ) );
	$assert( 'missing source diagnostic reports cwd', isset( $result['diagnostics']['cwd'] ) && is_string( $result['diagnostics']['cwd'] ) );
	$assert( 'missing source diagnostic reports context', 'local_path' === ( $result['diagnostics']['context'] ?? '' ) );
	$assert( 'missing source diagnostic reports expected shape', 'Readable local directory, .zip, or JSON file, or remote .zip/.json URL containing a Data Machine agent bundle.' === ( $result['diagnostics']['expected_shape'] ?? '' ) );

	$reset();
	$invalid_json_temp = tempnam( sys_get_temp_dir(), 'datamachine-invalid-runtime-bundle-' );
	$invalid_json_path = $invalid_json_temp . '.json';
	rename( $invalid_json_temp, $invalid_json_path );
	file_put_contents( $invalid_json_path, '{not valid json' );
	$result = AgentAbilities::importAgent( array( 'source' => $invalid_json_path ) );
	$assert( 'invalid source import fails', false === $result['success'] );
	$assert( 'invalid source diagnostic reports attempted source', $invalid_json_path === ( $result['diagnostics']['source'] ?? '' ) );
	$assert( 'invalid source diagnostic reports expected shape', 'Readable local directory, .zip, or JSON file, or remote .zip/.json URL containing a Data Machine agent bundle.' === ( $result['diagnostics']['expected_shape'] ?? '' ) );
	@unlink( $invalid_json_path );

	echo "\n[3] Inline bundle import path\n";
	$reset();
	$result = AgentAbilities::importAgent(
		array(
			'bundle'   => $base_bundle( 'inline-agent' ),
			'slug'     => 'Inline Runtime Agent',
			'owner_id' => 101,
		)
	);
	$assert( 'inline bundle import succeeds through public ability', true === $result['success'] );
	// @phpstan-ignore-next-line smoke-test stub property shadows production class.
	$assert( 'inline bundle import reaches bundler import', ! empty( AgentBundler::$last_import ) );
	// @phpstan-ignore-next-line smoke-test stub property shadows production class.
	$assert( 'inline bundle import does not use source loaders', 0 === AgentBundler::$from_directory_calls && 0 === AgentBundler::$from_zip_calls && 0 === AgentBundler::$from_json_calls );
	// @phpstan-ignore-next-line smoke-test stub property shadows production class.
	$assert( 'inline bundle slug override rewrites bundle before import', 'inline-runtime-agent' === AgentBundler::$last_import['bundle']['agent']['agent_slug'] );

	echo "\n[4] Runtime bundle importer normalization\n";
	$reset();
	$runtime_bundle = $base_bundle( 'runtime-inline-agent' );
	$result         = AgentAbilities::importRuntimeAgentBundle(
		null,
		array(
			'slug'        => 'runtime-inline-agent',
			'on_conflict' => 'upgrade',
			'bundle'      => $runtime_bundle,
			'token_env'   => 'DATAMACHINE_TEST_TOKEN',
			'junk'        => 'ignored',
		),
		array(
			'owner_id'  => 202,
			'dry_run'   => true,
			'source'    => '/tmp/should-not-win.json',
			'token'     => 'one-shot-token',
			'extra_key' => 'ignored',
		),
		0
	);
	$assert( 'runtime inline import succeeds through canonical ability', true === $result['success'] );
	$assert( 'runtime importer passes inline bundle through canonical ability', $runtime_bundle === WP_Ability::$last_execute['bundle'] );
	$assert( 'runtime importer preserves canonical slug', 'runtime-inline-agent' === WP_Ability::$last_execute['slug'] );
	$assert( 'runtime importer preserves owner_id from import defaults', 202 === WP_Ability::$last_execute['owner_id'] );
	$assert( 'runtime importer preserves on_conflict from spec', 'upgrade' === WP_Ability::$last_execute['on_conflict'] );
	$assert( 'runtime importer preserves dry_run from import defaults', true === WP_Ability::$last_execute['dry_run'] );
	$assert( 'runtime importer passes token fields only when present', 'one-shot-token' === WP_Ability::$last_execute['token'] && 'DATAMACHINE_TEST_TOKEN' === WP_Ability::$last_execute['token_env'] );
	$assert( 'runtime importer does not fabricate source for inline bundles', ! isset( WP_Ability::$last_execute['source'] ) );
	$assert( 'runtime importer drops non-canonical fields', ! isset( WP_Ability::$last_execute['junk'] ) && ! isset( WP_Ability::$last_execute['extra_key'] ) );

	echo "\n[5] Auth ref resolution\n";
	$reset();
	$GLOBALS['datamachine_test_auth_resolver'] = function ( array $handler_config, string $handler_slug ): array|WP_Error {
		if ( 'resolvable' === $handler_slug ) {
			return array(
				'account_id' => 'local-account',
				'token_id'   => 'local-token',
			);
		}

		return new WP_Error( 'missing_auth_ref', 'Credential is not connected locally.' );
	};

	$bundle                                           = $base_bundle();
	$bundle['flows'][0]['flow_config']['step-two']    = array(
		'handler_configs' => array(
			'missing' => array( 'auth_ref' => 'provider:missing' ),
		),
	);
	$bundle_path                                      = $write_bundle( $bundle );
	$result                                           = AgentAbilities::importAgent( array( 'source' => $bundle_path ) );
	// @phpstan-ignore-next-line smoke-test stub property shadows production class.
	$imported_bundle                                  = AgentBundler::$last_import['bundle'];
	$resolved_config                                  = $imported_bundle['flows'][0]['flow_config']['step-one']['handler_configs']['resolvable'];
	$unresolved_config                                = $imported_bundle['flows'][0]['flow_config']['step-two']['handler_configs']['missing'];
	$disabled_scheduling                              = $imported_bundle['flows'][0]['scheduling_config'];

	$assert( 'auth ref resolver import succeeds with warning', true === $result['success'] );
	$assert( 'resolved auth_ref rewrites handler config', 'local-account' === $resolved_config['account_id'] );
	$assert( 'resolved auth_ref is removed from handler config', ! isset( $resolved_config['auth_ref'] ) );
	$assert( 'unresolved auth_ref remains visible', 'provider:missing' === $unresolved_config['auth_ref'] );
	$assert( 'unresolved auth_ref returns one warning', 1 === count( $result['auth_warnings'] ) );
	$assert( 'warning names handler slug', 'missing' === $result['auth_warnings'][0]['handler_slug'] );
	$assert( 'warning carries resolver error code', 'missing_auth_ref' === $result['auth_warnings'][0]['code'] );
	$assert( 'flow with unresolved auth_ref is disabled', false === $disabled_scheduling['enabled'] );
	$assert( 'disabled flow is forced manual', 'manual' === $disabled_scheduling['interval'] );
	$assert( 'disabled flow records reason', 'unresolved_auth_ref' === $disabled_scheduling['disabled_reason'] );

	echo "\nAssertions: {$assertions}\n";
	echo "PASS\n";
}
