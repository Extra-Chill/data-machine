<?php
/** Real credential encryption and PHPMailer MIME delivery, with deterministic IMAP/SMTP peers. */

namespace {
	$wp = getenv( 'WORDPRESS_PATH' );
	if ( ! $wp || ! is_file( $wp . '/wp-includes/PHPMailer/PHPMailer.php' ) ) {
		fwrite( STDERR, "Set WORDPRESS_PATH to a WordPress checkout for the real PHPMailer library.\n" );
		exit( 1 );
	}
	define( 'ABSPATH', rtrim( $wp, '/' ) . '/' );
	define( 'WPINC', 'wp-includes' );
	foreach ( array( 'Exception', 'PHPMailer', 'SMTP' ) as $class ) {
		require_once ABSPATH . WPINC . '/PHPMailer/' . $class . '.php';
	}
	foreach ( array( 'FT_UID' => 1, 'FT_PEEK' => 2 ) as $constant => $value ) {
		if ( ! defined( $constant ) ) { define( $constant, $value ); }
	}
	if ( ! function_exists( 'imap_open' ) ) { function imap_open( mixed ...$args ): bool { return false; } }
}

namespace DataMachine\Abilities {
	class PermissionHelper {
		public static int $user = 1;
		public static int $agent = 0;
		public static function can( string $action ): bool { return self::$user > 0 || self::$agent > 0; }
		public static function can_manage(): bool { return self::$user === 1 && self::$agent === 0; }
		public static function acting_user_id(): int { return self::$user; }
		public static function get_acting_agent_id(): int { return self::$agent; }
	}
	class AbilityRegistration {
		public static function on_abilities_api_init( callable $callback ): void { $callback(); }
	}
}

namespace DataMachine\Core\Database\Agents {
	class Agents { public function get_agent( int $id ): ?array { return 7 === $id ? array( 'owner_id' => 1 ) : null; } }
}

namespace DataMachine\Abilities\Email {
	function imap_open( string $mailbox, string $user, string $password ): object { return (object) array( 'user' => $user ); }
	function imap_close( mixed $connection ): bool { $GLOBALS['closed']++; return true; }
	function imap_fetch_overview( mixed $connection, string $uid, int $flags ): array { return array( (object) array( 'size' => 1000, 'message_id' => '<invoice-' . $connection->user . '@vendor.test>', 'subject' => 'Invoice 123', 'from' => 'vendor@example.test', 'date' => '2026-10-01' ) ); }
	function imap_fetchheader( mixed $connection, int $uid, int $flags ): string { return "From: vendor@example.test\r\nTo: {$connection->user}\r\nSubject: Invoice 123\r\n"; }
	function imap_body( mixed $connection, int $uid, int $flags ): string { return 'raw original MIME body'; }
	function imap_utf8( string $value ): string { return $value; }
	function imap_fetchstructure( mixed $connection, int $uid, int $flags ): object {
		return (object) array( 'type' => 1, 'parts' => array(
			(object) array( 'type' => 1, 'parts' => array( (object) array( 'type' => 0, 'subtype' => 'PLAIN', 'encoding' => 0 ), (object) array( 'type' => 0, 'subtype' => 'HTML', 'encoding' => 4 ) ) ),
			(object) array( 'type' => 1, 'parts' => array( (object) array( 'type' => 3, 'subtype' => 'PDF', 'encoding' => 3, 'disposition' => 'attachment', 'dparameters' => array( (object) array( 'attribute' => 'filename', 'value' => 'invoice.pdf' ) ) ) ) ),
			(object) array( 'type' => 3, 'subtype' => 'PDF', 'encoding' => 3, 'disposition' => 'attachment', 'dparameters' => array( (object) array( 'attribute' => 'filename', 'value' => 'details.pdf' ) ) ),
		) );
	}
	function imap_fetchbody( mixed $connection, int $uid, string $number, int $flags ): string|false {
		$GLOBALS['fetch_flags'][] = $flags;
		if ( ! empty( $GLOBALS['fail_part'] ) && '2.1' === $number ) { return false; }
		return match ( $number ) { '1.1' => 'Original invoice text', '1.2' => '<p>Original invoice HTML</p>', '2.1' => base64_encode( '%PDF-original-invoice' ), '3' => base64_encode( '%PDF-original-details' ), default => '' };
	}
	function imap_append( mixed $connection, string $folder, string $raw, string $flags ): bool { $GLOBALS['sent_copies'][] = array( $folder, $raw ); return true; }
}

