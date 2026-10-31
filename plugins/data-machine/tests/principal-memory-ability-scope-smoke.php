<?php
/**
 * Pure-PHP contract test for principal-derived memory ability addressing.
 *
 * Run with: php tests/principal-memory-ability-scope-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

class WP_Error {
	public function __construct( public string $code, public string $message, public array $data = array() ) {}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function sanitize_title( string $value ): string {
	return strtolower( trim( $value ) );
}

eval( 'namespace DataMachine\\Abilities; class PermissionHelper { public static ?object $principal = null; public static ?int $agent = null; public static function get_execution_principal(): ?object { return self::$principal; } public static function get_acting_agent_id(): ?int { return self::$agent; } }' );
eval( 'namespace DataMachine\\Core\\Database\\Agents; class Agents { public function get_by_slug( string $slug ): ?array { return "north" === $slug ? [ "agent_id" => 42 ] : null; } }' );
eval( 'namespace DataMachine\\Engine\\AI; class MemoryFileRegistry { public const LAYER_PRINCIPAL = "principal"; }' );
eval( 'namespace DataMachine\\Core\\FilesRepository; class AgentMemory { public function __construct( public int $user_id, public int $agent_id, public string $filename ) {} public static function resolve_layer_for( string $filename ): string { return "USER_MEMORY.md" === $filename ? "principal" : "agent"; } public function get_all(): array { return [ "success" => true, "user_id" => $this->user_id, "agent_id" => $this->agent_id, "filename" => $this->filename ]; } }' );

require_once __DIR__ . '/../inc/Abilities/AgentMemoryAbilities.php';

DataMachine\Abilities\PermissionHelper::$principal = (object) array(
	'acting_user_id'     => 7,
	'effective_agent_id' => 'north',
);
$resolved = DataMachine\Abilities\AgentMemoryAbilities::getMemory(
	array(
		'user_id'  => 999,
		'agent_id' => 888,
		'file'     => 'USER_MEMORY.md',
	)
);

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( $condition ) {
		fwrite( STDOUT, "PASS {$label}\n" );
		return;
	}
	$failures[] = $label;
	fwrite( STDERR, "FAIL {$label}\n" );
};

$assert( 7 === ( $resolved['user_id'] ?? 0 ), 'authenticated principal overrides caller-supplied user ID' );
$assert( 42 === ( $resolved['agent_id'] ?? 0 ), 'effective agent overrides caller-supplied agent ID' );

DataMachine\Abilities\PermissionHelper::$agent     = 42;
DataMachine\Abilities\PermissionHelper::$principal = (object) array(
	'acting_user_id'     => 7,
	'effective_agent_id' => 'agent:84',
);
$delegated = DataMachine\Abilities\AgentMemoryAbilities::getMemory( array( 'file' => 'USER_MEMORY.md' ) );
$assert( 84 === ( $delegated['agent_id'] ?? 0 ), 'delegated effective agent overrides the acting agent' );

DataMachine\Abilities\PermissionHelper::$principal = (object) array(
	'acting_user_id'     => 0,
	'effective_agent_id' => 'north',
);
$denied = DataMachine\Abilities\AgentMemoryAbilities::getMemory( array( 'file' => 'USER_MEMORY.md' ) );
$assert( is_wp_error( $denied ) && 'principal_memory_scope_unavailable' === $denied->code, 'principal memory fails closed without an authenticated user' );

if ( $failures ) {
	exit( 1 );
}

fwrite( STDOUT, "Principal memory ability scope contract passed.\n" );
