<?php
/**
 * Pure-PHP smoke test for GA/GSC config resolution in fetchStats() (#127).
 *
 * Regression guard for 1a6734d, which removed `$config = self::get_config();`
 * from both fetch paths while leaving later `$config[...]` reads in place:
 *  - GA: input without property_id must resolve the configured network
 *    property (and aggregate_report must pass config to the resolver)
 *    instead of failing with "No GA4 property ID configured or provided.",
 *  - GSC: input without site_url must resolve the configured property
 *    instead of throwing a TypeError (null passed to resolve_site_url).
 *
 * Auth is stubbed at the class level (a fake GoogleServiceAccountAuth hands
 * back a token) so no credential parsing or network round-trip is involved;
 * the non-credential config read under test is get_site_option(CONFIG_OPTION).
 *
 * Run with: php tests/analytics-config-resolution-smoke.php
 *
 * @package DataMachineBusiness\Tests
 */

namespace {
	$__smoke_failures = array();
	$__smoke_passes   = 0;
	$__smoke_warnings = array();

	// Fail the run on ANY notice/warning/deprecation — the GSC half of this
	// regression shipped as a PHP warning before the TypeError.
	set_error_handler( static function ( int $errno, string $errstr ) {
		$GLOBALS['__smoke_warnings'][] = $errstr;
		return true;
	}, E_ALL );

	// Minimal WP shims required by the abilities files at load + call time.
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			private string $code;
			private string $message;
			public function __construct( string $code = '', string $message = '' ) {
				$this->code    = $code;
				$this->message = $message;
			}
			public function get_error_message(): string {
				return $this->message;
			}
			public function get_error_code(): string {
				return $this->code;
			}
		}
	}
	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool {
			return $thing instanceof \WP_Error;
		}
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $str ) {
			return trim( (string) $str );
		}
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data ) {
			return json_encode( $data );
		}
	}
	if ( ! function_exists( 'wp_list_pluck' ) ) {
		function wp_list_pluck( $list, $field ) {
			$out = array();
			foreach ( $list as $item ) {
				$out[] = is_object( $item ) ? $item->$field : ( $item[ $field ] ?? null );
			}
			return $out;
		}
	}
	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( $url, $component = -1 ) {
			return parse_url( $url, $component );
		}
	}
	if ( ! function_exists( 'trailingslashit' ) ) {
		function trailingslashit( $url ) {
			return rtrim( (string) $url, '/\\' ) . '/';
		}
	}
	if ( ! function_exists( 'home_url' ) ) {
		function home_url( $path = '' ) {
			return $GLOBALS['__smoke_home_url'] . $path;
		}
	}
	if ( ! function_exists( 'get_site_option' ) ) {
		function get_site_option( $option, $default = false ) {
			return $GLOBALS['__smoke_site_options'][ $option ] ?? $default;
		}
	}
	if ( ! function_exists( 'add_action' ) ) {
		function add_action( ...$args ) {
			return true;
		}
	}
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}
	if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
		define( 'MINUTE_IN_SECONDS', 60 );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}
	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}
}

namespace DataMachine\Abilities {
	class PermissionHelper {
		public static function can_manage(): bool {
			return true;
		}
	}
}

namespace DataMachine\Core {
	class HttpClient {
		public static function post( $url, $args = array() ) {
			$GLOBALS['__smoke_http_urls'][] = $url;
			return array( 'success' => true, 'data' => '{}', 'status_code' => 200 );
		}
		public static function get( $url, $args = array() ) {
			$GLOBALS['__smoke_http_urls'][] = $url;
			return array( 'success' => true, 'data' => '{}', 'status_code' => 200 );
		}
	}
}

