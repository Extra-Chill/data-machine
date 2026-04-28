<?php
/**
 * Behavior smoke test for the generic import-agent ability slice (#1306).
 *
 * Run with: php tests/import-agent-ability-smoke.php
 */

namespace {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );

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

	function sanitize_title( string $title ): string {
		$title = strtolower( trim( $title ) );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title ) ?? '';
		return trim( $title, '-' );
	}

	function get_current_user_id(): int {
		return (int) ( $GLOBALS['datamachine_test_current_user_id'] ?? 0 );
	}

	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['datamachine_test_options'][ $name ] ?? $default;
	}

	function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
		if ( 'datamachine_auth_ref_to_handler_config' !== $hook_name ) {
			return $value;
		}

		$resolver = $GLOBALS['datamachine_test_auth_resolver'] ?? null;
		return is_callable( $resolver ) ? $resolver( $value, ...$args ) : $value;
	}

	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

namespace DataMachine\Core\Agents {
	class AgentBundler {
		public static array $last_import = array();

		public function from_directory( string $source ): ?array {
			return null;
		}

		public function from_zip( string $source ): ?array {
			return null;
		}

		public function from_json( string $json ): ?array {
			$bundle = json_decode( $json, true );
			return is_array( $bundle ) ? $bundle : null;
		}

		public function import( array $bundle, ?string $new_slug = null, int $owner_id = 0, bool $dry_run = false ): array {
			self::$last_import = compact( 'bundle', 'new_slug', 'owner_id', 'dry_run' );

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
}

namespace DataMachine\Core\Database\Agents {
	class Agents {
		public static array $by_slug = array();

		public function get_by_slug( string $slug ): ?array {
			return self::$by_slug[ $slug ] ?? null;
		}
	}

	class AgentAccess {}
}

namespace DataMachine\Core\FilesRepository {
	class DirectoryManager {}
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
		AgentBundler::$last_import                   = array();
		Agents::$by_slug                             = array();
		$GLOBALS['datamachine_test_current_user_id'] = 42;
		$GLOBALS['datamachine_test_auth_resolver']   = null;
	};

	echo "=== Import Agent Ability Behavior Smoke (#1306) ===\n";

	echo "\n[1] Conflict modes\n";
	$reset();
	Agents::$by_slug['portable-agent'] = array( 'agent_id' => 77 );
	$bundle_path                       = $write_bundle( $base_bundle() );
	$result                            = AgentAbilities::importAgent(
		array(
			'source'      => $bundle_path,
			'on_conflict' => 'error',
		)
	);
	$assert( 'conflict error fails', false === $result['success'] );
	$assert( 'conflict error happens before import writes', array() === AgentBundler::$last_import );

	$result = AgentAbilities::importAgent(
		array(
			'source'      => $bundle_path,
			'on_conflict' => 'skip',
		)
	);
	$assert( 'conflict skip succeeds', true === $result['success'] );
	$assert( 'conflict skip marks skipped', true === $result['skipped'] );
	$assert( 'conflict skip does not import', array() === AgentBundler::$last_import );

	$result = AgentAbilities::importAgent(
		array(
			'source'      => $bundle_path,
			'on_conflict' => 'replace',
		)
	);
	$assert( 'unsupported replace policy is rejected', false === $result['success'] );
	$assert( 'replace policy is no longer claimed', str_contains( $result['error'], 'error, skip' ) );

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
	$assert( 'accepted import reaches bundler import', ! empty( AgentBundler::$last_import ) );
	$assert( 'slug override rewrites bundle before import', 'renamed-agent' === AgentBundler::$last_import['bundle']['agent']['agent_slug'] );
	$assert( 'owner_id passes through to bundler import', 99 === AgentBundler::$last_import['owner_id'] );
	$assert( 'ability lets bundler use rewritten bundle slug, not new_slug', null === AgentBundler::$last_import['new_slug'] );

	echo "\n[3] Auth ref resolution\n";
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
