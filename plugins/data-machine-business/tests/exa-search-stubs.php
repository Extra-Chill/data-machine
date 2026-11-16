<?php
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
}
