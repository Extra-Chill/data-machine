<?php
/**
 * wp-ai-client transporter wrapper with default request options.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

if ( interface_exists( '\WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface' ) && ! class_exists( __NAMESPACE__ . '\DefaultOptionsHttpTransporter', false ) ) {
	/**
	 * Applies default RequestOptions when wp-ai-client sends a request without them.
	 */
	class DefaultOptionsHttpTransporter implements \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface {

		/**
		 * @param \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface $inner           Wrapped transporter.
		 * @param mixed                                                                 $default_options Default RequestOptions instance.
		 */
		public function __construct(
			private \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface $inner,
			private $default_options
		) {}

		/**
		 * Replace the default request options used by this wrapper.
		 *
		 * @param mixed $default_options wp-ai-client RequestOptions instance.
		 * @return void
		 */
		public function setDefaultOptions( $default_options ): void {
			$this->default_options = $default_options;
		}

		/**
		 * Send the request, supplying Data Machine's defaults when none are provided.
		 *
		 * @param \WordPress\AiClient\Providers\Http\DTO\Request        $request Request object.
		 * @param \WordPress\AiClient\Providers\Http\DTO\RequestOptions|null $options Explicit RequestOptions.
		 * @return \WordPress\AiClient\Providers\Http\DTO\Response
		 */
		public function send( \WordPress\AiClient\Providers\Http\DTO\Request $request, ?\WordPress\AiClient\Providers\Http\DTO\RequestOptions $options = null ): \WordPress\AiClient\Providers\Http\DTO\Response {
			return $this->inner->send( $request, $options ?? $this->default_options );
		}
	}
}
