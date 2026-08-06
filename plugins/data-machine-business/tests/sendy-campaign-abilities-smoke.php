<?php
/**
 * Integration smoke coverage for the credential-free Sendy campaign surface.
 *
 * Run with: php tests/sendy-campaign-abilities-smoke.php
 */

namespace DataMachine\Abilities {
	class PermissionHelper {
		public static bool $allowed = false;

		public static function can_manage(): bool {
			return self::$allowed;
		}
	}

	class AbilityRegistration {
		public static function on_abilities_api_init( callable $callback ): void {
			$callback();
		}
	}
}

namespace {
	$root          = dirname( __DIR__ );
	$failures      = array();
	$passes        = 0;
	$registered    = array();
	$sendy_config  = array();
	$http_mode     = 'create';
	$http_fixtures = json_decode( file_get_contents( __DIR__ . '/fixtures/sendy-campaign-http.json' ), true );

	define( 'ABSPATH', $root . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );

	class WP_Error {
		private string $code;
		private string $message;
		private $data;

		public function __construct( string $code, string $message, $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}

	class wpdb {
		private array $campaigns;

		public function __construct() {
			$this->campaigns = array(
				101 => array(
					'id' => 101, 'title' => 'Draft campaign', 'sent' => '', 'to_send' => 0,
					'recipients' => 0, 'opens' => 0, 'clicks' => 0, 'send_date' => '',
					'lists' => '1', 'opens_tracking' => 1, 'links_tracking' => 1,
					'campaign_stopped' => 0, 'from_name' => 'Extra Chill',
					'from_email' => 'newsletter@example.com', 'reply_to' => 'reply@example.com',
					'errors' => '',
				),
				102 => array(
					'id' => 102, 'title' => 'Sent campaign', 'sent' => 1700000000, 'to_send' => 50,
					'recipients' => 50, 'opens' => 25, 'clicks' => 5, 'send_date' => '',
					'lists' => '1', 'opens_tracking' => 1, 'links_tracking' => 1,
					'campaign_stopped' => 0, 'from_name' => 'Extra Chill',
					'from_email' => 'newsletter@example.com', 'reply_to' => 'reply@example.com',
					'errors' => '',
				),
			);
		}

		public function prepare( string $query, ...$args ): string {
			return vsprintf( $query, $args );
		}

		public function get_var( string $query ) {
			return count( $this->filtered( $query ) );
		}

		public function get_results( string $query, string $output ): array {
			return array_values( $this->filtered( $query ) );
		}

		public function get_row( string $query, string $output ): ?array {
			preg_match( '/id = (\d+)/', $query, $matches );
			$id = isset( $matches[1] ) ? (int) $matches[1] : 0;
			return $this->campaigns[ $id ] ?? null;
		}

		public function delete( string $table, array $where, array $formats ) {
			$id = (int) $where['id'];
			if ( ! isset( $this->campaigns[ $id ] ) ) {
				return false;
			}
			unset( $this->campaigns[ $id ] );
			return 1;
		}

		private function filtered( string $query ): array {
			return array_filter(
				$this->campaigns,
				static function ( array $campaign ) use ( $query ): bool {
					if ( false !== strpos( $query, 'sent != ""' ) ) {
						return ! empty( $campaign['sent'] );
					}
					if ( false !== strpos( $query, 'send_date != ""' ) ) {
						return ! empty( $campaign['send_date'] );
					}
					if ( false !== strpos( $query, 'sent = ""' ) ) {
						return empty( $campaign['sent'] ) && empty( $campaign['send_date'] );
					}
					return true;
				}
			);
		}
	}

	function assert_sendy( bool $condition, string $name ): void {
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
		global $sendy_config;
		return $sendy_config ?: $default;
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

	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}

	function wp_remote_post( string $url, array $args ) {
		global $http_mode, $http_fixtures;
		if ( 'timeout' === $http_mode ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: api-secret timed out' );
		}
		if ( false !== strpos( $url, '/status.php' ) ) {
			return $http_fixtures['exists'];
		}
		if ( 'remote_error' === $http_mode ) {
			return $http_fixtures['remote_error'];
		}
		return $http_fixtures[ false !== strpos( $url, '/update.php' ) ? 'update' : 'create' ];
	}

	function wp_remote_retrieve_body( array $response ): string {
		return $response['body'];
	}

	function wp_remote_retrieve_response_code( array $response ): int {
		return $response['code'];
	}

	require_once $root . '/inc/Sendy/SendyClient.php';
	require_once $root . '/inc/Abilities/Sendy/SendyAbilities.php';

	$abilities = new \DataMachineBusiness\Abilities\Sendy\SendyAbilities();
	$campaign_abilities = array(
		'datamachine/sendy-push-campaign',
		'datamachine/sendy-list-campaigns',
		'datamachine/sendy-get-campaign',
		'datamachine/sendy-delete-campaign',
	);
	foreach ( $campaign_abilities as $ability_name ) {
		assert_sendy( isset( $registered[ $ability_name ] ), "{$ability_name} is registered" );
		assert_sendy( ! isset( $registered[ $ability_name ]['input_schema']['properties']['config'] ), "{$ability_name} does not accept credentials" );
		assert_sendy( ! empty( $registered[ $ability_name ]['output_schema']['properties'] ), "{$ability_name} has an explicit output schema" );
	}

