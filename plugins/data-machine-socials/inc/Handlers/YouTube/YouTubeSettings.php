<?php
/**
 * Placeholder settings container for YouTube handler.
 *
 * @package DataMachineSocials
 * @subpackage Handlers\YouTube
 */

namespace DataMachineSocials\Handlers\YouTube;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YouTubeSettings {
	/**
	 * Settings payload placeholder.
	 *
	 * @var array
	 */
	private array $settings = array();

	public function __construct( array $settings = array() ) {
		$this->settings = $settings;
	}

	public function all(): array {
		return $this->settings;
	}
}
