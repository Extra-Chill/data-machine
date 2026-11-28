<?php
/**
 * Slack fetch handler — reads messages from channels.
 *
 * Thin wrapper that maps handler config to ability input, delegates
 * the API call to FetchMessagesSlackAbility, and handles pipeline-specific
 * concerns like deduplication and data packet formatting.
 *
 * @package DataMachineBusiness
 * @subpackage Handlers\Slack
 * @since 0.2.0
 */

namespace DataMachineBusiness\Handlers\Slack;

use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineBusiness\Abilities\Slack\FetchMessagesSlackAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SlackFetch extends FetchHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'slack_fetch' );

		self::registerHandler(
			'slack_fetch',
			'fetch',
			self::class,
			__( 'Slack Messages', 'data-machine-business' ),
			__( 'Fetch messages from Slack channels', 'data-machine-business' ),
			true,
			\DataMachineBusiness\OAuth\Providers\SlackAuth::class,
			SlackFetchSettings::class,
			null,
			'slack'
		);
	}

	/**
	 * Fetch messages from a Slack channel.
	 *
	 * Delegates the API call to FetchMessagesSlackAbility, then handles
	 * pipeline-specific deduplication and data packet formatting.
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$channel = trim( $config['slack_fetch_channel'] ?? '' );
		if ( empty( $channel ) ) {
			$context->log( 'error', 'Slack: Channel ID is required.' );
			return array();
		}

		// Build ability input from handler config
		$ability_input = array(
			'channel' => $channel,
			'limit'   => min( max( intval( $config['slack_fetch_limit'] ?? 20 ), 1 ), 1000 ),
		);

		$oldest = $config['slack_fetch_oldest'] ?? '';
		$latest = $config['slack_fetch_latest'] ?? '';
		if ( ! empty( $oldest ) ) {
			$ability_input['oldest'] = $oldest;
		}
		if ( ! empty( $latest ) ) {
			$ability_input['latest'] = $latest;
		}

		$context->log(
			'debug',
			'Slack: Fetching messages via ability.',
			array(
				'channel' => $channel,
				'limit'   => $ability_input['limit'],
			)
		);

		// Delegate to the ability — single source of API logic
		$ability = new FetchMessagesSlackAbility();
		$result  = $ability->execute( $ability_input );

		if ( empty( $result['success'] ) ) {
			$context->log( 'error', 'Slack: ' . ( $result['error'] ?? 'Unknown error' ) );
			return array();
		}

		$messages = $result['data']['messages'] ?? array();

		if ( empty( $messages ) ) {
			$context->log( 'debug', 'Slack: No messages found.' );
			return array();
		}

		$context->log(
			'debug',
			'Slack: Retrieved messages.',
			array(
				'count'    => count( $messages ),
				'has_more' => $result['data']['has_more'] ?? false,
			)
		);

		// Pipeline-specific: deduplication and data packet formatting
		foreach ( $messages as $message ) {
			$timestamp = $message['ts'] ?? '';
			$item_id   = $channel . '_' . $timestamp;

			if ( $context->isItemProcessed( $item_id ) ) {
				continue;
			}

			$user_id = $message['user'] ?? '';
			$text    = $message['text'] ?? '';
			$subtype = $message['subtype'] ?? null;

			// Skip join/leave and other non-message subtypes
			if ( in_array( $subtype, array( 'channel_join', 'channel_leave', 'bot_add', 'bot_remove' ), true ) ) {
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
				'Slack: Processed message.',
				array( 'message_ts' => $timestamp )
			);

			// Return the first unprocessed message as a data packet
			return array(
				'title'    => 'Slack message from ' . $user_id,
				'content'  => $text,
				'metadata' => array(
					'source_type'     => 'slack_fetch',
					'channel'         => $channel,
					'message_ts'      => $timestamp,
					'user_id'         => $user_id,
					'subtype'         => $subtype,
					'item_identifier' => $item_id,
				),
			);
		}

		$context->log( 'debug', 'Slack: No unprocessed messages found.' );
		return array();
	}

	/**
	 * Get handler display label.
	 *
	 * @return string Handler label
	 */
	public static function get_label(): string {
		return __( 'Slack Fetch', 'data-machine-business' );
	}
}
