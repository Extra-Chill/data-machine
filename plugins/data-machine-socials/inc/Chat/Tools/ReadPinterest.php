<?php
/**
 * Read Pinterest Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class ReadPinterest extends BaseTool {

	public function __construct() {
		$this->registerTool( 'chat', 'read_pinterest', array( $this, 'getToolDefinition' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Read Pinterest pins and boards. List pins, get pin details, list boards, or list board pins. Requires Pinterest access token to be configured.',
			'parameters'  => array(
				'action'   => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Action: "pins" (user pins), "pin" (single pin), "boards" (list boards), "board_pins" (board pins). Defaults to "pins".',
					'enum'        => array( 'pins', 'pin', 'boards', 'board_pins' ),
				),
				'pin_id'   => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Pinterest pin ID. Required for "pin" action.',
				),
				'board_id' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Pinterest board ID. Required for "board_pins" action.',
				),
				'limit'    => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Number of items to return (max 100). Defaults to 25.',
				),
				'bookmark' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Pagination bookmark for next page.',
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

		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'pinterest' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'Pinterest auth provider not available',
				'prerequisite_missing',
				$tool_name,
				array( 'provider' => 'pinterest', 'status' => 'not_registered' ),
				array( 'action' => 'configure_pinterest_auth', 'message' => 'Configure Pinterest access token in Data Machine Settings > Auth.', 'tool_hint' => 'authenticate_handler' )
			);
		}

		if ( ! $provider->is_authenticated() ) {
			return $this->buildDiagnosticErrorResponse(
				'Pinterest is not authenticated',
				'prerequisite_missing',
				$tool_name,
				array( 'provider' => 'pinterest', 'status' => 'not_authenticated' ),
				array( 'action' => 'authenticate_pinterest', 'message' => 'Set Pinterest access token in Data Machine Settings > Auth > Pinterest.', 'tool_hint' => 'authenticate_handler' )
			);
		}

		$ability_instance = new \DataMachineSocials\Abilities\Pinterest\PinterestReadAbility();
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

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( $this->getAbilityError( $result, 'Failed to read from Pinterest' ), $tool_name );
		}

		return array(
			'success'   => true,
			'data'      => $result['data'] ?? array(),
			'tool_name' => $tool_name,
		);
	}
}
