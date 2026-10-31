<?php
/**
 * Isolated unit tests for invoice composition, delivery and numbering.
 *
 * Run: php tests/invoice-ability-smoke.php
 *
 * @package DataMachineBusiness\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Isolated WordPress test doubles.
// phpcs:disable WordPress.Files.FileName -- Standalone test entry point.
// phpcs:disable WordPress.WP.I18n -- Translation test double.
// phpcs:disable WordPress.PHP.DevelopmentFunctions -- Unit test assertions.
// phpcs:disable Squiz.Commenting -- Minimal isolated test doubles.
// phpcs:disable Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO -- Isolated doubles share one executable test.
// phpcs:disable PSR1.Classes.ClassDeclaration, PSR1.Files.SideEffects, Squiz.Classes.ClassFileName -- Isolated doubles share one executable test.
// phpcs:disable Universal.Namespaces.DisallowCurlyBraceSyntax, Universal.Namespaces.OneDeclarationPerFile, Universal.Namespaces.DisallowDeclarationWithoutName -- Namespaced dependency doubles and global WordPress functions.
// phpcs:disable Generic.NamingConventions.CamelCapsFunctionName, Generic.CodeAnalysis.UnusedFunctionParameter, Universal.NamingConventions.NoReservedKeywordParameterNames -- WordPress signatures.
// phpcs:disable WordPress.WP.GlobalVariablesOverride -- Isolated fixture variables.

namespace DataMachine\Abilities {
	class AbilityRegistration {
		public static function on_abilities_api_init( $callback ) {
			$callback();
		}
	}
	class PermissionHelper {
		public static function can_manage() {
			return true;
		}
	}
}

namespace DataMachine\Abilities\Media {
	class BrandTokens {
		public static function get() {
			return array( 'logo_path' => $GLOBALS['logo_path'] ?? null );
		}
	}
}

namespace {
	define( 'ABSPATH', '/wordpress/' );
	define( 'WP_CONTENT_DIR', '/wordpress/wp-content' );
	$options    = array();
	$filters    = array();
	$registered = array();
	$passes     = 0;
	$mailer     = new class() {
		public array $calls = array();
		public $result      = array( 'success' => true );
		public function execute( $input ) {
			$this->calls[] = $input;
			if ( isset( $GLOBALS['filters']['datamachine_email_templates'] ) ) {
				$templates = $GLOBALS['filters']['datamachine_email_templates']( array() );
				$templates['invoice']( $input['context'] );
			}
			return $this->result;
		}
	};

	class WP_Error {
		public function __construct( $code, $message ) {}
	}
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
	function __( $text, $domain ) {
		return $text;
	}
	function wp_register_ability( $name, $args ) {
		$GLOBALS['registered'][ $name ] = $args;
	}
	function wp_get_ability( $name ) {
		if ( 'datamachine/create-invoice' === $name ) {
			return $GLOBALS['ability'];
		}
		invoice_assert( 'datamachine/send-email' === $name, 'Delegates to send-email ability' );
		return $GLOBALS['mailer'];
	}
	function add_filter( $name, $callback, $priority = 10 ) {
		if ( PHP_INT_MAX === $priority ) {
			$previous                    = $GLOBALS['filters'][ $name ];
			$GLOBALS['filters'][ $name ] = static fn( $value ) => $callback( $previous( $value ) );
		} else {
			$GLOBALS['filters'][ $name ] = $callback;
		}
	}
	function remove_filter( $name, $callback, $priority = 10 ) {
		$GLOBALS['filters'][ $name ] = array( \DataMachineBusiness\Abilities\Invoice\InvoiceTemplate::class, 'register' );
	}
	function wp_json_encode( $value, $flags = 0 ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress test double.
		return json_encode( $value, $flags );
	}
	class WP_CLI {
		public static array $lines = array();
		public static function line( $text ) {
			self::$lines[] = $text;
		}
		public static function error( $text ) {
			throw new \RuntimeException( esc_html( $text ) );
		}
	}
	function get_option( $key, $default = false ) {
		return $GLOBALS['options'][ $key ] ?? $default;
	}
	function add_option( $key, $value, $deprecated = '', $autoload = false ) {
		if ( isset( $GLOBALS['options'][ $key ] ) ) {
			return false;
		}
		$GLOBALS['options'][ $key ] = $value;
		return true;
	}
	function update_option( $key, $value, $autoload = false ) {
		$GLOBALS['options'][ $key ] = $value;
		return true;
	}
	function delete_option( $key ) {
		unset( $GLOBALS['options'][ $key ] );
	}
	function wp_date( $format ) {
		return 'Y' === $format ? '2026' : '2026-10-29';
	}
	function get_bloginfo( $key ) {
		return 'Extra Chill';
	}
	function home_url( $path ) {
		return 'https://example.org' . $path;
	}
	function site_url() {
		return 'https://example.org';
	}
	function content_url() {
		return 'https://example.org/wp-content';
	}
	function wp_get_upload_dir() {
		return array(
			'basedir' => '/uploads',
			'baseurl' => 'https://example.org/uploads',
		);
	}
	function wp_normalize_path( $path ) {
		return str_replace( '\\', '/', $path );
	}
	function trailingslashit( $path ) {
		return rtrim( $path, '/' ) . '/';
	}
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
	function esc_attr( $text ) {
		return esc_html( $text );
	}
	function esc_url( $text ) {
		return esc_html( $text );
	}
	function invoice_assert( $condition, $message ) {
		if ( ! $condition ) {
			throw new \RuntimeException( esc_html( $message ) );
		}
		++$GLOBALS['passes'];
	}

	require_once dirname( __DIR__ ) . '/inc/Abilities/Invoice/InvoiceTemplate.php';
	require_once dirname( __DIR__ ) . '/inc/Abilities/Invoice/CreateInvoiceAbility.php';
	$ability = new \DataMachineBusiness\Abilities\Invoice\CreateInvoiceAbility();
	$counter = $ability::COUNTER_OPTION;
	invoice_assert( isset( $registered['datamachine/create-invoice'] ), 'Ability registered' );
	$templates = $filters['datamachine_email_templates']( array( 'existing' => '__return_true' ) );
	invoice_assert( is_callable( $templates['invoice'] ) && isset( $templates['existing'] ), 'Template registered alongside existing templates' );
	$input   = array(
		'bill_to'              => array(
			'name'    => 'Building 64',
			'email'   => 'billing@example.org',
			'attn'    => 'Accounts',
			'address' => "123 Main St\nCharleston",
		),
		'pay_to'               => array(
			'name' => 'Contest Winner',
			'note' => 'Pay directly via Zelle',
		),
		'line_items'           => array(
			array(
				'description' => 'Costume contest prize',
				'unit_amount' => 1000,
			),
			array(
				'description' => 'Service',
				'quantity'    => 2.5,
				'unit_amount' => 10.25,
			),
			array(
				'description' => 'Rounding',
				'quantity'    => 3,
				'unit_amount' => 0.1,
			),
		),
		'payment_instructions' => 'Zelle the winner',
		'due'                  => 'Upon receipt',
		'notes'                => '<script>alert(1)</script>',
		'contact'              => 'accounts@example.org',
		'total'                => '99999999.99', // Direct execution must never trust a total.
	);
	$preview = $ability->execute( $input );
	invoice_assert( '1,025.93' === $preview['total'], 'Server computes and rounds line totals, ignoring supplied total' );
	invoice_assert( 'INV-2026-0001' === $preview['invoice_number'], 'First number includes site year' );
	invoice_assert( $preview['invoice_number'] === $ability->execute( $input )['invoice_number'] && ! isset( $options[ $counter ] ), 'Repeated previews do not consume numbers' );
	foreach ( array( 'Building 64', 'Contest Winner', '1,025.93', 'USD', 'Extra Chill', '2026-10-29', 'Upon receipt', 'Zelle the winner', 'accounts@example.org', '&lt;script&gt;' ) as $text ) {
		invoice_assert( str_contains( $preview['html'], $text ), 'HTML contains ' . $text );
	}
	invoice_assert( ! str_contains( $preview['html'], '<script>' ), 'HTML escapes untrusted text' );
	invoice_assert( is_wp_error( $ability->execute( array( 'bill_to' => $input['bill_to'] ) ) ), 'Missing items rejected' );
	invoice_assert( is_wp_error( $ability->execute( array( 'line_items' => array() ) ) ), 'Empty items rejected' );
	foreach ( array( -1, INF, NAN, 'invalid' ) as $amount ) {
		$invalid               = $input;
		$invalid['line_items'] = array(
			array(
				'description' => 'Invalid',
				'unit_amount' => $amount,
			),
		);
		invoice_assert( is_wp_error( $ability->execute( $invalid ) ), 'Invalid amount rejected' );
	}
	$input['send'] = true;
	$result        = $ability->execute( $input );
	$sent          = end( $mailer->calls );
	invoice_assert( $result === $mailer->result && 1 === $options[ $counter ], 'Successful send returns mailer result and consumes number' );
	invoice_assert( 'invoice' === $sent['template'] && 'text/html' === $sent['content_type'], 'Send uses invoice HTML template' );
	invoice_assert( 'billing@example.org' === $sent['to'], 'To defaults to bill_to email' );
	invoice_assert( '1,025.93' === $sent['context']['total'] && 1 === $sent['context']['line_items'][0]['quantity'], 'Send context contains computed totals and default quantity' );
	invoice_assert( $templates['invoice']( $sent['context'] ) === $preview['html'], 'Send template and preview render identical context' );
	$input['send'] = false;
	invoice_assert( 'INV-2026-0002' === $ability->execute( $input )['invoice_number'], 'Next preview reflects successful send' );
	$input['invoice_number'] = 'CUSTOM-42';
	invoice_assert( 'CUSTOM-42' === $ability->execute( $input )['invoice_number'], 'Explicit preview number passes through' );
	$input['send'] = true;
	$input['to']   = 'override@example.org';
	$input['cc']   = 'cc@example.org';
	$ability->execute( $input );
	$sent = end( $mailer->calls );
	invoice_assert( 1 === $options[ $counter ] && 'CUSTOM-42' === $sent['context']['invoice_number'], 'Explicit send number leaves sequence untouched' );
	invoice_assert( $input['to'] === $sent['to'] && $input['cc'] === $sent['cc'], 'Recipients passed through' );
	unset( $input['invoice_number'] );
	$mailer->result = new WP_Error( 'failed', 'Mail failed' );
	invoice_assert( is_wp_error( $ability->execute( $input ) ) && 1 === $options[ $counter ], 'Failed send does not consume number' );
	$mailer->result = array( 'success' => false );
	$ability->execute( $input );
	invoice_assert( 1 === $options[ $counter ] && ! isset( $options[ $counter . '_lock' ] ), 'Unsuccessful send releases lock without consuming number' );
	$options[ $counter . '_lock' ] = time();
	invoice_assert( is_wp_error( $ability->execute( $input ) ), 'Concurrent send is blocked' );
	$mailer->result                = array( 'success' => true );
	$options[ $counter . '_lock' ] = time() - DataMachineBusiness\Abilities\Invoice\CreateInvoiceAbility::LOCK_TTL - 1;
	$ability->execute( $input );
	invoice_assert( 2 === $options[ $counter ] && ! isset( $options[ $counter . '_lock' ] ), 'Stale lock is taken over and the send increments' );
	$input['send']        = false;
	$GLOBALS['logo_path'] = '/uploads/brand logo.png';
	invoice_assert( str_contains( $ability->execute( $input )['html'], 'https://example.org/uploads/brand%20logo.png' ), 'Upload logo converted to public URL' );
	$GLOBALS['logo_path'] = '/wordpress/wp-content/themes/example/logo.png';
	invoice_assert( str_contains( $ability->execute( $input )['html'], 'https://example.org/wp-content/themes/example/logo.png' ), 'Theme logo converted to public URL' );
	$GLOBALS['logo_path'] = '/private/secret.png';
	invoice_assert( ! str_contains( $ability->execute( $input )['html'], '<img' ), 'Unmappable logo falls back to site name' );
	require_once dirname( __DIR__ ) . '/inc/Cli/Commands/InvoiceCommand.php';
	$command = new \DataMachineBusiness\Cli\Commands\InvoiceCommand();
	$file    = sys_get_temp_dir() . '/invoice-test-' . uniqid() . '.html';
	unset( $input['total'] );
	try {
		$command->create(
			array(),
			array(
				'data'         => wp_json_encode( $input ),
				'preview-file' => $file,
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read explicit test artifact.
		$written = file_get_contents( $file );
		invoice_assert( str_contains( $written, '1,025.93' ) && 2 === $options[ $counter ], 'CLI writes preview without consuming number' );
		$command->create(
			array(),
			array(
				'data'         => wp_json_encode( $input ),
				'send'         => true,
				'preview-file' => $file,
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read explicit test artifact.
		$written = file_get_contents( $file );
		invoice_assert( str_contains( $written, 'INV-2026-0003' ) && 3 === $options[ $counter ], 'CLI send writes the actual sent HTML and consumes one number' );
		try {
			$command->create( array(), array( 'data' => '[]' ) );
			invoice_assert( false, 'CLI rejects non-object JSON' );
		} catch ( \RuntimeException $exception ) {
			invoice_assert( str_contains( $exception->getMessage(), 'JSON object' ), 'CLI rejects non-object JSON' );
		}
	} finally {
		if ( file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove test artifact.
			unlink( $file );
		}
	}
	echo esc_html( $passes ) . " invoice assertions passed.\n";
}
