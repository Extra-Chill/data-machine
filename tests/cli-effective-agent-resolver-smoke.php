<?php
/**
 * Pure-PHP smoke test for Data Machine CLI effective agent resolution.
 *
 * Run with: php tests/cli-effective-agent-resolver-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$failures = array();
	$passes   = 0;

	echo "datamachine-cli-effective-agent-resolver-smoke\n";

	require_once __DIR__ . '/agents-api-smoke-helpers.php';
	datamachine_tests_require_agents_api();

	class WP_CLI {
		public static function error( string $message ): void {
			throw new RuntimeException( $message );
		}
	}

	$GLOBALS['__datamachine_test_user_meta'] = array();

	function get_user_meta( int $user_id, string $key, bool $single = false ) {
		unset( $single );
		return $GLOBALS['__datamachine_test_user_meta'][ $user_id ][ $key ] ?? '';
	}
}

namespace DataMachine\Core\Database\Agents {
	class Agents {
		public static array $rows = array();

		public function get_agent( int $agent_id ): ?array {
			foreach ( self::$rows as $row ) {
				if ( (int) $row['agent_id'] === $agent_id ) {
					return $row;
				}
			}

			return null;
		}

		public function get_by_slug( string $agent_slug ): ?array {
			foreach ( self::$rows as $row ) {
				if ( $row['agent_slug'] === $agent_slug ) {
					return $row;
				}
			}

			return null;
		}

		public function get_all_by_owner_id( int $owner_id ): array {
			return array_values( array_filter( self::$rows, static fn( $row ) => (int) $row['owner_id'] === $owner_id ) );
		}
	}
}

namespace DataMachine\Core\FilesRepository {
	class DirectoryManager {
		public function get_effective_user_id( int $user_id = 0 ): int {
			return $user_id > 0 ? $user_id : 1;
		}
	}
}

namespace DataMachine\Cli {
	class UserResolver {
		public static function resolve( array $assoc_args ): int {
			return (int) ( $assoc_args['user'] ?? 0 );
		}
	}
}

namespace {
	require_once __DIR__ . '/../inc/Core/Agents/AgentIdentity.php';
	require_once __DIR__ . '/../inc/Core/Agents/AgentIdentityResolver.php';
	require_once __DIR__ . '/../inc/Cli/AgentResolver.php';

	DataMachine\Core\Database\Agents\Agents::$rows = array(
		array(
			'agent_id'   => 1,
			'agent_slug' => 'admin',
			'owner_id'   => 1,
		),
		array(
			'agent_id'   => 2,
			'agent_slug' => 'intelligence-chubes4',
			'owner_id'   => 1,
		),
		array(
			'agent_id'   => 3,
			'agent_slug' => 'solo-agent',
			'owner_id'   => 2,
		),
	);

	$context = DataMachine\Cli\AgentResolver::resolveEffectiveContext( array( 'agent' => 'intelligence-chubes4' ) );
	agents_api_smoke_assert_equals( 2, $context['agent_id'], 'explicit --agent resolves to agent id', $failures, $passes );
	agents_api_smoke_assert_equals( 1, $context['user_id'], 'explicit --agent carries owner user id', $failures, $passes );
	agents_api_smoke_assert_equals( 'intelligence-chubes4', $context['agent_slug'], 'explicit --agent carries slug', $failures, $passes );

	$context = DataMachine\Cli\AgentResolver::resolveEffectiveContext( array( 'user' => 2 ) );
	agents_api_smoke_assert_equals( 3, $context['agent_id'], 'single owned agent resolves from owner fallback', $failures, $passes );
	agents_api_smoke_assert_equals( 'solo-agent', $context['agent_slug'], 'single owner fallback carries slug', $failures, $passes );

	try {
		DataMachine\Cli\AgentResolver::resolveEffectiveContext( array( 'user' => 1 ) );
		agents_api_smoke_assert_equals( true, false, 'ambiguous owner fallback is rejected', $failures, $passes );
	} catch ( RuntimeException $e ) {
		agents_api_smoke_assert_equals( true, str_contains( $e->getMessage(), 'ambiguous' ), 'ambiguous owner fallback is rejected', $failures, $passes );
	}

	$GLOBALS['__datamachine_test_user_meta'][1]['datamachine_active_agent_slug'] = 'intelligence-chubes4';
	$context = DataMachine\Cli\AgentResolver::resolveEffectiveContext( array( 'user' => 1 ) );
	agents_api_smoke_assert_equals( 2, $context['agent_id'], 'active preference resolves before ambiguous owner fallback', $failures, $passes );
	agents_api_smoke_assert_equals( 'intelligence-chubes4', $context['agent_slug'], 'active preference carries slug', $failures, $passes );

	$context = DataMachine\Cli\AgentResolver::resolveEffectiveContext( array( 'agent' => 'admin', 'user' => 1 ) );
	agents_api_smoke_assert_equals( 1, $context['agent_id'], 'explicit --agent overrides active preference', $failures, $passes );

	agents_api_smoke_finish( 'Data Machine CLI effective agent resolver', $failures, $passes );
}