namespace DataMachineBusiness\OAuth\Providers {
	/**
	 * Test double for the shared service-account provider. The production class
	 * extends data-machine's BaseServiceAccountProvider (not loadable here) and
	 * would attempt a real token exchange; fetchStats() only needs a token.
	 */
	class GoogleServiceAccountAuth {
		public function __construct( string $legacy_option = '' ) {}
		public function get_access_token( string $scope ) {
			return 'stub-access-token';
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Abilities/Analytics/GoogleAnalyticsAbilities.php';
	require_once dirname( __DIR__ ) . '/inc/Abilities/Analytics/GoogleSearchConsoleAbilities.php';

	use DataMachineBusiness\Abilities\Analytics\GoogleAnalyticsAbilities;
	use DataMachineBusiness\Abilities\Analytics\GoogleSearchConsoleAbilities;

	function smoke_assert( bool $condition, string $name ): void {
		global $__smoke_failures, $__smoke_passes;
		if ( $condition ) {
			$__smoke_passes++;
			echo "  \u{2713} {$name}\n";
			return;
		}
		$__smoke_failures[] = $name;
		echo "  \u{2717} {$name}\n";
	}

	function smoke_reset(): void {
		$GLOBALS['__smoke_site_options'] = array();
		$GLOBALS['__smoke_http_urls']    = array();
		$GLOBALS['__smoke_home_url']     = 'https://extrachill.com';
	}

	echo "Analytics config resolution smoke (#127)\n";
	echo "----------------------------------------\n";

	// --- GA: configured property resolved when input omits property_id ---
	smoke_reset();
	$GLOBALS['__smoke_site_options']['datamachine_ga_config'] = array(
		'property_id' => '123456789',
	);

	$result = GoogleAnalyticsAbilities::fetchStats( array(
		'action'     => 'date_stats',
		'start_date' => '2026-01-01',
		'end_date'   => '2026-01-31',
	) );
	smoke_assert(
		! empty( $result['success'] ),
		'GA date_stats without property_id succeeds using the configured property'
	);
	smoke_assert(
		'No GA4 property ID configured or provided.' !== ( $result['error'] ?? '' ),
		'GA does not return the "No GA4 property ID" error when a property is configured'
	);
	smoke_assert(
		in_array( 'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport', $GLOBALS['__smoke_http_urls'], true ),
		'GA queries the configured property 123456789'
	);

	// Explicit input still wins over the configured property.
	$result = GoogleAnalyticsAbilities::fetchStats( array(
		'action'      => 'date_stats',
		'property_id' => '987654321',
		'start_date'  => '2026-01-01',
		'end_date'    => '2026-01-31',
	) );
	smoke_assert(
		! empty( $result['success'] ) && in_array( 'https://analyticsdata.googleapis.com/v1beta/properties/987654321:runReport', $GLOBALS['__smoke_http_urls'], true ),
		'GA explicit input property_id overrides the configured property'
	);

	// Empty config keeps the guard error (no property from anywhere).
	smoke_reset();
	$result = GoogleAnalyticsAbilities::fetchStats( array(
		'action'     => 'date_stats',
		'start_date' => '2026-01-01',
		'end_date'   => '2026-01-31',
	) );
	smoke_assert(
		empty( $result['success'] ) && 'No GA4 property ID configured or provided.' === ( $result['error'] ?? '' ),
		'GA still errors with the canonical message when no property is configured or provided'
	);

	// GA aggregate_report branch passes config to resolveAggregatePropertyId.
	smoke_reset();
	$GLOBALS['__smoke_site_options']['datamachine_ga_config'] = array(
		'property_id' => '123456789',
	);
	$result = GoogleAnalyticsAbilities::fetchStats( array(
		'action'     => 'aggregate_report',
		'date_range' => array( 'start_date' => '2026-01-01', 'end_date' => '2026-01-31' ),
		'metrics'    => array( 'sessions' ),
	) );
	smoke_assert(
		in_array( 'https://analyticsdata.googleapis.com/v1beta/properties/123456789:batchRunReports', $GLOBALS['__smoke_http_urls'], true ),
		'GA aggregate_report without property_id resolves the configured property'
	);

	// --- GSC: configured site_url resolved when input omits site_url ---
	smoke_reset();
	$GLOBALS['__smoke_site_options']['datamachine_gsc_config'] = array(
		'site_url' => 'sc-domain:extrachill.com',
	);

	$result = GoogleSearchConsoleAbilities::fetchStats( array(
		'action' => 'list_sitemaps',
	) );
	smoke_assert(
		! empty( $result['success'] ),
		'GSC list_sitemaps without site_url succeeds using the configured property'
	);
	smoke_assert(
		isset( $GLOBALS['__smoke_http_urls'][0] ) && false !== strpos( $GLOBALS['__smoke_http_urls'][0], rawurlencode( 'sc-domain:extrachill.com' ) ),
		'GSC queries the configured sc-domain:extrachill.com property'
	);

	// A model-supplied mismatched property is still rejected via config.
	smoke_reset();
	$GLOBALS['__smoke_site_options']['datamachine_gsc_config'] = array(
		'site_url' => 'sc-domain:extrachill.com',
	);
	$result = GoogleSearchConsoleAbilities::fetchStats( array(
		'action'   => 'list_sitemaps',
		'site_url' => 'https://studio.extrachill.com/',
	) );
	smoke_assert(
		empty( $result['success'] ) && false !== strpos( (string) ( $result['error'] ?? '' ), 'studio.extrachill.com' ),
		'GSC configured site_url still guards against mismatched input properties'
	);

	if ( ! empty( $__smoke_warnings ) ) {
		foreach ( array_unique( $__smoke_warnings ) as $warning ) {
			$__smoke_failures[] = "PHP issue raised during run: {$warning}";
			echo "  \u{2717} PHP issue raised during run: {$warning}\n";
		}
	}

	restore_error_handler();

	if ( ! empty( $__smoke_failures ) ) {
		echo "\nFAILURES:\n";
		foreach ( $__smoke_failures as $failure ) {
			echo " - {$failure}\n";
		}
		exit( 1 );
	}

	echo "\n{$__smoke_passes} assertions passed.\n";
}
