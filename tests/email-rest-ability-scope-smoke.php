<?php
/**
 * Pure-PHP coverage for exact email REST route ability scopes.
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Abilities {
	class PermissionHelper {
		public static bool $broad = true;
		public static bool $agent = false;
		public static array $allowed = array();
		public static array $allowed_categories = array();
		public static array $checks = array();
		public static function can( string $action ): bool { return self::$broad; }
		public static function can_manage(): bool { return self::$broad; }
		public static function can_use_ability( string $ability, string $category = '' ): bool {
			self::$checks[] = array( $ability, $category );
			return ! self::$agent || in_array( $ability, self::$allowed, true ) || in_array( $category, self::$allowed_categories, true );
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['email_rest_routes'] = array();

	class WP_REST_Request {}
	class WP_REST_Response {}
	class WP_Error {}

	function add_action( string $hook, callable $callback ): bool { return true; }
	function register_rest_route( string $namespace, string $route, array $config ): bool {
		$GLOBALS['email_rest_routes'][ $route ] = $config;
		return true;
	}
	function wp_get_ability( string $ability ) {
		$categories = array(
			'datamachine/send-email' => 'datamachine-publishing',
			'datamachine/fetch-email' => 'datamachine-fetch',
		);
		$category = $categories[ $ability ] ?? 'datamachine-email';
		return new class( $category ) {
			public function __construct( private string $category ) {}
			public function get_category(): string { return $this->category; }
		};
	}

	require_once __DIR__ . '/../inc/Api/Email.php';

	use DataMachine\Abilities\PermissionHelper;
	use DataMachine\Api\Email;

	Email::register_routes();
	$expected = array(
		'/email/send' => array( 'datamachine/send-email', 'datamachine-publishing' ),
		'/email/fetch' => array( 'datamachine/fetch-email', 'datamachine-fetch' ),
		'/email/(?P<uid>\d+)/read' => array( 'datamachine/fetch-email', 'datamachine-fetch' ),
		'/email/reply' => array( 'datamachine/email-reply', 'datamachine-email' ),
		'/email/(?P<uid>\d+)' => array( 'datamachine/email-delete', 'datamachine-email' ),
		'/email/(?P<uid>\d+)/move' => array( 'datamachine/email-move', 'datamachine-email' ),
		'/email/(?P<uid>\d+)/flag' => array( 'datamachine/email-flag', 'datamachine-email' ),
		'/email/batch/move' => array( 'datamachine/email-batch-move', 'datamachine-email' ),
		'/email/batch/flag' => array( 'datamachine/email-batch-flag', 'datamachine-email' ),
		'/email/batch/delete' => array( 'datamachine/email-batch-delete', 'datamachine-email' ),
		'/email/(?P<uid>\d+)/unsubscribe' => array( 'datamachine/email-unsubscribe', 'datamachine-email' ),
		'/email/batch/unsubscribe' => array( 'datamachine/email-batch-unsubscribe', 'datamachine-email' ),
		'/email/test-connection' => array( 'datamachine/email-test-connection', 'datamachine-email' ),
	);

	$failed = 0;
	$assert = static function ( bool $condition, string $message ) use ( &$failed ): void {
		echo ( $condition ? 'PASS' : 'FAIL' ) . ": {$message}\n";
		$failed += $condition ? 0 : 1;
	};
	$request = new WP_REST_Request();
	$assert( count( $expected ) === count( $GLOBALS['email_rest_routes'] ), 'every email REST route is covered by the scope matrix' );

	PermissionHelper::$agent = false;
	foreach ( $expected as $route => $scope ) {
		$callback = $GLOBALS['email_rest_routes'][ $route ]['permission_callback'] ?? null;
		$assert( is_callable( $callback ) && true === $callback( $request ), "normal user/admin remains allowed for {$route}" );
	}

	PermissionHelper::$agent = true;
	PermissionHelper::$allowed = array();
	PermissionHelper::$allowed_categories = array();
	foreach ( $expected as $route => $scope ) {
		$callback = $GLOBALS['email_rest_routes'][ $route ]['permission_callback'];
		$assert( false === $callback( $request ), "revoked agent token is denied for {$route}" );
	}

	foreach ( $expected as $route => $scope ) {
		PermissionHelper::$allowed = array( $scope[0] );
		PermissionHelper::$allowed_categories = array();
		PermissionHelper::$checks  = array();
		$callback = $GLOBALS['email_rest_routes'][ $route ]['permission_callback'];
		$allowed  = $callback( $request );
		$assert( true === $allowed, "explicit exact ability scope allows {$route}" );
		$assert( array( $scope ) === PermissionHelper::$checks, "{$route} checks exact ability and registered category" );
	}

	$category_cases = array(
		'datamachine-publishing' => array( '/email/send' ),
		'datamachine-fetch'      => array( '/email/fetch', '/email/(?P<uid>\d+)/read' ),
		'datamachine-email'      => array_values( array_diff( array_keys( $expected ), array( '/email/send', '/email/fetch', '/email/(?P<uid>\d+)/read' ) ) ),
	);
	PermissionHelper::$allowed = array();
	foreach ( $category_cases as $category => $allowed_routes ) {
		PermissionHelper::$allowed_categories = array( $category );
		foreach ( $expected as $route => $scope ) {
			$callback = $GLOBALS['email_rest_routes'][ $route ]['permission_callback'];
			$assert( in_array( $route, $allowed_routes, true ) === $callback( $request ), "{$category} scope is isolated for {$route}" );
		}
	}

	PermissionHelper::$broad = false;
	PermissionHelper::$allowed = array_column( $expected, 0 );
	PermissionHelper::$allowed_categories = array();
	$send_callback = $GLOBALS['email_rest_routes']['/email/send']['permission_callback'];
	$assert( false === $send_callback( $request ), 'exact scope never bypasses broad REST permission' );

	if ( $failed ) {
		exit( 1 );
	}
	echo "email-rest-ability-scope-smoke: ok\n";
}
