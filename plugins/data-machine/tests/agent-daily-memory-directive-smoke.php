<?php
/**
 * Pure-PHP smoke test for agent daily memory prompt injection.
 *
 * Run with: php tests/agent-daily-memory-directive-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core\Database\Agents {
    class Agents {
        public function get_agent( int $agent_id ): ?array {
            return $GLOBALS['__agent_daily_memory_directive_agents'][ $agent_id ] ?? null;
        }
    }
}

namespace DataMachine\Core\FilesRepository {
    class AgentMemory {
        public const MAX_FILE_SIZE = 1000000;
    }

    class DailyMemory {
        public function __construct( int $user_id = 0, int $agent_id = 0 ) {
            $GLOBALS['__agent_daily_memory_directive_scope'] = array(
                'user_id'  => $user_id,
                'agent_id' => $agent_id,
            );
        }

        public static function parse_date( string $date ) {
            $parts = explode( '-', $date );
            if ( 3 !== count( $parts ) ) {
                return false;
            }

            return array(
                'year'  => $parts[0],
                'month' => $parts[1],
                'day'   => $parts[2],
            );
        }

        public function exists( string $year, string $month, string $day ): bool {
            return isset( $GLOBALS['__agent_daily_memory_directive_files']["{$year}-{$month}-{$day}"] );
        }

        public function read( string $year, string $month, string $day ): array {
            $date = "{$year}-{$month}-{$day}";
            return array(
                'success' => true,
                'date'    => $date,
                'content' => $GLOBALS['__agent_daily_memory_directive_files'][ $date ] ?? '',
            );
        }
    }
}

namespace {
    if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', __DIR__ . '/' );
    }

    if ( ! function_exists( 'add_filter' ) ) {
        function add_filter( string $_hook, callable $_callback, int $_priority = 10, int $_accepted_args = 1 ): void {}
    }

    if ( ! function_exists( 'wp_json_encode' ) ) {
        function wp_json_encode( $value, int $flags = 0 ) {
            return json_encode( $value, $flags );
        }
    }

    require_once __DIR__ . '/../inc/Engine/AI/Directives/DirectiveInterface.php';
    require_once __DIR__ . '/../inc/Engine/AI/Directives/DirectiveOutputValidator.php';
    require_once __DIR__ . '/../inc/Engine/AI/Directives/DirectiveRenderer.php';
    require_once __DIR__ . '/agents-api-loader.php';
    datamachine_tests_require_agents_api();
    require_once __DIR__ . '/../inc/Engine/AI/PromptBuilder.php';
    require_once __DIR__ . '/../inc/Engine/AI/Directives/AgentDailyMemoryDirective.php';

    $failures   = 0;
    $assertions = 0;

    function agent_daily_memory_directive_assert( bool $condition, string $message ): void {
        global $failures, $assertions;
        ++$assertions;
        if ( $condition ) {
            echo "  [PASS] {$message}\n";
            return;
        }

        echo "  [FAIL] {$message}\n";
        ++$failures;
    }

    $today = gmdate( 'Y-m-d' );

    $GLOBALS['__agent_daily_memory_directive_agents'][123] = array(
        'agent_config' => array(
            'daily_memory' => array(
                'enabled'     => true,
                'recent_days' => 1,
            ),
        ),
    );
    $GLOBALS['__agent_daily_memory_directive_files'][ $today ] = 'Remember the upstream directive seam.';

    $result = ( new \DataMachine\Engine\AI\PromptBuilder() )
        ->setMessages(
            array(
                array(
                    'role'    => 'user',
                    'content' => 'Use memory.',
                ),
            )
        )
        ->addDirective( \DataMachine\Engine\AI\Directives\AgentDailyMemoryDirective::class, 35, array( 'chat' ) )
        ->buildDetailed(
			array( 'chat' ),
            'openai',
            array(
                'agent_id' => 123,
                'user_id'  => 456,
            )
        );

    $messages = $result['messages'];
    $content  = $messages[0]['content'] ?? '';

    agent_daily_memory_directive_assert(
        in_array( 'AgentDailyMemoryDirective', $result['applied_directives'], true ),
        'daily memory directive is executed by PromptBuilder'
    );
    agent_daily_memory_directive_assert( 'system' === ( $messages[0]['role'] ?? '' ), 'daily memory is prepended as a system message' );
    agent_daily_memory_directive_assert( false !== strpos( $content, "Daily Memory: {$today}" ), 'daily memory system text includes today label' );
    agent_daily_memory_directive_assert( false !== strpos( $content, 'Remember the upstream directive seam.' ), 'daily memory system text includes file content' );
    agent_daily_memory_directive_assert(
        array( 'user_id' => 456, 'agent_id' => 123 ) === ( $GLOBALS['__agent_daily_memory_directive_scope'] ?? null ),
        'daily memory reader receives payload scope'
    );

    echo "\n{$assertions} assertions, {$failures} failures\n";
    if ( $failures > 0 ) {
        exit( 1 );
    }
}
