<?php
/**
 * PageSpeed Insights — AI agent wrapper for the pagespeed ability.
 *
 * Provides the AI-callable tool interface and settings page configuration.
 * Delegates actual auditing to the datamachine/pagespeed ability.
 *
 * @package DataMachine\Engine\AI\Tools\Global
 * @since 0.31.0
 */

namespace DataMachine\Engine\AI\Tools\Global;

defined( 'ABSPATH' ) || exit;

use DataMachine\Abilities\Analytics\PageSpeedAbilities;
use DataMachine\Engine\AI\Tools\BaseTool;

class PageSpeed extends BaseTool {

	public function __construct() {
		$this->registerConfigurationHandlers( 'pagespeed' );
		$this->registerGlobalTool( 'pagespeed', array( $this, 'getToolDefinition' ) );
	}

	/**
	 * Execute PageSpeed audit by delegating to the ability.
	 *
	 * @param array $parameters Contains 'action' and optional parameters.
	 * @param array $tool_def   Tool definition (unused).
	 * @return array Result from the ability.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$ability = wp_get_ability( 'datamachine/pagespeed' );

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'PageSpeed ability not registered. Ensure WordPress 6.9+ and PageSpeedAbilities is loaded.',
				'pagespeed'
			);
		}

		$result = $ability->execute( $parameters );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				$result->get_error_message(),
				'pagespeed'
			);
		}

		if ( ! empty( $result['error'] ) ) {
			return $this->buildErrorResponse(
				$result['error'],
				'pagespeed'
			);
		}

		$result['tool_name'] = 'pagespeed';
		return $result;
	}

	/**
	 * Get tool definition for AI agents.
	 *
	 * @return array Tool definition array.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'           => __CLASS__,
			'method'          => 'handle_tool_call',
			'description'     => 'Run Google PageSpeed Insights (Lighthouse) audits on any URL. Get performance scores, Core Web Vitals (LCP, CLS, INP, FCP, TTFB), accessibility and SEO scores, and actionable optimization opportunities with estimated savings. Use to audit page speed, monitor site health, and identify performance improvements.',
			'requires_config' => false,
			'parameters'      => array(
				'action'   => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action to perform: analyze (full Lighthouse audit with all category scores and key metrics), performance (focused Core Web Vitals and performance metrics), opportunities (optimization suggestions sorted by estimated savings).',
				),
				'url'      => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'URL to analyze. Defaults to the WordPress site home URL.',
				),
				'strategy' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Device strategy: mobile (default) or desktop.',
				),
			),
		);
	}

	/**
	 * Check if PageSpeed Insights is configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return PageSpeedAbilities::is_configured();
	}

	/**
	 * Get stored configuration.
	 *
	 * @return array
	 */
	public static function get_config(): array {
		return PageSpeedAbilities::get_config();
	}

	/**
	 * Check if this tool is configured.
	 *
	 * @param bool   $configured Current status.
	 * @param string $tool_id    Tool identifier.
	 * @return bool
	 */
	public function check_configuration( $configured, $tool_id ) {
		if ( 'pagespeed' !== $tool_id ) {
			return $configured;
		}

		return self::is_configured();
	}

	/**
	 * Get current configuration.
	 *
	 * @param array  $config  Current config.
	 * @param string $tool_id Tool identifier.
	 * @return array
	 */
	public function get_configuration( $config, $tool_id ) {
		if ( 'pagespeed' !== $tool_id ) {
			return $config;
		}

		return self::get_config();
	}

	/**
	 * Save configuration from settings page.
	 *
	 * @param string $tool_id     Tool identifier.
	 * @param array  $config_data Configuration data.
	 */
	public function save_configuration( $tool_id, $config_data ) {
		if ( 'pagespeed' !== $tool_id ) {
			return;
		}

		$api_key = sanitize_text_field( $config_data['api_key'] ?? '' );

		$config = array(
			'api_key' => $api_key,
		);

		if ( update_site_option( PageSpeedAbilities::CONFIG_OPTION, $config ) ) {
			wp_send_json_success(
				array(
					'message'    => __( 'PageSpeed Insights configuration saved successfully', 'data-machine' ),
					'configured' => true,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to save configuration', 'data-machine' ) ) );
		}
	}

	/**
	 * Get configuration field definitions for the settings page.
	 *
	 * @param array  $fields  Current fields.
	 * @param string $tool_id Tool identifier.
	 * @return array
	 */
	public function get_config_fields( $fields = array(), $tool_id = '' ) {
		if ( ! empty( $tool_id ) && 'pagespeed' !== $tool_id ) {
			return $fields;
		}

		return array(
			'api_key' => array(
				'type'        => 'password',
				'label'       => __( 'Google API Key', 'data-machine' ),
				'placeholder' => __( 'Optional — increases rate limits', 'data-machine' ),
				'required'    => false,
				'description' => __( 'Optional API key for higher rate limits. PageSpeed Insights works without a key but may be rate-limited.', 'data-machine' ),
			),
		);
	}
}
