<?php
/**
 * WP-CLI Messages Command
 *
 * Cross-platform direct-message access via the generic messaging API.
 * Returns normalized conversation and message shapes regardless of platform.
 *
 * @package    DataMachineSocials
 * @subpackage Cli\Commands
 * @since      0.21.0
 */

namespace DataMachineSocials\Cli\Commands;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Read and send direct messages across social platforms.
 *
 * Provides a unified interface for DM operations across supported platforms.
 * Conversations and messages are returned in normalized shapes with is_echo
 * set on messages sent by the connected account.
 *
 * ## EXAMPLES
 *
 *     # List Instagram DM conversations
 *     wp datamachine-socials messages list instagram
 *
 *     # List conversations as JSON
 *     wp datamachine-socials messages list instagram --format=json
 *
 *     # Read the messages in one conversation
 *     wp datamachine-socials messages read instagram 17891234567890
 *
 *     # Send a reply to a user
 *     wp datamachine-socials messages send instagram 1789555000000 "Thanks for reaching out!"
 */
class MessagesCommand {

	/**
	 * Platform-to-read-ability slug mapping.
	 */
	private const READ_SLUG_MAP = array(
		'instagram' => 'datamachine/instagram-read',
	);

	/**
	 * Platform-to-send-ability slug mapping.
	 */
	private const SEND_SLUG_MAP = array(
		'instagram' => 'datamachine/instagram-message-send',
	);

	/**
	 * List direct-message conversations.
	 *
	 * ## OPTIONS
	 *
	 * <platform>
	 * : Platform slug (instagram).
	 *
	 * [--limit=<count>]
	 * : Maximum conversations to return.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--user=<igsid>]
	 * : Fetch only the conversation with this platform-scoped user ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials messages list instagram
	 *     wp datamachine-socials messages list instagram --limit=10 --format=json
	 */
	public function list_( $args, $assoc_args ) {
		$platform = $args[0] ?? '';

		if ( ! isset( self::READ_SLUG_MAP[ $platform ] ) ) {
			WP_CLI::error( "Unsupported platform: {$platform}. Supported: " . implode( ', ', array_keys( self::READ_SLUG_MAP ) ) );
		}

		$ability = $this->get_ability( self::READ_SLUG_MAP[ $platform ] );

		WP_CLI::log( "Fetching {$platform} conversations..." );

		$payload = array(
			'action' => 'conversations',
			'limit'  => absint( $assoc_args['limit'] ?? 25 ),
		);
		if ( ! empty( $assoc_args['user'] ) ) {
			$payload['user_id'] = $assoc_args['user'];
		}

		$result = $ability->execute( $payload );

		if ( is_wp_error( $result ) || ! $result['success'] ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : $result['error'] );
		}

		$conversations = $result['data']['conversations'] ?? array();
		$count         = count( $conversations );

		$format = $assoc_args['format'] ?? 'table';

		if ( 'count' === $format ) {
			WP_CLI::log( (string) $count );
			return;
		}

