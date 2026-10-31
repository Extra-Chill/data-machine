<?php
/** Fetch public Business/Creator posts by username. @package DataMachineSocials */
namespace DataMachineSocials\Handlers\Instagram;

use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\FreshCandidateCollector;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineSocials\Abilities\Instagram\FetchInstagramAccountAbility;

defined( 'ABSPATH' ) || exit;

class InstagramAccount extends FetchHandler {
	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'instagram_account' );
		self::registerHandler( 'instagram_account', 'fetch', self::class, 'Instagram account (public)', 'Fetch public Business/Creator posts by username', true, InstagramAuth::class, InstagramAccountSettings::class, null, 'instagram' );
	}

	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$config['max_items'] = (int) ( $config['max_items'] ?? $this->getDefaultMaxItems() );
		$collector           = new FreshCandidateCollector( $context, $config['max_items'] );
		$result              = ( new FetchInstagramAccountAbility() )->executeWithCollector( $config, $collector );
		if ( is_wp_error( $result ) ) {
			$context->log( 'error', $result->get_error_message(), array( 'code' => $result->get_error_code() ) );
			return array();
		}
		$items = array();
		foreach ( $result['items'] as $candidate ) {
			$data       = $candidate['data'];
			$url        = $data['image_info']['url'] ?? '';
			$extension  = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			$mime_types = array(
				'jpg'  => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'png'  => 'image/png',
				'webp' => 'image/webp',
			);
			if ( ! isset( $mime_types[ $extension ] ) ) {
				$extension = 'jpg';
			}
			$stored = $url ? $context->downloadFile( $url, 'instagram_image_' . $candidate['item_id'] . '.' . $extension, array( 'timeout' => 30 ) ) : null;
			// Never emit a caption-only item: downstream vision requires a local flyer.
			if ( ! $stored ) {
				$context->log( 'warning', 'Instagram: Unable to store post image', array( 'media_id' => $candidate['item_id'] ) );
				continue;
			}
			$data['file_info'] = array(
				'file_path' => $stored['path'],
				'file_name' => $stored['filename'],
				'file_size' => $stored['size'],
				'mime_type' => $mime_types[ $extension ],
			);
			unset( $data['image_info'] );
			$data['metadata']['_engine_data'] = array(
				'source_url'      => $candidate['source_url'],
				'image_file_path' => $stored['path'],
			);
			$items[]                          = $data;
		}
		return $items ? array( 'items' => $items ) : array();
	}

	public static function get_label(): string {
		return 'Instagram account (public)';
	}
}
