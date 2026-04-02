<?php
/**
 * Set Handler Defaults Tool
 *
 * Updates site-wide handler defaults via conversation.
 * Allows the agent to configure default values that apply to new flows.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Engine\AI\Tools\BaseTool;

class SetHandlerDefaults extends BaseTool {

	public function __construct() {
		$this->registerTool( 'set_handler_defaults', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Set site-wide handler defaults. Use to establish standard configuration values that apply to all new flows. For example, setting post_author and include_images defaults for upsert_event.',
			'parameters'  => array(
				'handler_slug' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Handler slug to set defaults for (e.g., upsert_event, eventbrite)',
				),
				'defaults'     => array(
					'type'        => 'object',
					'required'    => true,
					'description' => 'Default configuration values to set. Keys should match handler config fields.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$handler_slug = $parameters['handler_slug'] ?? null;
		$defaults     = $parameters['defaults'] ?? null;

		// Validate handler_slug
		if ( empty( $handler_slug ) || ! is_string( $handler_slug ) ) {
			return array(
				'success'   => false,
				'error'     => 'handler_slug is required and must be a non-empty string',
				'tool_name' => 'set_handler_defaults',
			);
		}

		$handler_slug = sanitize_key( $handler_slug );

		// Validate defaults
		if ( empty( $defaults ) || ! is_array( $defaults ) ) {
			return array(
				'success'   => false,
				'error'     => 'defaults is required and must be an object with configuration values',
				'tool_name' => 'set_handler_defaults',
			);
		}

		// Get available fields for validation feedback before delegating
		$handler_abilities = new HandlerAbilities();
		$handler_info      = $handler_abilities->getHandler( $handler_slug );
		$fields            = $handler_abilities->getConfigFields( $handler_slug );
		$valid_keys        = array_keys( $fields );
		$provided_keys     = array_keys( $defaults );
		$unrecognized_keys = array_diff( $provided_keys, $valid_keys );

		// Delegate to SettingsAbilities via Abilities API
		$ability = wp_get_ability( 'datamachine/update-handler-defaults' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Handler defaults ability not available',
				'tool_name' => 'set_handler_defaults',
			);
		}

		$result = $ability->execute(
			array(
				'handler_slug' => $handler_slug,
				'defaults'     => $defaults,
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'error'     => $result->get_error_message(),
				'tool_name' => 'set_handler_defaults',
			);
		}

		if ( ! ( $result['success'] ?? false ) ) {
			return array(
				'success'   => false,
				'error'     => $result['error'] ?? 'Failed to save handler defaults',
				'tool_name' => 'set_handler_defaults',
			);
		}

		$response_data = array(
			'handler_slug' => $handler_slug,
			'label'        => $handler_info['label'] ?? $handler_slug,
			'defaults'     => $result['defaults'] ?? $defaults,
			'message'      => "Defaults updated for '{$handler_slug}'. New flows will use these values when fields are not explicitly set.",
		);

		// Warn about unrecognized keys
		if ( ! empty( $unrecognized_keys ) ) {
			$response_data['warning'] = 'Some keys are not recognized handler fields: ' . implode( ', ', $unrecognized_keys );
		}

		return array(
			'success'   => true,
			'data'      => $response_data,
			'tool_name' => 'set_handler_defaults',
		);
	}
}