		if ( empty( $conversations ) ) {
			WP_CLI::warning( 'No conversations found.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( array(
				'platform'      => $platform,
				'count'         => $count,
				'conversations' => $conversations,
			), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( "Found {$count} conversations" );
		WP_CLI::log( '' );

		foreach ( $conversations as $c ) {
			$date   = ! empty( $c['updated_time'] ) ? wp_date( 'Y-m-d H:i', strtotime( $c['updated_time'] ) ) : '';
			$who    = $c['participant']['username'] ?? $c['participant']['id'] ?? '';
			$unread = ! empty( $c['unread_count'] ) ? sprintf( '  [%d unread]', (int) $c['unread_count'] ) : '';

			WP_CLI::log( sprintf(
				'  %s  @%-20s %s%s',
				$c['id'],
				$who,
				$date,
				$unread
			) );
		}

		if ( ! empty( $result['data']['has_next'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'More conversations exist; pass --limit higher or a provider cursor to page through.' );
		}
	}

	/**
	 * Read the messages in a conversation.
	 *
	 * Only the 20 most recent messages in an Instagram conversation have
	 * detail; older message IDs error as deleted on Instagram.
	 *
	 * ## OPTIONS
	 *
	 * <platform>
	 * : Platform slug (instagram).
	 *
	 * <conversation_id>
	 * : Platform-specific conversation ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials messages read instagram 17891234567890
	 *     wp datamachine-socials messages read instagram 17891234567890 --format=json
	 */
	public function read( $args, $assoc_args ) {
		$platform        = $args[0] ?? '';
		$conversation_id = $args[1] ?? '';

		if ( ! isset( self::READ_SLUG_MAP[ $platform ] ) ) {
			WP_CLI::error( "Unsupported platform: {$platform}. Supported: " . implode( ', ', array_keys( self::READ_SLUG_MAP ) ) );
		}

		if ( empty( $conversation_id ) ) {
			WP_CLI::error( 'conversation_id is required.' );
		}

		$ability = $this->get_ability( self::READ_SLUG_MAP[ $platform ] );

		$result = $ability->execute( array(
			'action'          => 'messages',
			'conversation_id' => $conversation_id,
		) );

		if ( is_wp_error( $result ) || ! $result['success'] ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : $result['error'] );
		}

		$messages = $result['data']['messages'] ?? array();
		$count    = count( $messages );

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( array(
				'platform'        => $platform,
				'conversation_id' => $conversation_id,
				'count'           => $count,
				'messages'        => $messages,
			), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( empty( $messages ) ) {
			WP_CLI::warning( 'No messages found in this conversation.' );
			return;
		}

		WP_CLI::success( "Found {$count} messages" );
		WP_CLI::log( '' );

		foreach ( $messages as $m ) {
			$who  = ! empty( $m['is_echo'] ) ? 'you' : '@' . ( $m['from']['username'] ?? $m['from']['id'] ?? '' );
			$date = ! empty( $m['created_time'] ) ? wp_date( 'Y-m-d H:i', strtotime( $m['created_time'] ) ) : '';
			$text = (string) ( $m['text'] ?? '' );

			if ( empty( $text ) && ! empty( $m['attachments'] ) ) {
				$text = '[attachment]';
			}

			WP_CLI::log( sprintf(
				'  [%s] %-25s %s',
				$date,
				$who,
				mb_substr( $text, 0, 120 )
			) );
		}
	}

	/**
	 * Send a direct message to a user.
	 *
	 * Instagram only allows replies inside the 24-hour messaging window;
	 * outside it the send fails with a window-closed error.
	 *
	 * ## OPTIONS
	 *
	 * <platform>
	 * : Platform slug (instagram).
	 *
	 * <recipient_id>
	 * : Platform-scoped recipient user ID (IGSID for Instagram).
	 *
	 * <message>
	 * : The message text.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials messages send instagram 1789555000000 "Thanks for reaching out!"
	 */
	public function send( $args ) {
		$platform     = $args[0] ?? '';
		$recipient_id = $args[1] ?? '';
		$message      = $args[2] ?? '';

		if ( ! isset( self::SEND_SLUG_MAP[ $platform ] ) ) {
			WP_CLI::error( "Message send not supported for platform: {$platform}. Supported: " . implode( ', ', array_keys( self::SEND_SLUG_MAP ) ) );
		}

		if ( empty( $recipient_id ) || empty( $message ) ) {
			WP_CLI::error( 'recipient_id and message are required.' );
		}

		$ability = $this->get_ability( self::SEND_SLUG_MAP[ $platform ] );

		$result = $ability->execute( array(
			'recipient_id' => $recipient_id,
			'message'      => $message,
		) );

		if ( is_wp_error( $result ) || ! $result['success'] ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : $result['error'] );
		}

		WP_CLI::success( "Message sent on {$platform}!" );
		WP_CLI::log( 'Recipient ID: ' . ( $result['data']['recipient_id'] ?? $recipient_id ) );
		WP_CLI::log( 'Message ID:   ' . ( $result['data']['message_id'] ?? '' ) );
	}

	/**
	 * Get an ability by slug, or exit with error.
	 *
	 * @param string $slug Ability slug.
	 * @return object Ability instance.
	 */
	private function get_ability( string $slug ) {
		$ability = wp_get_ability( $slug );
		if ( ! $ability ) {
			WP_CLI::error( "{$slug} ability not registered." );
		}

		return $ability;
	}
}
