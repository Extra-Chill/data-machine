<?php
/**
 * Read Pinterest Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class ReadPinterest extends AbstractSocialTool {

	protected string $tool_name = 'read_pinterest';

	protected string $platform = 'pinterest';

	protected string $platform_label = 'Pinterest';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Read Pinterest pins and boards. List pins, get pin details, list boards, or list board pins. Requires Pinterest access token to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'action'   => array(
						'type'        => 'string',
						'description' => 'Action: "pins" (user pins), "pin" (single pin), "boards" (list boards), "board_pins" (board pins). Defaults to "pins".',
						'enum'        => array( 'pins', 'pin', 'boards', 'board_pins' ),
					),
					'pin_id'   => array(
						'type'        => 'string',
						'description' => 'Pinterest pin ID. Required for "pin" action.',
					),
					'board_id' => array(
						'type'        => 'string',
						'description' => 'Pinterest board ID. Required for "board_pins" action.',
					),
					'limit'    => array(
						'type'        => 'integer',
						'description' => 'Number of items to return (max 100). Defaults to 25.',
					),
					'bookmark' => array(
						'type'        => 'string',
						'description' => 'Pagination bookmark for next page.',
					),
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'read_pinterest';
		$action    = $parameters['action'] ?? 'pins';

		if ( 'pin' === $action && empty( $parameters['pin_id'] ) ) {
			return $this->buildErrorResponse( 'pin_id is required for the pin action', $tool_name );
		}
		if ( 'board_pins' === $action && empty( $parameters['board_id'] ) ) {
			return $this->buildErrorResponse( 'board_id is required for the board_pins action', $tool_name );
		}

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$ability = wp_get_ability( 'datamachine/pinterest-read' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/pinterest-read ability not registered', $tool_name );
		}
		$ability_instance = $ability;
		$input            = array( 'action' => sanitize_text_field( $action ) );

		if ( ! empty( $parameters['pin_id'] ) ) {
			$input['pin_id'] = sanitize_text_field( $parameters['pin_id'] );
		}
		if ( ! empty( $parameters['board_id'] ) ) {
			$input['board_id'] = sanitize_text_field( $parameters['board_id'] );
		}
		if ( ! empty( $parameters['limit'] ) ) {
			$input['limit'] = absint( $parameters['limit'] );
		}
		if ( ! empty( $parameters['bookmark'] ) ) {
			$input['bookmark'] = sanitize_text_field( $parameters['bookmark'] );
		}

		$result = $ability_instance->execute( $input );

		if ( is_wp_error( $result ) || ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : $this->getAbilityError( $result, 'Failed to read from Pinterest' ), $tool_name );
		}

		return array(
			'success'   => true,
			'data'      => $result['data'] ?? array(),
			'tool_name' => $tool_name,
		);
	}
}