	assert_sendy( ! $abilities->check_permission(), 'campaign management rejects unauthorized callers' );
	\DataMachine\Abilities\PermissionHelper::$allowed = true;
	assert_sendy( $abilities->check_permission(), 'campaign management permits managers' );

	$absent = $abilities->execute_list_campaigns( array() );
	assert_sendy( is_wp_error( $absent ) && 'sendy_provider_unavailable' === $absent->get_error_code(), 'absent provider is explicit' );

	$sendy_config = array( 'api_key' => 'api-secret' );
	$invalid      = $abilities->execute_push_campaign( array() );
	assert_sendy( is_wp_error( $invalid ) && 'sendy_config_incomplete' === $invalid->get_error_code(), 'incomplete API configuration is explicit' );
	$db_missing = $abilities->execute_list_campaigns( array() );
	assert_sendy( is_wp_error( $db_missing ) && 'sendy_db_not_configured' === $db_missing->get_error_code(), 'absent database configuration is explicit' );

	$sendy_config = array(
		'api_key'   => 'api-secret',
		'sendy_url' => 'https://sendy.example.test',
		'db'        => array( 'host' => 'db', 'user' => 'reader', 'pass' => 'db-secret', 'name' => 'sendy' ),
	);
	$campaign_input = array(
		'from_name'  => 'Extra Chill',
		'from_email' => 'newsletter@example.com',
		'reply_to'   => 'reply@example.com',
		'subject'    => 'Campaign subject',
		'html_text'  => '<p>Campaign</p>',
		'plain_text' => 'Campaign',
		'brand_id'   => '1',
	);

	$bad_input = $abilities->execute_push_campaign( array_merge( $campaign_input, array( 'from_email' => 'invalid' ) ) );
	assert_sendy( is_wp_error( $bad_input ) && 'sendy_validation_error' === $bad_input->get_error_code(), 'campaign input validation is explicit' );
	$bad_list = $abilities->execute_list_campaigns( array( 'status' => 'unknown' ) );
	assert_sendy( is_wp_error( $bad_list ) && 'sendy_validation_error' === $bad_list->get_error_code(), 'campaign list validation is explicit' );

	$http_mode = 'create';
	$created   = $abilities->execute_push_campaign( $campaign_input );
	assert_sendy( ! is_wp_error( $created ) && '321' === $created['campaign_id'] && true === $created['created'], 'Newsletter campaign create path succeeds' );
	assert_sendy( ! isset( $created['raw'] ), 'campaign create response omits raw provider data' );

	$updated = $abilities->execute_push_campaign( array_merge( $campaign_input, array( 'campaign_id' => '321' ) ) );
	assert_sendy( ! is_wp_error( $updated ) && false === $updated['created'], 'Newsletter campaign update path succeeds' );

	$list = $abilities->execute_list_campaigns( array( 'per_page' => 20, 'offset' => 0 ) );
	assert_sendy( ! is_wp_error( $list ) && 2 === $list['total'] && 2 === count( $list['campaigns'] ), 'Newsletter campaign list path succeeds' );
	$get = $abilities->execute_get_campaign( array( 'campaign_id' => 101 ) );
	assert_sendy( ! is_wp_error( $get ) && 'Draft campaign' === $get['title'], 'Newsletter campaign get path succeeds' );
	$sent_delete = $abilities->execute_delete_campaign( array( 'campaign_id' => 102 ) );
	assert_sendy( is_wp_error( $sent_delete ) && 'cannot_delete_sent' === $sent_delete->get_error_code(), 'sent campaigns remain protected' );
	$deleted = $abilities->execute_delete_campaign( array( 'campaign_id' => 101 ) );
	assert_sendy( ! is_wp_error( $deleted ) && true === $deleted['success'], 'Newsletter campaign delete path succeeds' );

	$http_mode   = 'remote_error';
	$remote_error = $abilities->execute_push_campaign( $campaign_input );
	assert_sendy( is_wp_error( $remote_error ) && 'sendy_remote_error' === $remote_error->get_error_code(), 'remote provider errors are explicit' );
	$http_mode = 'timeout';
	$timeout   = $abilities->execute_push_campaign( $campaign_input );
	assert_sendy( is_wp_error( $timeout ) && 'sendy_timeout' === $timeout->get_error_code(), 'provider timeouts are explicit' );

	$public_results = array( $absent, $invalid, $db_missing, $created, $updated, $list, $get, $deleted, $remote_error, $timeout );
	foreach ( $public_results as $result ) {
		if ( is_wp_error( $result ) ) {
			$result = array( $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
		}
		$serialized = serialize( $result );
		assert_sendy( false === strpos( $serialized, 'api-secret' ) && false === strpos( $serialized, 'db-secret' ), 'credentials are redacted from public results' );
	}

	if ( ! empty( $failures ) ) {
		fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
		exit( 1 );
	}

	echo "Sendy campaign ability smoke checks passed ({$passes} assertions).\n";
}
