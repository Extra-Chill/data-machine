<?php
/** Fetch inbound Instagram direct messages without changing conversations. @package DataMachineSocials */
namespace DataMachineSocials\Handlers\Instagram;

use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\FreshCandidateCollector;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineSocials\Abilities\Instagram\InstagramReadAbility;

defined( 'ABSPATH' ) || exit;

class InstagramMessages extends FetchHandler {
	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'instagram_messages' );
		self::registerHandler( 'instagram_messages', 'fetch', self::class, 'Instagram messages', 'Fetch inbound Instagram direct messages', true, InstagramAuth::class, InstagramMessagesSettings::class, null, 'instagram' );
	}

	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$config       = InstagramMessagesSettings::sanitize( $config );
		$reader       = new InstagramReadAbility();
		$collector    = new FreshCandidateCollector( $context );
		$cutoff       = $config['days_back'] > 0 ? time() - $config['days_back'] * DAY_IN_SECONDS : 0;
		$remaining    = $config['conversation_limit'];
		$after        = '';
		$seen_cursors = array();
		$items        = array();

		do {
			$result = $reader->execute(
				array(
					'action' => 'conversations',
					'limit'  => $remaining,
					'after'  => $after,
				)
			);
			if ( is_wp_error( $result ) ) {
				$context->log( 'error', $result->get_error_message() );
				break;
			}
			$conversations = $result['data']['conversations'] ?? array();
			foreach ( $conversations as $conversation ) {
				if ( $remaining-- <= 0 || count( $items ) >= $config['max_items'] ) {
					break;
				}
				$messages = $reader->execute(
					array(
						'action'          => 'messages',
						'conversation_id' => $conversation['id'],
					)
				);
				if ( is_wp_error( $messages ) ) {
					$context->log( 'warning', $messages->get_error_message() );
					continue;
				}
				foreach ( $messages['data']['messages'] ?? array() as $message ) {
					$timestamp = strtotime( $message['created_time'] );
					if ( ! empty( $message['is_echo'] ) || empty( $message['from']['id'] ) || empty( $message['id'] ) || false === $timestamp || $timestamp < $cutoff ) {
						continue;
					}
					$image = $this->getImageUrl( $message['attachments'] );
					if ( $config['require_attachment'] && '' === $image ) {
						continue;
					}
					// Do not let a failed download consume the fresh-item budget.
					if ( ! $collector->offer( $message['id'] ) ) {
						continue;
					}
					// Graph conversation IDs are references, not Instagram web thread IDs.
					$source_url = 'https://www.instagram.com/direct/inbox/';
					$item       = array(
						'title'    => $message['from']['username'] . ' — ' . $message['created_time'],
						'content'  => $message['text'],
						'metadata' => array(
							'source_type'     => 'instagram_messages',
							'source_url'      => $source_url,
							'item_identifier' => $message['id'],
							'conversation_id' => $conversation['id'],
							'sender_username' => $message['from']['username'],
							'timestamp'       => $message['created_time'],
							'_engine_data'    => array( 'source_url' => $source_url ),
						),
					);
					if ( '' !== $image ) {
						$extension  = strtolower( pathinfo( (string) wp_parse_url( $image, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
						$mime_types = array(
							'jpg'  => 'image/jpeg',
							'jpeg' => 'image/jpeg',
							'png'  => 'image/png',
							'webp' => 'image/webp',
							'gif'  => 'image/gif',
						);
						if ( ! isset( $mime_types[ $extension ] ) ) {
							$extension = 'jpg';
						}
						$stored = $context->downloadFile( $image, 'instagram_message_' . md5( $message['id'] ) . '.' . $extension, array( 'timeout' => 30 ) );
						if ( ! $stored ) {
							$context->log( 'warning', 'Instagram: Unable to store message image', array( 'message_id' => $message['id'] ) );
							continue;
						}
						$item['file_info']                                   = array(
							'file_path' => $stored['path'],
							'file_name' => $stored['filename'],
							'file_size' => $stored['size'],
							'mime_type' => $mime_types[ $extension ],
						);
						$item['metadata']['_engine_data']['image_file_path'] = $stored['path'];
					}
					$items[] = $item;
					if ( count( $items ) >= $config['max_items'] ) {
						break;
					}
				}
			}
			if ( count( $items ) >= $config['max_items'] ) {
				break;
			}
			$after = $result['data']['cursors']['after'] ?? '';
			if ( empty( $result['data']['has_next'] ) || '' === $after || isset( $seen_cursors[ $after ] ) || empty( $conversations ) ) {
				break;
			}
			$seen_cursors[ $after ] = true;
		} while ( $remaining > 0 );

		return $items ? array( 'items' => $items ) : array();
	}

	/** Use the first image, matching the single-image fetch handoff contract. */
	private function getImageUrl( array $attachments ): string {
		foreach ( $attachments as $attachment ) {
			if ( ! empty( $attachment['image_data']['url'] ) ) {
				return (string) $attachment['image_data']['url'];
			}
		}
		return '';
	}

	public static function get_label(): string {
		return 'Instagram messages';
	}
}
