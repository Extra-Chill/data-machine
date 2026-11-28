<?php
/** Run: php tests/exa-search-ability-smoke.php */
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

	new E();
	new E();
	$definition = $GLOBALS['exa_abilities']['datamachine/exa-search'];
	exa_check( 'Exa Search' === $definition['label'] && $definition['meta']['show_in_rest'], 'ability registration' );
	exa_check( array( 'query' ) === $definition['input_schema']['required'], 'query required' );
	exa_check( ! E::can_search(), 'subscriber denied' );
	$GLOBALS['exa_caps'] = array( 'edit_posts' );
	exa_check( E::can_search(), 'authors allowed' );
	$GLOBALS['exa_caps'] = array();
	$GLOBALS['exa_manage'] = true;
	exa_check( E::can_search(), 'managers allowed' );

	$calls = 0;
	$seen = array();
	E::$http_post = static function ( $url, $options ) use ( &$calls, &$seen ) {
		++$calls;
		$seen = array( $url, $options, json_decode( $options['body'], true ) );
		return array(
			'success' => true,
			'status_code' => 200,
			'data' => json_encode( array( 'results' => array(
				array( 'title' => 'Title', 'url' => 'https://example.com', 'publishedDate' => '2026-01-01', 'author' => 'Author', 'text' => 'Extract', 'id' => 'ignored' ),
				array( 'title' => 'Partial' ),
			) ) ),
		);
	};
	$r = E::execute( array( 'query' => 'test' ) );
	exa_check( is_wp_error( $r ) && 'exa_search_not_configured' === $r->get_error_code() && 503 === $r->get_error_data()['status'], 'missing config' );
	foreach ( array( 'Exa API key', 'Data Machine > Agent > Tools > Exa Search', 'datamachine/save-tool-config', 'exa_search' ) as $guidance ) {
		exa_check( str_contains( $r->get_error_message(), $guidance ), 'operator guidance: ' . $guidance );
	}
	exa_check( 0 === $calls, 'no HTTP without config' );
	$GLOBALS['exa_config']['exa_search'] = array( 'api_key' => 'KEY' );
	$r = E::execute( array( 'query' => '  test  ' ) );
	exa_check( E::ENDPOINT === $seen[0] && 'https://api.exa.ai/search' === $seen[0], 'endpoint' );
	exa_check( 'KEY' === $seen[1]['headers']['x-api-key'] && 'application/json' === $seen[1]['headers']['Content-Type'], 'headers' );
	exa_check( array( 'query' => 'test', 'numResults' => 5, 'type' => 'auto', 'contents' => array( 'text' => array( 'maxCharacters' => 2000 ) ) ) === $seen[2], 'default JSON body' );
	exa_check( $r['success'] && 'test' === $r['query'] && 2 === count( $r['results'] ), 'summary' );
	exa_check( array( 'title' => 'Title', 'url' => 'https://example.com', 'published_date' => '2026-01-01', 'author' => 'Author', 'text' => 'Extract' ) === $r['results'][0], 'normalized results' );
	exa_check( '' === $r['results'][1]['author'] && ! isset( $r['results'][1]['text'] ), 'missing fields' );
	$r = E::execute( array(
		'query' => 'test', 'num_results' => 100, 'type' => 'neural',
		'include_domains' => array( 'example.com' ), 'exclude_domains' => array( 'spam.com' ),
		'start_published_date' => '2025-01-01', 'include_text' => false,
	) );
	exa_check( 25 === $seen[2]['numResults'] && 'neural' === $seen[2]['type'], 'upper clamp and type' );
	exa_check( array( 'example.com' ) === $seen[2]['includeDomains'] && array( 'spam.com' ) === $seen[2]['excludeDomains'] && '2025-01-01' === $seen[2]['startPublishedDate'], 'filters mapped' );
	exa_check( ! isset( $seen[2]['contents'] ) && ! isset( $r['results'][0]['text'] ), 'text disabled' );
	foreach ( array( 'auto', 'neural', 'keyword', 'fast' ) as $type ) {
		E::execute( array( 'query' => 'x', 'num_results' => -5, 'type' => $type ) );
		exa_check( 1 === $seen[2]['numResults'] && $type === $seen[2]['type'], 'lower clamp and supported types' );
	}
	exa_check( 'exa_search_query_required' === E::execute( array( 'query' => ' ' ) )->get_error_code(), 'empty query' );
	exa_check( 'exa_search_invalid_type' === E::execute( array( 'query' => 'x', 'type' => 'invalid' ) )->get_error_code(), 'invalid type' );
	foreach ( array(
		array( 'success' => false, 'error' => 'Connection timeout' ),
		array( 'success' => false, 'status_code' => 401, 'data' => '{"error":"Invalid API key"}' ),
		array( 'success' => true, 'status_code' => 429, 'data' => '{"message":"Rate limit exceeded"}' ),
	) as $failure ) {
		E::$http_post = static fn() => $failure;
		$r = E::execute( array( 'query' => 'x' ) );
		$expected = json_decode( $failure['data'] ?? '{}', true );
		$message = $expected['error'] ?? $expected['message'] ?? $failure['error'];
		exa_check( is_wp_error( $r ) && 'exa_search_failed' === $r->get_error_code() && str_contains( $r->get_error_message(), $message ), 'HTTP failure includes upstream message' );
	}
	foreach ( array( 'not json', '{}', '{"results":null}' ) as $data ) {
		E::$http_post = static fn() => array( 'success' => true, 'data' => $data );
		exa_check( is_wp_error( E::execute( array( 'query' => 'x' ) ) ), 'unreadable response rejected' );
	}
	$providers = file_get_contents( dirname( __DIR__ ) . '/inc/Bootstrap/ProviderModules.php' );
	exa_check( str_contains( $providers, 'new \\DataMachineBusiness\\Abilities\\Search\\ExaSearchAbility()' ) && str_contains( $providers, "'datamachine/exa-search', 'tool:exa_search'" ), 'provider registration' );
	echo "Exa Search ability smoke passed.\n";
}
