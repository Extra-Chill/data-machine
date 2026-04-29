<?php
/**
 * WP_Agent definition object.
 *
 * @package DataMachine\Engine\Agents
 * @since   0.99.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent' ) ) {
	/**
	 * Declarative agent definition.
	 *
	 * This mirrors WordPress value-object vocabulary while the underlying
	 * registry still lives in Data Machine. Constructing an agent definition
	 * does not create Data Machine DB rows, access records, directories, or
	 * scaffold files.
	 *
	 * @since 0.99.0
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
