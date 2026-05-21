<?php
/**
 * PageSpeed Insights AI tool.
 *
 * @package DataMachineBusiness\Tools
 */

namespace DataMachineBusiness\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachineBusiness\Abilities\PageSpeed\PageSpeedAbility;

defined( 'ABSPATH' ) || exit;

class PageSpeedTool extends BaseTool {

	public function __construct() {
		$this->registerConfigurationHandlers( 'pagespeed' );
		$this->registerTool( 'pagespeed', array( $this, 'get_tool_definition' ), array( 'chat', 'pipeline' ), array( 'ability' => 'datamachine/pagespeed' ) );
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_def;
		$ability = wp_get_ability( 'datamachine/pagespeed' );

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'PageSpeed ability not registered. Ensure Data Machine Business is active and WordPress 6.9+ is available.',
				'pagespeed'
			);
		}

		$result = $ability->execute( $parameters );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'pagespeed' );
		}

		if ( ! empty( $result['error'] ) ) {
			return $this->buildErrorResponse( $result['error'], 'pagespeed' );
		}

		$result['tool_name'] = 'pagespeed';
		return $result;
	}

	public function get_tool_definition(): array {
		return array(
			'class'           => __CLASS__,
			'method'          => 'handle_tool_call',
			'description'     => 'Run Google PageSpeed Insights (Lighthouse) audits on any URL. Get performance scores, Core Web Vitals (LCP, CLS, INP, FCP, TTFB), accessibility and SEO scores, and actionable optimization opportunities with estimated savings. Use to audit page speed, monitor site health, and identify performance improvements.',
			'requires_config' => false,
			'parameters'      => array(
				'type'       => 'object',
				'properties' => array(
					'action'   => array(
						'type'        => 'string',
						'description' => 'Action to perform: analyze (full Lighthouse audit with all category scores and key metrics), performance (focused Core Web Vitals and performance metrics), opportunities (optimization suggestions sorted by estimated savings).',
					),
					'url'      => array(
						'type'        => 'string',
						'description' => 'URL to analyze. Defaults to the WordPress site home URL.',
					),
					'strategy' => array(
						'type'        => 'string',
						'description' => 'Device strategy: mobile (default) or desktop.',
					),
				),
				'required'   => array( 'action' ),
			),
		);
	}

	public static function is_configured(): bool {
		return PageSpeedAbility::is_configured();
	}

	public static function get_config(): array {
		return PageSpeedAbility::get_config();
	}

	public function check_configuration( $configured, $tool_id ) {
		if ( 'pagespeed' !== $tool_id ) {
			return $configured;
		}

		return self::is_configured();
	}

	public function get_configuration( $config, $tool_id ) {
		if ( 'pagespeed' !== $tool_id ) {
			return $config;
		}

		return self::get_config();
	}

	protected function get_config_option_name(): string {
		return PageSpeedAbility::CONFIG_OPTION;
	}

	protected function validate_and_build_config( array $config_data ): array {
		return array(
			'config'  => array(
				'api_key' => sanitize_text_field( $config_data['api_key'] ?? '' ),
			),
			'message' => __( 'PageSpeed Insights configuration saved successfully', 'data-machine-business' ),
		);
	}

	public function get_config_fields( $fields = array(), $tool_id = '' ) {
		if ( ! empty( $tool_id ) && 'pagespeed' !== $tool_id ) {
			return $fields;
		}

		return array(
			'api_key' => array(
				'type'        => 'password',
				'label'       => __( 'Google API Key', 'data-machine-business' ),
				'placeholder' => __( 'Optional - increases rate limits', 'data-machine-business' ),
				'required'    => false,
				'description' => __( 'Optional API key for higher rate limits. PageSpeed Insights works without a key but may be rate-limited.', 'data-machine-business' ),
			),
		);
	}
}
