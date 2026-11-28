<?php
/**
 * Discord fetch handler — reads messages from channels.
 *
 * Thin wrapper that maps handler config to ability input, delegates
 * the API call to FetchMessagesDiscordAbility, and handles pipeline-specific
 * concerns like deduplication and data packet formatting.
 *
 * @package DataMachineBusiness
 * @subpackage Handlers\Discord
 * @since 0.3.0
 */

namespace DataMachineBusiness\Handlers\Discord;

use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineBusiness\Abilities\Discord\FetchMessagesDiscordAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DiscordFetch extends FetchHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'discord_fetch' );

		self::registerHandler(
			'discord_fetch',
			'fetch',
			self::class,
			__( 'Discord Messages', 'data-machine-business' ),
			__( 'Fetch messages from Discord channels', 'data-machine-business' ),
			true,
			\DataMachineBusiness\OAuth\Providers\DiscordAuth::class,
			DiscordFetchSettings::class,
			null,
			'discord'
		);
	}

	/**
	 * Fetch messages from a Discord channel.
	 *
	 * Delegates the API call to FetchMessagesDiscordAbility, then handles
	 * pipeline-specific deduplication and data packet formatting.
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$channel_id = trim( $config['discord_fetch_channel_id'] ?? '' );
		if ( empty( $channel_id ) ) {
			$context->log( 'error', 'Discord: Channel ID is required.' );
			return array();
		}

		// Build ability input from handler config
		$ability_input = array(
			'channel_id' => $channel_id,
			'limit'      => min( max( intval( $config['discord_fetch_limit'] ?? 50 ), 1 ), 100 ),
		);

		$before = $config['discord_fetch_before'] ?? '';
		$after  = $config['discord_fetch_after'] ?? '';
		if ( ! empty( $before ) ) {
			$ability_input['before'] = $before;
		}
		if ( ! empty( $after ) ) {
			$ability_input['after'] = $after;
		}

		$context->log(
			'debug',
			'Discord: Fetching messages via ability.',
			array(
				'channel_id' => $channel_id,
				'limit'      => $ability_input['limit'],
			)
		);

		// Delegate to the ability — single source of API logic
		$ability = new FetchMessagesDiscordAbility();
		$result  = $ability->execute( $ability_input );

		if ( empty( $result['success'] ) ) {
			$context->log( 'error', 'Discord: ' . ( $result['error'] ?? 'Unknown error' ) );
			return array();
		}

		$messages = $result['data']['messages'] ?? array();

		if ( empty( $messages ) ) {
			$context->log( 'debug', 'Discord: No messages found.' );
			return array();
		}

		$context->log(
			'debug',
			'Discord: Retrieved messages.',
			array(
				'count' => count( $messages ),
			)
		);

		// Pipeline-specific: deduplication and data packet formatting
		foreach ( $messages as $message ) {
			$message_id = $message['id'] ?? '';
			$item_id    = $channel_id . '_' . $message_id;

			if ( $context->isItemProcessed( $item_id ) ) {
				continue;
			}

			$author   = $message['author'] ?? array();
			$username = $author['username'] ?? 'Unknown';
			$content  = $message['content'] ?? '';
			$msg_type = $message['type'] ?? 0;

			// Skip non-default message types (joins, pins, etc.)
			// Discord type 0 = DEFAULT (regular message)
			if ( 0 !== $msg_type ) {
				continue;
			}

			// Skip empty messages (could be embed-only or attachment-only)
			if ( empty( $content ) ) {
				continue;
			}

			$context->markItemProcessed( $item_id );

			$context->storeEngineData(
				array(
					'source_url' => '',
					'image_url'  => '',
				)
			);

			$context->log(
				'debug',
				'Discord: Processed message.',
				array( 'message_id' => $message_id )
			);

			// Return the first unprocessed message as a data packet
			return array(
				'title'    => 'Discord message from ' . $username,
				'content'  => $content,
				'metadata' => array(
					'source_type'     => 'discord_fetch',
					'channel_id'      => $channel_id,
					'message_id'      => $message_id,
					'author_id'       => $author['id'] ?? '',
					'author_username' => $username,
					'timestamp'       => $message['timestamp'] ?? '',
					'item_identifier' => $item_id,
				),
			);
		}

		$context->log( 'debug', 'Discord: No unprocessed messages found.' );
		return array();
	}

	/**
	 * Get handler display label.
	 *
	 * @return string Handler label
	 */
	public static function get_label(): string {
		return __( 'Discord Fetch', 'data-machine-business' );
	}
}
