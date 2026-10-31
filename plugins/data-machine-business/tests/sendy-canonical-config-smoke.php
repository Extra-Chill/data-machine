<?php
/**
 * Smoke coverage for the single canonical Sendy configuration.
 *
 * Every Sendy ability, including subscribe and metrics, resolves
 * datamachine_sendy_config. Callers cannot supply or override credentials.
 *
 * Run with: php tests/sendy-canonical-config-smoke.php
 */

namespace DataMachine\Abilities {
	class PermissionHelper {
		public static function can_manage(): bool {
			return true;
		}
	}

	class AbilityRegistration {
		public static function on_abilities_api_init( callable $callback ): void {
			$callback();
		}
	}
}

namespace {
	$root         = dirname( __DIR__ );
	$failures     = array();
	$passes       = 0;
	$registered   = array();
	$site_options = array();
	$http_calls   = array();

	define( 'ABSPATH', $root . '/' );

	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code, string $message, $data = null ) {
			unset( $data );
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

	function assert_config( bool $condition, string $name ): void {
		global $failures, $passes;
		if ( $condition ) {
			++$passes;
			return;
		}
		$failures[] = $name;
	}

	function __( string $text, string $domain = '' ): string {
		return $text;
	}

	function wp_register_ability( string $name, array $definition ): void {
		global $registered;
		$registered[ $name ] = $definition;
	}

	function get_site_option( string $option, $default = array() ) {
		global $site_options;
		return array_key_exists( $option, $site_options ) ? $site_options[ $option ] : $default;
	}

	function update_site_option( string $option, $value ): bool {
		global $site_options;
		$site_options[ $option ] = $value;
		return true;
	}

	function apply_filters( string $hook, $value ) {
		return $value;
	}

	function wp_parse_args( array $args, array $defaults ): array {
		return array_merge( $defaults, $args );
	}

	function untrailingslashit( string $value ): string {
		return rtrim( $value, '/' );
	}

	function wp_http_validate_url( string $url ) {
		return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : false;
	}

	function is_email( string $email ) {
		return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
	}

	function absint( $value ): int {
		return abs( (int) $value );
	}

	function sanitize_text_field( $value ): string {
		return trim( (string) $value );
	}

	function sanitize_email( $value ): string {
		return trim( (string) $value );
	}

	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}

	function wp_remote_post( string $url, array $args ) {
		global $http_calls;
		$http_calls[] = array( 'url' => $url, 'body' => $args['body'] ?? array() );
		if ( false !== strpos( $url, 'get-brands' ) ) {
			return array( 'code' => 200, 'body' => '{"brand1":{"id":"1","name":"Brand"}}' );
		}
		if ( false !== strpos( $url, 'get-lists' ) ) {
			return array( 'code' => 200, 'body' => '{"list1":{"id":"ListHash","name":"Main"}}' );
		}
		if ( false !== strpos( $url, 'active-subscriber-count' ) ) {
			return array( 'code' => 200, 'body' => '42' );
		}
		return array( 'code' => 200, 'body' => '1' );
	}

	function wp_remote_retrieve_body( array $response ): string {
		return $response['body'];
	}

	function wp_remote_retrieve_response_code( array $response ): int {
		return $response['code'];
	}

	require_once $root . '/inc/Sendy/SendyClient.php';
	require_once $root . '/inc/Abilities/Sendy/SendyAbilities.php';

	use DataMachineBusiness\Abilities\Sendy\SendyAbilities;

	$abilities = new SendyAbilities();

	// ── No caller credentials anywhere ──
	foreach ( $registered as $name => $definition ) {
		assert_config( ! isset( $definition['input_schema']['properties']['config'] ), "{$name} accepts no credentials" );
		assert_config( ! in_array( 'config', $definition['input_schema']['required'] ?? array(), true ), "{$name} requires no credentials" );
	}
	assert_config( ! method_exists( SendyAbilities::class, 'update_api_config' ), 'no consumer-facing credential writer exists' );

	// ── No canonical config: explicit errors ──
	$absent = $abilities->execute_subscribe( array( 'list_id' => 'L1', 'email' => 'fan@example.com' ) );
	assert_config( is_wp_error( $absent ) && 'sendy_provider_unavailable' === $absent->get_error_code(), 'subscribe without config is explicit' );
	assert_config( false !== strpos( $absent->get_error_message(), 'datamachine_sendy_config' ), 'error names the option to set' );

	// ── DB-only canonical config (the production state that broke push) ──
	$site_options[ SendyAbilities::CONFIG_OPTION ] = array(
		'db' => array( 'host' => 'localhost', 'user' => 'sendy', 'pass' => 'db-secret', 'name' => 'sendy' ),
	);
	$db_only = $abilities->execute_subscribe( array( 'list_id' => 'L1', 'email' => 'fan@example.com' ) );
	assert_config( is_wp_error( $db_only ) && 'sendy_config_incomplete' === $db_only->get_error_code(), 'subscribe with db-only config reports missing API fields' );
	assert_config( false !== strpos( $db_only->get_error_message(), 'datamachine_sendy_config' ), 'incomplete error names the option' );
	$api_metrics = $abilities->execute_metrics( array( 'metric' => 'subscribers', 'source' => 'api' ) );
	assert_config( is_wp_error( $api_metrics ) && 'sendy_config_incomplete' === $api_metrics->get_error_code(), 'api-sourced metrics need API fields' );
	assert_config( array() === $http_calls, 'incomplete config makes no HTTP calls' );

	// ── A caller-supplied config is ignored, never used ──
	$smuggled = $abilities->execute_subscribe(
		array(
			'config'  => array( 'api_key' => 'caller-secret', 'sendy_url' => 'https://attacker.example.test' ),
			'list_id' => 'L1',
			'email'   => 'fan@example.com',
		)
	);
	assert_config( is_wp_error( $smuggled ) && array() === $http_calls, 'a caller config cannot supply missing credentials or redirect requests' );

	// ── Canonical config drives subscribe and metrics ──
	$site_options[ SendyAbilities::CONFIG_OPTION ]['api_key']   = 'canonical-secret';
	$site_options[ SendyAbilities::CONFIG_OPTION ]['sendy_url'] = 'https://mail.example.test';

	$subscribed = $abilities->execute_subscribe(
		array(
			'config'  => array( 'api_key' => 'caller-secret', 'sendy_url' => 'https://attacker.example.test' ),
			'list_id' => 'L1',
			'email'   => 'fan@example.com',
		)
	);
	$call       = end( $http_calls );
	assert_config( ! is_wp_error( $subscribed ), 'subscribe succeeds with canonical config' );
	assert_config( 'https://mail.example.test/subscribe' === $call['url'] && 'canonical-secret' === $call['body']['api_key'], 'subscribe always uses the canonical URL and key' );

	$http_calls = array();
	$stats      = $abilities->execute_metrics( array( 'metric' => 'subscribers', 'source' => 'api' ) );
	assert_config( ! is_wp_error( $stats ) && 42 === ( $stats['total_active'] ?? null ), 'metrics resolve canonical config' );
	assert_config( 0 === strpos( $http_calls[0]['url'], 'https://mail.example.test/' ), 'metrics hit the canonical URL' );

	foreach ( array( $absent, $db_only, $api_metrics, $smuggled, $stats ) as $result ) {
		if ( is_wp_error( $result ) ) {
			$result = array( $result->get_error_code(), $result->get_error_message() );
		}
		$serialized = serialize( $result );
		assert_config( false === strpos( $serialized, 'secret' ), 'credentials never appear in results' );
	}

	if ( ! empty( $failures ) ) {
		fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
		exit( 1 );
	}

	echo "Sendy canonical config smoke checks passed ({$passes} assertions).\n";
}
