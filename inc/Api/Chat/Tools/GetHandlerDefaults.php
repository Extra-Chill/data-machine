<?php
/**
 * Get Handler Defaults Tool
 *
 * Retrieves site-wide handler defaults for configuration reference.
 * Helps the agent understand current site standards before configuring flows.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class GetHandlerDefaults extends BaseTool {

	public function __construct() {
		$this->registerTool( 'get_handler_defaults', array( $this, 'getToolDefinition' ), array( 'chat' ), array( 'abilities' => array( 'datamachine/get-handler-site-defaults', 'datamachine/get-handlers', 'datamachine/get-handler-config-fields' ) ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Get site-wide handler defaults. Use before configuring flows to learn the established configuration standards for this site. Returns defaults for a specific handler or all handlers.',
			'parameters'  => array(
				'handler_slug' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Handler slug to get defaults for (e.g., upsert_event, eventbrite). If omitted, returns defaults for all handlers.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$handler_slug = $parameters['handler_slug'] ?? null;

		$defaults_ability = wp_get_ability( 'datamachine/get-handler-site-defaults' );
		if ( ! $defaults_ability ) {
			return array(
				'success'   => false,
				'error'     => 'Get handler site defaults ability not available',
				'tool_name' => 'get_handler_defaults',
			);
		}

		$defaults_result = $defaults_ability->execute( array( 'handler_slug' => $handler_slug ) );
		if ( ! $this->isAbilitySuccess( $defaults_result ) ) {
			$error = $this->getAbilityError( $defaults_result, 'Failed to get handler defaults' );
			return $this->buildErrorResponse( $error, 'get_handler_defaults' );
		}

		$site_defaults = $defaults_result['defaults'] ?? array();

		// If specific handler requested
		if ( ! empty( $handler_slug ) ) {
			$handler_slug = sanitize_key( $handler_slug );

			// Validate handler exists via ability
			$handler_ability = wp_get_ability( 'datamachine/get-handlers' );
			if ( ! $handler_ability ) {
				return array(
					'success'   => false,
					'error'     => 'Get handlers ability not available',
					'tool_name' => 'get_handler_defaults',
				);
			}

			$handler_result = $handler_ability->execute( array( 'handler_slug' => $handler_slug ) );
			if ( ! $this->isAbilitySuccess( $handler_result ) || empty( $handler_result['handlers'] ) ) {
				return $this->buildErrorResponse( "Handler '{$handler_slug}' not found", 'get_handler_defaults' );
			}

			$handler_info = $handler_result['handlers'][ $handler_slug ] ?? array();

			// Get config fields via ability
			$fields_ability = wp_get_ability( 'datamachine/get-handler-config-fields' );
			$fields         = array();
			if ( $fields_ability ) {
				$fields_result = $fields_ability->execute( array( 'handler_slug' => $handler_slug ) );
				if ( $this->isAbilitySuccess( $fields_result ) ) {
					$fields = $fields_result['fields'] ?? array();
				}
			}

			return array(
				'success'   => true,
				'data'      => array(
					'handler_slug'     => $handler_slug,
					'label'            => $handler_info['label'] ?? $handler_slug,
					'defaults'         => $site_defaults,
					'available_fields' => array_keys( $fields ),
					'message'          => empty( $site_defaults )
						? "No site defaults configured for '{$handler_slug}'. Schema defaults will be used."
						: "Site defaults for '{$handler_slug}'. These values are applied when fields are not explicitly set.",
				),
				'tool_name' => 'get_handler_defaults',
			);
		}

		// Return all defaults
		$handlers_ability = wp_get_ability( 'datamachine/get-handlers' );
		if ( ! $handlers_ability ) {
			return array(
				'success'   => false,
				'error'     => 'Get handlers ability not available',
				'tool_name' => 'get_handler_defaults',
			);
		}

		$handlers_result = $handlers_ability->execute( array() );
		if ( ! $this->isAbilitySuccess( $handlers_result ) ) {
			$error = $this->getAbilityError( $handlers_result, 'Failed to get handlers' );
			return $this->buildErrorResponse( $error, 'get_handler_defaults' );
		}
		$all_handlers = $handlers_result['handlers'] ?? array();
		$summary      = array();

		foreach ( $all_handlers as $slug => $info ) {
			$handler_defaults = $site_defaults[ $slug ] ?? array();
			if ( ! empty( $handler_defaults ) ) {
				$summary[ $slug ] = array(
					'label'    => $info['label'] ?? $slug,
					'defaults' => $handler_defaults,
				);
			}
		}

		return array(
			'success'   => true,
			'data'      => array(
				'handlers_with_defaults'        => $summary,
				'total_handlers'                => count( $all_handlers ),
				'handlers_with_custom_defaults' => count( $summary ),
				'message'                       => count( $summary ) > 0
					? 'Site-wide defaults are configured for ' . count( $summary ) . ' handler(s). Use these values as reference when configuring flows.'
					: 'No site-wide defaults configured. Schema defaults will be used for all handlers.',
			),
			'tool_name' => 'get_handler_defaults',
		);
	}
}
