<?php
/**
 * WP_Agent definition object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent' ) ) {
	/**
	 * Declarative agent definition.
	 *
	 * Constructing an agent definition only prepares registration arguments. It
	 * does not create database rows, access records, directories, or scaffold
	 * files.
	 */
	class WP_Agent {

		/**
		 * Agent slug.
		 *
		 * @var string
		 */
		public string $slug;

		/**
		 * Registration arguments.
		 *
		 * @var array
		 */
		private array $args;

		/**
		 * Constructor.
		 *
		 * @param string $slug Unique agent slug.
		 * @param array  $args Registration arguments.
		 */
		public function __construct( string $slug, array $args = array() ) {
			$this->slug = sanitize_title( $slug );
			$this->args = $args;
		}

		/**
		 * Return registration arguments for the registry.
		 *
		 * @return array
		 */
		public function to_array(): array {
			return $this->args;
		}
	}
}
