<?php
/**
* Placeholder settings container for Threads handler.
*
* @package DataMachineSocials\Handlers\Threads
*/

namespace DataMachineSocials\Handlers\Threads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ThreadsSettings {
	/**
	* Settings payload placeholder.
	*/
	private array $settings = array();

	public function __construct( array $settings = array() ) {
		$this->settings = $settings;
	}

	public function all(): array {
		return $this->settings;
	}
}