namespace {
	class TestSMTP extends \PHPMailer\PHPMailer\SMTP {
		public static array $messages = array();
		public static array $auth = array();
		private bool $active = false;
		public function connect( $host, $port = null, $timeout = 30, $options = array() ) { $this->active = true; return true; }
		public function connected() { return $this->active; }
		public function hello( $host = '' ) { return true; }
		public function startTLS() { return true; }
		public function authenticate( $username, $password, $authtype = null, $OAuth = null ) { self::$auth[] = array( $username, $password ); return true; }
		public function mail( $from ) { return true; }
		public function recipient( $address, $dsn = '' ) { return true; }
		public function data( $msg_data ) { self::$messages[] = $msg_data; return true; }
		public function quit( $close_on_error = true ) { $this->active = false; return true; }
		public function close() { $this->active = false; }
		public function getLastTransactionID() { return 'fixture-transaction'; }
	}
	$GLOBALS['options'] = array(); $GLOBALS['registered_abilities'] = array(); $GLOBALS['closed'] = 0; $GLOBALS['fetch_flags'] = array(); $GLOBALS['sent_copies'] = array();
	function get_site_option( string $name, mixed $default = false ): mixed { return $GLOBALS['options'][ $name ] ?? $default; }
	function update_site_option( string $name, mixed $value ): bool { $GLOBALS['options'][ $name ] = $value; return true; }
	function get_option( string $name, mixed $default = false ): mixed { return get_site_option( $name, $default ); }
	function update_option( string $name, mixed $value, mixed $autoload = null ): bool { return update_site_option( $name, $value ); }
	function add_option( string $name, mixed $value, mixed $deprecated = '', mixed $autoload = null ): bool { if ( isset( $GLOBALS['options'][ $name ] ) ) { return false; } return update_site_option( $name, $value ); }
	function wp_salt( string $scheme ): string { return 'mailbox-fixture-salt'; }
	function absint( mixed $value ): int { return abs( (int) $value ); }
	function sanitize_key( string $value ): string { return $value; }
	function sanitize_file_name( string $value ): string { return basename( $value ); }
	function is_email( mixed $value ): bool { return is_string( $value ) && false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
	function get_current_user_id(): int { return \DataMachine\Abilities\PermissionHelper::$user; }
	function user_can( int $id, string $capability ): bool { return 1 === $id; }
	function apply_filters( string $name, mixed $value, mixed ...$args ): mixed { return 'datamachine_auth_providers' === $name ? array( 'email_imap' => new \DataMachine\Core\Steps\Fetch\Handlers\Email\EmailAuth() ) : $value; }
	function do_action( string $name, mixed ...$args ): void { if ( 'datamachine_email_phpmailer_init' === $name ) { $args[0]->setSMTPInstance( new TestSMTP() ); } }
	function __( string $value, string $domain = '' ): string { return $value; }
	function esc_html( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	function wp_register_ability( string $name, array $definition ): void { $GLOBALS['registered_abilities'][ $name ] = $definition; }
	function get_bloginfo( string $key ): string { return 'Fixture site'; }
	function wp_date( mixed $format ): string { return '2026-10-01'; }
	function wp_mail( mixed ...$args ): bool { throw new RuntimeException( 'Named SMTP delivery must not fall back to site mail.' ); }
	class WP_Error { public function __construct( public string $code, public string $message, public array $data = array() ) {} public function get_error_code(): string { return $this->code; } public function get_error_message(): string { return $this->message; } }
	function check( bool $result, string $name ): void { if ( ! $result ) { throw new RuntimeException( $name ); } echo "PASS: {$name}\n"; }
	require_once dirname( __DIR__ ) . '/inc/Core/OAuth/BaseAuthProvider.php';
	require_once dirname( __DIR__ ) . '/inc/Core/Steps/Fetch/Handlers/Email/EmailAuth.php';
	require_once dirname( __DIR__ ) . '/inc/Abilities/Email/MailboxAbilities.php';
	require_once dirname( __DIR__ ) . '/inc/Core/Email/MailboxTransport.php';
	require_once dirname( __DIR__ ) . '/inc/Abilities/Email/ForwardEmailAbility.php';
	$abilities = new \DataMachine\Abilities\Email\MailboxAbilities();
	$forward = new \DataMachine\Abilities\Email\ForwardEmailAbility();
	$config = array( 'imap_host' => 'imap.example.test', 'imap_user' => 'work@example.test', 'imap_password' => 'work-imap-secret', 'smtp_host' => 'smtp.example.test', 'smtp_user' => 'work@example.test', 'smtp_password' => 'work-smtp-secret' );
	$result = $abilities->connectMailbox( array( 'name' => 'work', 'credentials' => $config ) );
	check( $result['success'], 'connects first user-owned inbox' );
	$config['imap_user'] = $config['smtp_user'] = 'personal@example.test';
	$config['imap_password'] = 'personal-imap-secret'; $config['smtp_password'] = 'personal-smtp-secret';
	check( $abilities->connectMailbox( array( 'name' => 'personal', 'credentials' => $config ) )['success'], 'connects second inbox without replacing first' );
	check( 2 === count( $abilities->listMailboxes( array() )['mailboxes'] ), 'lists both inboxes for their owner' );
	check( ! str_contains( wp_json_encode( $abilities->listMailboxes( array() ) ), 'secret' ), 'mailbox discovery returns no credentials' );
	$stored = $GLOBALS['options']['datamachine_auth_data']['email_imap']['principals']['user:1']['accounts'];
	check( str_starts_with( $stored['personal']['imap_password'], 'dm:enc:v1:' ) && str_starts_with( $stored['personal']['smtp_password'], 'dm:enc:v1:' ), 'IMAP and SMTP credentials are encrypted by the real storage layer' );
	$input = array( 'auth_ref' => 'email_imap:work', 'uid' => 5, 'to' => 'receipts@example.test', 'body' => 'Memo: AI subscription' );
	$result = $forward->execute( $input );
	check( ! is_wp_error( $result ) && 3 === $result['attachment_count'], 'forwards nested PDFs and original.eml using real PHPMailer MIME encoding' );
	$message = TestSMTP::$messages[0];
	check( str_contains( $message, 'work@example.test' ) && str_contains( $message, 'Memo: AI subscription' ) && str_contains( $message, 'Original invoice HTML' ), 'forward preserves sender identity, note, and HTML invoice body' );
	check( str_contains( $message, 'invoice.pdf' ) && str_contains( $message, base64_encode( '%PDF-original-invoice' ) ) && str_contains( $message, 'details.pdf' ) && str_contains( $message, 'original.eml' ), 'both original binary PDFs and complete original message reach SMTP peer' );
	check( array( 'work@example.test', 'work-smtp-secret' ) === TestSMTP::$auth[0], 'authenticates SMTP as selected work inbox' );
	check( $forward->execute( $input )['already_forwarded'] && 1 === count( TestSMTP::$messages ), 'repeated forwarding does not deliver duplicates' );
	$input['auth_ref'] = 'email_imap:personal';
	check( $forward->execute( $input )['success'] && array( 'personal@example.test', 'personal-smtp-secret' ) === TestSMTP::$auth[1], 'second inbox uses its own SMTP credentials and identity' );
	check( empty( array_filter( $GLOBALS['fetch_flags'], static fn( int $flag ): bool => ! ( $flag & FT_PEEK ) ) ), 'forwarding reads message parts without marking them seen' );
	\DataMachine\Abilities\PermissionHelper::$user = 2;
	check( is_wp_error( $forward->execute( $input ) ) && 2 === count( TestSMTP::$messages ), 'another user cannot forward from owner inboxes' );
	check( empty( $abilities->listMailboxes( array() )['mailboxes'] ), 'another user cannot discover owner inboxes' );
	\DataMachine\Abilities\PermissionHelper::$user = 1;
	check( $abilities->grantMailbox( array( 'name' => 'work', 'agent_id' => 7, 'operations' => array( 'read', 'search' ) ) )['success'], 'owner can delegate read access on one inbox' );
	\DataMachine\Abilities\PermissionHelper::$agent = 7;
	$input['auth_ref'] = 'email_imap:work';
	check( is_wp_error( $forward->execute( $input ) ), 'read-only agent delegation cannot forward mail' );
	\DataMachine\Abilities\PermissionHelper::$agent = 0;
	check( $abilities->grantMailbox( array( 'name' => 'work', 'agent_id' => 7, 'operations' => array( 'read', 'search', 'send' ) ) )['success'], 'owner can explicitly delegate sending' );
	\DataMachine\Abilities\PermissionHelper::$agent = 7;
	$input['to'] = 'receipts2@example.test';
	check( $forward->execute( $input )['success'], 'delegated agent can forward from explicitly granted mailbox' );
	$input['auth_ref'] = 'email_imap:personal';
	check( is_wp_error( $forward->execute( $input ) ), 'grant on work inbox does not authorize personal inbox' );
	\DataMachine\Abilities\PermissionHelper::$agent = 0;
	$GLOBALS['fail_part'] = true;
	$input['to'] = 'other@example.test';
	$count = count( TestSMTP::$messages );
	check( is_wp_error( $forward->execute( $input ) ) && $count === count( TestSMTP::$messages ), 'failed attachment extraction blocks partial forwarding' );
	check( $GLOBALS['closed'] >= 5, 'IMAP connections close on success, duplicates, and attachment errors' );
	require_once dirname( __DIR__ ) . '/inc/Abilities/Publish/SendEmailAbility.php';
	$send = ( new ReflectionClass( \DataMachine\Abilities\Publish\SendEmailAbility::class ) )->newInstanceWithoutConstructor();
	$result = $send->execute( array( 'auth_ref' => 'email_imap:personal', 'to' => 'receipt@example.test', 'subject' => 'Follow-up', 'body' => 'Invoice follow-up', 'from_email' => 'spoof@example.test' ) );
	check( ! is_wp_error( $result ) && $result['success'] && array( 'personal@example.test', 'personal-smtp-secret' ) === end( TestSMTP::$auth ), 'existing send ability uses mailbox SMTP rather than site mail' );
	check( str_contains( end( TestSMTP::$messages ), 'personal@example.test' ) && ! str_contains( end( TestSMTP::$messages ), 'spoof@example.test' ), 'existing send ability pins authenticated sender and rejects supplied spoof identity' );
	echo "Multi-inbox forwarding smoke passed. No external mail sent.\n";
}
