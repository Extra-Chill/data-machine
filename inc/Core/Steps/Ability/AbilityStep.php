<?php
/**
 * Ability step.
 *
 * @package DataMachine\Core\Steps\Ability
 */

namespace DataMachine\Core\Steps\Ability;

use DataMachine\Core\AbilityResult;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\Step;
use DataMachine\Core\Steps\StepTypeRegistrationTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs a registered WordPress Ability as a deterministic pipeline step.
 */
class AbilityStep extends Step {

	use StepTypeRegistrationTrait;

	public function __construct() {
		parent::__construct( 'ability' );

		self::registerStepType(
			slug: 'ability',
			label: 'Ability',
			description: 'Run a registered WordPress Ability as a pipeline step',
			class_name: self::class,
			position: 65,
			usesHandler: false,
			hasPipelineConfig: false,
			consumeAllPackets: false,
			stepSettings: array(
				'config_type' => 'handler',
				'modal_type'  => 'configure-step',
				'button_text' => 'Configure',
				'label'       => 'Ability Configuration',
			),
			showSettingsDisplay: false
		);
	}

	protected function validateStepConfiguration(): bool {
		$settings = $this->getHandlerConfig();
		$ability  = trim( (string) ( $settings['ability'] ?? '' ) );

		if ( '' === $ability ) {
			$this->logConfigurationError( 'Ability step requires an ability slug in flow_step_settings.' );
			return false;
		}

		if ( ! class_exists( '\WP_Abilities_Registry' ) ) {
			$this->logConfigurationError( 'Ability step requires the WordPress Abilities API.' );
			return false;
		}

		$registry = \WP_Abilities_Registry::get_instance();
		if ( method_exists( $registry, 'is_registered' ) && ! $registry->is_registered( $ability ) ) {
			$this->logConfigurationError( 'Configured ability is not registered.', array( 'ability' => $ability ) );
			return false;
		}

		return true;
	}

	protected function executeStep(): array {
		$settings     = $this->getHandlerConfig();
		$ability_slug = trim( (string) ( $settings['ability'] ?? '' ) );
		$input        = is_array( $settings['input'] ?? null ) ? $settings['input'] : array();

		$registry = \WP_Abilities_Registry::get_instance();
		$ability  = $registry->get_registered( $ability_slug );

		if ( ! $ability ) {
			return $this->addResultPacket(
				$ability_slug,
				array(
					'success' => false,
					'error'   => 'Configured ability is not registered.',
				),
				'ability_not_registered'
			);
		}

		$permission = $ability->check_permissions( $input );
		if ( is_wp_error( $permission ) ) {
			return $this->addResultPacket(
				$ability_slug,
				array(
					'success'       => false,
					'error'         => $permission->get_error_message(),
					'wp_error_code' => $permission->get_error_code(),
				),
				'ability_permission_denied'
			);
		}

		if ( true !== $permission ) {
			return $this->addResultPacket(
				$ability_slug,
				array(
					'success' => false,
					'error'   => 'Configured ability is not permitted.',
				),
				'ability_permission_denied'
			);
		}

		$result = AbilityResult::normalize( $ability->execute( $input ) );
		return $this->addResultPacket(
			$ability_slug,
			$result,
			empty( $result['success'] ) ? 'ability_execution_failed' : ''
		);
	}

	/**
	 * @param array<string,mixed> $result Normalized ability result.
	 */
	private function addResultPacket( string $ability_slug, array $result, string $failure_reason = '' ): array {
		$success = ! array_key_exists( 'success', $result ) || true === (bool) $result['success'];

		$packet = new DataPacket(
			array(
				'title'  => $success ? 'Ability Step Completed' : 'Ability Step Failed',
				'body'   => wp_json_encode( $result ),
				'result' => $result,
			),
			array_filter(
				array(
					'source_type'            => 'ability',
					'flow_step_id'           => $this->flow_step_id,
					'ability'                => $ability_slug,
					'success'                => $success,
					'step_execution_success' => $success,
					'failure_reason'         => $failure_reason,
				),
				static fn( $value ) => '' !== $value && null !== $value
			),
			'ability_result'
		);

		return $packet->addTo( $this->dataPackets );
	}
}
