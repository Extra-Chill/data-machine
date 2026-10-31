<?php
/** REST human principal resolution and self-memory policy regression. */
namespace {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	$GLOBALS['user'] = 38;
	$GLOBALS['filters'] = array();
	function add_filter( $name, $callback, $priority = 10, $args = 1 ) { $GLOBALS['filters'][$name][] = $callback; }
	function apply_filters( $name, $value, ...$args ) {
		foreach ( $GLOBALS['filters'][$name] ?? array() as $callback ) { $value = $callback( $value, ...$args ); }
		return $value;
	}
	function get_current_user_id() { return $GLOBALS['user']; }
	function is_user_logged_in() { return get_current_user_id() > 0; }
	function user_can( $user, $cap ) { return 1 === $user; }
	function current_user_can( $cap ) { return user_can( get_current_user_id(), $cap ); }
	function doing_action( $name ) { return false; }
	function absint( $value ) { return abs( (int) $value ); }
	function esc_html( $value ) { return htmlspecialchars( $value ); }
	function sanitize_title( $value ) { return strtolower( trim( $value ) ); }
	function sanitize_key( $value ) { return strtolower( $value ); }
	class WP_REST_Request {
		private array $headers = array();
		public function set_header( $key, $value ) { $this->headers[strtolower( $key )] = $value; }
		public function get_header( $key ) { return $this->headers[strtolower( $key )] ?? null; }
	}
	require __DIR__ . '/agents-api-loader.php';
	datamachine_tests_require_agents_api();
	require __DIR__ . '/../vendor/autoload.php';
}
namespace DataMachine\Core {
	class PluginSettings {
		public static string $default = '';
		public static function get( $key, $fallback ) { return self::$default; }
	}
}
namespace DataMachine\Core\Database\Agents {
	class Agents {
		public function get_agent( $id ) { return in_array( $id, array( 1, 2 ), true ) ? array( 'agent_id' => $id, 'agent_slug' => 1 === $id ? 'north' : 'south', 'owner_id' => 1 ) : null; }
		public function get_by_slug( $slug ) { return 'north' === $slug ? $this->get_agent( 1 ) : ( 'south' === $slug ? $this->get_agent( 2 ) : null ); }
	}
	class AgentAccess {
		public static bool $granted = true;
		public static string $role = 'operator';
		public function get_access( $agent, $user ) {
			return self::$granted && '1' === $agent && 38 === $user ? new \WP_Agent_Access_Grant( $agent, $user, self::$role ) : null;
		}
	}
}
namespace DataMachine\Core\FilesRepository {
	class AgentMemory {
		public static array $writes = array();
		public function __construct( public int $user, public int $agent, public string $file ) {}
		public function set_section( $section, $content ) { self::$writes[] = array( $this->user, $this->agent, $this->file ); return array( 'success' => true ); }
		public function append_to_section( $section, $content ) { return $this->set_section( $section, $content ); }
	}
}
namespace DataMachine\Engine\AI {
	class DataMachineAgentConsentPolicy {
		public static function get() { return new self(); }
		public function can_store_memory( $args ) { return new class { public function is_allowed() { return true; } }; }
	}
}
namespace {
	require __DIR__ . '/../inc/Abilities/PermissionHelper.php';
	require __DIR__ . '/../inc/Core/Agents/AgentIdentity.php';
	require __DIR__ . '/../inc/Core/Agents/AgentIdentityResolver.php';
	require __DIR__ . '/../inc/Core/Auth/UserSessionAgentContext.php';
	require __DIR__ . '/../inc/Engine/AI/Memory/SelfMemoryWritePolicy.php';
	require __DIR__ . '/../inc/Engine/AI/Memory/MemorySectionArtifact.php';

	use DataMachine\Abilities\PermissionHelper as P;
	use DataMachine\Core\Auth\UserSessionAgentContext;
	use DataMachine\Core\PluginSettings;
	use DataMachine\Core\Database\Agents\AgentAccess;
	use DataMachine\Core\FilesRepository\AgentMemory;
	use DataMachine\Engine\AI\Memory\SelfMemoryWritePolicy as Policy;
	use AgentsAPI\AI\WP_Agent_Execution_Principal as Principal;

