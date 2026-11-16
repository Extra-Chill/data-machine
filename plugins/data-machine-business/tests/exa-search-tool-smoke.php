<?php
/** Run: php tests/exa-search-tool-smoke.php */
/** Standalone Exa smoke test stubs; no WordPress or network required. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['exa_config'] = array();
	$GLOBALS['exa_caps']   = array();
	$GLOBALS['exa_manage'] = false;
	$GLOBALS['exa_abilities'] = array();
	class WP_Error {
		public function __construct( private $code, private $message, private $data = null ) {}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function __( $text ) { return $text; }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function current_user_can( $cap ) { return in_array( $cap, $GLOBALS['exa_caps'], true ); }
	function get_site_option( $key, $default = array() ) {
		if ( 'datamachine_search_config' !== $key ) { throw new RuntimeException( 'Unexpected option' ); }
		return $GLOBALS['exa_config'];
	}
	function update_site_option( $key, $value ) {
		if ( 'datamachine_search_config' !== $key ) { throw new RuntimeException( 'Unexpected option' ); }
		$GLOBALS['exa_config'] = $value;
		return true;
	}
	function wp_register_ability( $name, $definition ) { $GLOBALS['exa_abilities'][ $name ] = $definition; }
	function exa_check( $condition, $message ) {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}
}
namespace DataMachine\Abilities {
	class PermissionHelper {
		public static function can_manage() { return $GLOBALS['exa_manage']; }
	}
	class AbilityRegistration {
		public static function on_abilities_api_init( $callback ) { call_user_func( $callback ); }
	}
}
namespace DataMachine\Core {
	class HttpClient {
		public static function post( $url, $options = array() ) { throw new \RuntimeException( 'Network must not be used' ); }
	}
}
namespace DataMachine\Engine\AI\Tools {
	class BaseTool {
		public $configuration_id;
		public $registration;
		protected function registerConfigurationHandlers( $id ) { $this->configuration_id = $id; }
		protected function registerTool( ...$args ) { $this->registration = $args; }
	}
}
namespace {
	require dirname( __DIR__ ) . '/inc/Abilities/Search/ExaSearchAbility.php';
	require dirname( __DIR__ ) . '/inc/Tools/ExaSearch.php';


	use DataMachineBusiness\Abilities\Search\ExaSearchAbility as E;
	use DataMachineBusiness\Tools\ExaSearch;

	$tool = new ExaSearch();
	exa_check( 'exa_search' === $tool->configuration_id && 'exa_search' === $tool->registration[0], 'configuration and tool IDs' );
	exa_check( array( 'chat', 'pipeline' ) === $tool->registration[2] && array( 'access_level' => 'admin' ) === $tool->registration[3], 'tool contexts and access' );
	$definition = $tool->getToolDefinition();
	exa_check( ExaSearch::class === $definition['class'] && 'handle_tool_call' === $definition['method'] && $definition['requires_config'], 'tool definition' );
	exa_check( E::input_schema() === $definition['parameters'], 'tool exposes all ability parameters' );
	$fields = $tool->get_config_fields();
	exa_check( array( 'api_key' ) === array_keys( $fields ) && $fields['api_key']['required'], 'API key config field' );
	exa_check( array( 'other' ) === $tool->get_config_fields( array( 'other' ), 'google_search' ), 'other fields untouched' );
	exa_check( ! ExaSearch::is_configured(), 'initially unconfigured' );
	exa_check( ! $tool->save_configuration( null, 'exa_search', array() )['success'], 'key required' );
	exa_check( 'previous' === $tool->save_configuration( 'previous', 'other', array() ), 'other save ignored' );
	$GLOBALS['exa_config']['google_search'] = array( 'api_key' => 'GOOGLE', 'search_engine_id' => 'CX' );
	exa_check( $tool->save_configuration( null, 'exa_search', array( 'api_key' => ' KEY ' ) )['success'], 'save key' );
	exa_check( 'GOOGLE' === $GLOBALS['exa_config']['google_search']['api_key'] && array( 'api_key' => 'KEY' ) === ExaSearch::get_config(), 'preserves Google config and sanitizes key' );
	exa_check( ExaSearch::is_configured() && $tool->check_configuration( false, 'exa_search' ), 'configuration check' );
	exa_check( 'previous' === $tool->check_configuration( 'previous', 'other' ) && 'previous' === $tool->get_configuration( 'previous', 'other' ), 'other configuration untouched' );
	exa_check( ExaSearch::get_config() === $tool->get_configuration( null, 'exa_search' ), 'configuration lookup' );
	$body = null;
	E::$http_post = static function ( $url, $options ) use ( &$body ) {
		$body = json_decode( $options['body'], true );
		return array( 'success' => true, 'data' => '{"results":[{"title":"Delegated"}]}' );
	};
	$r = $tool->handle_tool_call( array( 'query' => 'test', 'num_results' => 7, 'type' => 'fast', 'include_text' => false ) );
	exa_check( $r['success'] && 'exa_search' === $r['tool_name'] && 'Delegated' === $r['data']['results'][0]['title'], 'delegated result' );
	exa_check( 7 === $body['numResults'] && 'fast' === $body['type'] && ! isset( $body['contents'] ), 'all parameters delegated' );
	$GLOBALS['exa_config']['exa_search'] = array();
	$r = $tool->handle_tool_call( array( 'query' => 'test' ) );
	exa_check( ! $r['success'] && str_contains( $r['error'], 'Exa Search is not configured' ) && 'exa_search' === $r['tool_name'], 'ability errors propagated' );
	$providers = file_get_contents( dirname( __DIR__ ) . '/inc/Bootstrap/ProviderModules.php' );
	exa_check( str_contains( $providers, 'new \\DataMachineBusiness\\Tools\\ExaSearch()' ), 'provider instantiates tool' );
	echo "Exa Search tool smoke passed.\n";
}
