<?php
/**
 * Smoke test: datamachine/google-search permission, not-configured error
 * and result shape with a stubbed HTTP response.
 *
 * Run with: php tests/google-search-ability-smoke.php
 *
 * @package DataMachineBusiness\Tests
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['gs_caps']   = array();
	$GLOBALS['gs_manage'] = false;
	$GLOBALS['gs_config'] = array();

	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
		public function get_error_data() {
			return $this->data;
		}
	}
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
	function current_user_can( $cap ) {
		return in_array( $cap, $GLOBALS['gs_caps'], true );
	}
	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- stub.
	}
	function add_query_arg( $args, $url ) {
		return $url . '?' . http_build_query( $args );
	}
	function __( $text ) {
		return $text;
	}
}

namespace DataMachine\Abilities {
	class PermissionHelper {
		public static function can_manage() {
			return $GLOBALS['gs_manage'];
		}
	}
}

namespace DataMachine\Core {
	class HttpClient {
		public static function get( $url, $options = array() ) {
			throw new \RuntimeException( 'real HTTP must not be used in the smoke test' );
		}
	}
}

namespace DataMachineBusiness\Tools {
	class GoogleSearch {
		public static function get_config() {
			return $GLOBALS['gs_config'];
		}
	}
}

namespace {
	require dirname( __DIR__ ) . '/inc/Abilities/Search/GoogleSearchAbility.php';

	use DataMachineBusiness\Abilities\Search\GoogleSearchAbility as G;

	$failures = 0;
	function gs_check( $cond, $msg ) {
		global $failures;
		if ( ! $cond ) {
			++$failures;
			fwrite( STDERR, "FAIL: {$msg}\n" );
		}
	}

	/* Permission: edit_posts (editor/author/team) or Data Machine managers. */
	gs_check( false === G::can_search(), 'subscriber cannot search' );
	$GLOBALS['gs_caps'] = array( 'edit_posts' );
	gs_check( true === G::can_search(), 'edit_posts can search' );
	$GLOBALS['gs_caps']   = array();
	$GLOBALS['gs_manage'] = true;
	gs_check( true === G::can_search(), 'managers can search' );
	$GLOBALS['gs_manage'] = false;

	/* Not configured: specific code, operator guidance, no HTTP. */
	$calls      = 0;
	G::$http_get = static function ( $url, $options ) use ( &$calls ) {
		++$calls;
		return array(
			'success' => true,
			'data'    => '{}',
		);
	};
	$r = G::execute( array( 'query' => 'Gavin Hamilton' ) );
	gs_check( is_wp_error( $r ) && 'google_search_not_configured' === $r->get_error_code(), 'unconfigured returns google_search_not_configured' );
	gs_check( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'API key' ) && false !== strpos( $r->get_error_message(), 'Search Engine ID' ) && false !== strpos( $r->get_error_message(), 'Data Machine > Agent > Tools' ), 'message tells an operator where to configure' );
	gs_check( is_wp_error( $r ) && 503 === ( $r->get_error_data()['status'] ?? 0 ), 'not configured is a 503' );
	$GLOBALS['gs_config'] = array( 'api_key' => 'k' );
	gs_check( 'google_search_not_configured' === G::search( 'x' )->get_error_code(), 'missing engine id is also not configured' );
	gs_check( 0 === $calls, 'no request without configuration' );

	/* Configured: request parameters and result shape. */
	$GLOBALS['gs_config'] = array(
		'api_key'          => 'KEY',
		'search_engine_id' => 'CX',
	);
	$seen        = '';
	G::$http_get = static function ( $url, $options ) use ( &$seen ) {
		$seen = $url;
		return array(
			'success' => true,
			'data'    => json_encode(
				array(
					'searchInformation' => array(
						'totalResults' => '1230',
						'searchTime'   => 0.21,
					),
					'items'             => array(
						array(
							'title'       => 'Gavin Hamilton',
							'link'        => 'https://gavinhamilton.example/',
							'snippet'     => 'Charleston songwriter',
							'displayLink' => 'gavinhamilton.example',
							'pagemap'     => array( 'ignored' => true ),
						),
						array( 'title' => 'Partial' ),
					),
				)
			),
		);
	};
	$r = G::execute(
		array(
			'query'         => '  Gavin Hamilton ',
			'site_restrict' => 'bandcamp.com',
		)
	);
	gs_check( ! is_wp_error( $r ), 'configured search succeeds' );
	parse_str( (string) parse_url( $seen, PHP_URL_QUERY ), $q ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- test.
	gs_check( 0 === strpos( $seen, G::ENDPOINT . '?' ), 'calls the Custom Search endpoint' );
	gs_check( 'KEY' === $q['key'] && 'CX' === $q['cx'] && 'Gavin Hamilton' === $q['q'] && '10' === $q['num'] && 'active' === $q['safe'] && 'bandcamp.com' === $q['siteSearch'], 'request parameters' );
	gs_check( 'Gavin Hamilton' === $r['query'] && 2 === $r['results_count'] && '1230' === $r['total_available'] && 0.21 === $r['search_time'], 'summary fields' );
	gs_check(
		array(
			'title'       => 'Gavin Hamilton',
			'link'        => 'https://gavinhamilton.example/',
			'snippet'     => 'Charleston songwriter',
			'displayLink' => 'gavinhamilton.example',
		) === $r['results'][0],
		'result items are normalized to title/link/snippet/displayLink'
	);
	gs_check( '' === $r['results'][1]['link'] && '' === $r['results'][1]['snippet'], 'missing item fields default to empty strings' );

	/* Errors. */
	gs_check( 'google_search_query_required' === G::search( '   ' )->get_error_code(), 'empty query rejected' );
	G::$http_get = static function () {
		return array(
			'success' => false,
			'error'   => 'HTTP 403',
		);
	};
	gs_check( 'google_search_failed' === G::search( 'x' )->get_error_code(), 'upstream failure is google_search_failed' );
	G::$http_get = static function () {
		return array(
			'success' => true,
			'data'    => 'not json',
		);
	};
	gs_check( 'google_search_failed' === G::search( 'x' )->get_error_code(), 'bad JSON is google_search_failed' );

	/* Tool delegates to the ability; provider registers the ability. */
	$root = dirname( __DIR__ );
	gs_check( false !== strpos( (string) file_get_contents( $root . '/inc/Tools/GoogleSearch.php' ), 'GoogleSearchAbility::search(' ), 'google_search tool delegates to the ability' );
	$providers = (string) file_get_contents( $root . '/inc/Bootstrap/ProviderModules.php' );
	gs_check( false !== strpos( $providers, 'new \\DataMachineBusiness\\Abilities\\Search\\GoogleSearchAbility()' ) && false !== strpos( $providers, "'datamachine/google-search'" ), 'provider module registers the ability' );
	$ability = (string) file_get_contents( $root . '/inc/Abilities/Search/GoogleSearchAbility.php' );
	gs_check( false !== strpos( $ability, "'show_in_rest' => true" ), 'ability is REST visible' );

	if ( $failures ) {
		exit( 1 );
	}
	echo "Google Search ability smoke passed.\n";
}