	function check( $condition, $label ) {
		if ( ! $condition ) { throw new \RuntimeException( $label ); }
		echo "PASS $label\n";
	}
	$resolver = new UserSessionAgentContext();
	$input = array( 'file' => 'USER_MEMORY.md', 'section' => 'Preferences', 'content' => 'Remember this', 'user_id' => 999 );
	foreach ( array( null, 'north', '1' ) as $selector ) {
		PluginSettings::$default = 'north';
		$request = new WP_REST_Request();
		if ( null !== $selector ) { $request->set_header( 'X-Datamachine-Agent', $selector ); }
		apply_filters( 'rest_request_before_callbacks', null, array(), $request );
		check( 38 === get_current_user_id() && 38 === P::acting_user_id(), 'human identity unchanged' );
		check( 1 === P::get_acting_agent_id() && null === P::get_acting_token_id(), 'effective agent resolved without token context' );
		check( ! P::can( 'manage_agents' ), 'owner capabilities never borrowed' );
		check( Principal::resolve() === P::get_execution_principal(), 'Agents API exposes same principal' );
		$result = Policy::execute( $input );
		check( $result['success'] && array( 38, 1, 'USER_MEMORY.md' ) === end( AgentMemory::$writes ), 'write scopes to human plus agent, ignoring supplied user' );
		check( 'cross_agent_denied' === Policy::execute( array_merge( $input, array( 'agent_id' => 2 ) ) )['error_code'], 'cross-agent denied' );
		check( 'durable_fact_denied' === Policy::execute( array_merge( $input, array( 'file' => 'MEMORY.md', 'section_type' => 'fact' ) ) )['error_code'], 'shared durable facts denied' );
		check( Policy::execute( array_merge( $input, array( 'file' => 'MEMORY.md' ) ) )['success'], 'shared operational note permitted' );
		apply_filters( 'rest_request_after_callbacks', null, array(), $request );
		check( null === P::get_execution_principal() && null === P::get_acting_agent_id(), 'scope cleaned after dispatch' );
	}
	// Both supported setting forms resolve identically; an explicit selector
	// takes precedence even when the configured default is inaccessible.
	foreach ( array( array( '1', null ), array( 'south', 'north' ) ) as [ $default, $selector ] ) {
		PluginSettings::$default = $default;
		$request = new WP_REST_Request();
		if ( null !== $selector ) { $request->set_header( 'X-Datamachine-Agent', $selector ); }
		$resolver->resolve( null, array(), $request );
		check( 1 === P::get_acting_agent_id(), 'numeric default and explicit selector precedence resolve granted agent' );
		$resolver->restore( null, array(), $request );
	}
	PluginSettings::$default = 'north';
	$request = new WP_REST_Request();
	$error = new \stdClass();
	check( $error === $resolver->resolve( $error, array(), $request ) && null === P::get_execution_principal(), 'failed authentication response is preserved without a principal' );
	$resolver->restore( $error, array(), $request );

	// An owner and an operator on the same agent must never share USER_MEMORY.
	$GLOBALS['user'] = 1;
	$resolver->resolve( null, array(), $request );
	$result = Policy::execute( array_merge( $input, array( 'user_id' => 38 ) ) );
	check( $result['success'] && array( 1, 1, 'USER_MEMORY.md' ) === end( AgentMemory::$writes ), 'owner writes only own private scope despite another user selector' );
	$resolver->restore( null, array(), $request );
	$GLOBALS['user'] = 38;
	$resolver->resolve( null, array(), $request );
	$result = Policy::execute( array_merge( $input, array( 'user_id' => 1 ) ) );
	check( $result['success'] && array( 38, 1, 'USER_MEMORY.md' ) === end( AgentMemory::$writes ), 'operator cannot reach owner private memory' );
	$resolver->restore( null, array(), $request );

	$outer = new WP_REST_Request();
	$resolver->resolve( null, array(), $outer );
	$outer_principal = P::get_execution_principal();
	$nested = new WP_REST_Request();
	$nested->set_header( 'X-Datamachine-Agent', 'south' );
	$resolver->resolve( null, array(), $nested );
	check( null === P::get_execution_principal() && 'missing_agent_context' === Policy::execute( $input )['error_code'], 'nested unauthorized selector cannot inherit outer scope' );
	$resolver->restore( null, array(), $nested );
	check( $outer_principal === P::get_execution_principal(), 'outer principal restored after denied nested dispatch' );
	$nested = new WP_REST_Request();
	$resolver->resolve( null, array(), $nested );
	check( 1 === P::get_acting_agent_id(), 'nested authorized dispatch resolves independently' );
	$resolver->restore( null, array(), $nested );
	check( $outer_principal === P::get_execution_principal(), 'outer principal restored after authorized nested dispatch' );
	$resolver->restore( null, array(), $outer );
	check( null === P::get_execution_principal(), 'nested dispatch leaves no principal behind' );
	foreach ( array( 'south', '2', 'missing', '' ) as $selector ) {
		$request = new WP_REST_Request();
		$request->set_header( 'X-Datamachine-Agent', $selector );
		$resolver->resolve( null, array(), $request );
		check( null === P::get_execution_principal(), 'invalid or unauthorized selector never uses default: ' . $selector );
		$resolver->restore( null, array(), $request );
	}
	$request = new WP_REST_Request();
	foreach ( array( 'viewer', 'none' ) as $role ) {
		AgentAccess::$role = $role;
		AgentAccess::$granted = 'none' !== $role;
		$count = count( AgentMemory::$writes );
		$resolver->resolve( null, array(), $request );
		check( null === P::get_execution_principal() && 'missing_agent_context' === Policy::execute( $input )['error_code'] && $count === count( AgentMemory::$writes ), 'no operator grant preserves missing_agent_context and writes nothing' );
		$resolver->restore( null, array(), $request );
	}
	$GLOBALS['user'] = 0;
	AgentAccess::$granted = true;
	$resolver->resolve( null, array(), $request );
	check( null === P::get_execution_principal(), 'anonymous caller gets no principal' );
	$resolver->restore( null, array(), $request );
	$GLOBALS['user'] = 1;
	P::set_agent_context( 2, 1, null, 12 );
	$token = P::get_execution_principal();
	$resolver->resolve( null, array(), $request );
	$resolver->restore( null, array(), $request );
	check( $token === P::get_execution_principal() && 1 === get_current_user_id() && 12 === P::get_acting_token_id() && 2 === P::get_acting_agent_id() && P::can( 'manage_agents' ), 'token principal, capabilities, agent, token ID and owner identity untouched' );
	P::clear_agent_context();
	PluginSettings::$default = '';
	$resolver->resolve( null, array(), $request );
	check( null === P::get_execution_principal(), 'unset default preserves existing behavior' );
}
