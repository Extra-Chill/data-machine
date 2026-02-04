<?php
/**
 * Step Type Abilities
 *
 * Abilities API primitives for step type discovery.
 * Replaces StepTypeService for step type data access throughout the codebase.
 *
 * @package DataMachine\Abilities
 * @since 0.12.0
 */

namespace DataMachine\Abilities;
use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class StepTypeAbilities {

	private static bool $registered = false;

	/**
	 * Cached step types.
	 *
	 * @var array|null
	 */
	private static ?array $cache = null;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	/**
	 * Clear cached step types.
	 * Call when step types are dynamically registered.
	 */
	public static function clearCache(): void {
		self::$cache = null;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerGetStepTypesAbility();
			$this->registerValidateStepTypeAbility();
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	private function registerGetStepTypesAbility(): void {
		wp_register_ability(
			'datamachine/get-step-types',
			array(
				'label'               => __( 'Get Step Types', 'data-machine' ),
				'description'         => __( 'Get all registered step types, or a single step type by slug.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'step_type_slug' => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Get a specific step type by slug', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'step_types' => array( 'type' => 'object' ),
						'count'      => array( 'type' => 'integer' ),
						'error'      => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGetStepTypes' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerValidateStepTypeAbility(): void {
		wp_register_ability(
			'datamachine/validate-step-type',
			array(
				'label'               => __( 'Validate Step Type', 'data-machine' ),
				'description'         => __( 'Validate that a step type slug exists.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'step_type' ),
					'properties' => array(
						'step_type' => array(
							'type'        => 'string',
							'description' => __( 'Step type slug to validate', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'valid'   => array( 'type' => 'boolean' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeValidateStepType' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Permission callback for abilities.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute get step types ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with step types data.
	 */
	public function executeGetStepTypes( array $input ): array {
		$step_type_slug = $input['step_type_slug'] ?? null;

		// Direct step type lookup by slug.
		if ( $step_type_slug ) {
			if ( ! is_string( $step_type_slug ) || empty( $step_type_slug ) ) {
				return array(
					'success' => false,
					'error'   => 'step_type_slug must be a non-empty string',
				);
			}

			$step_type = $this->getStepType( $step_type_slug );

			if ( ! $step_type ) {
				return array(
					'success'    => true,
					'step_types' => array(),
					'count'      => 0,
				);
			}

			return array(
				'success'    => true,
				'step_types' => array( $step_type_slug => $step_type ),
				'count'      => 1,
			);
		}

		$step_types = $this->getAllStepTypes();

		return array(
			'success'    => true,
			'step_types' => $step_types,
			'count'      => count( $step_types ),
		);
	}

	/**
	 * Execute validate step type ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with validation status.
	 */
	public function executeValidateStepType( array $input ): array {
		$slug = $input['step_type'] ?? null;

		if ( empty( $slug ) ) {
			return array(
				'success' => true,
				'valid'   => false,
				'error'   => 'step_type is required',
			);
		}

		if ( ! $this->stepTypeExists( $slug ) ) {
			$available = array_keys( $this->getAllStepTypes() );
			return array(
				'success' => true,
				'valid'   => false,
				'error'   => "Step type '{$slug}' not found. Available: " . implode( ', ', $available ),
			);
		}

		return array(
			'success' => true,
			'valid'   => true,
		);
	}

	/**
	 * Get all registered step types (cached).
	 *
	 * @return array Step types array keyed by slug.
	 */
	public function getAllStepTypes(): array {
		if ( null === self::$cache ) {
			self::$cache = apply_filters( 'datamachine_step_types', array() );
		}

		return self::$cache;
	}

	/**
	 * Get a step type definition by slug.
	 *
	 * @param string $slug Step type slug.
	 * @return array|null Step type definition or null.
	 */
	public function getStepType( string $slug ): ?array {
		$step_types = $this->getAllStepTypes();
		return $step_types[ $slug ] ?? null;
	}

	/**
	 * Check if a step type exists.
	 *
	 * @param string $slug Step type slug.
	 * @return bool True if step type exists.
	 */
	public function stepTypeExists( string $slug ): bool {
		$step_types = $this->getAllStepTypes();
		return isset( $step_types[ $slug ] );
	}

	/**
	 * Check if a step type uses handlers.
	 *
	 * @param string $slug Step type slug.
	 * @return bool True if step type uses handlers.
	 */
	public function usesHandler( string $slug ): bool {
		$step_type = $this->getStepType( $slug );

		if ( ! $step_type ) {
			return false;
		}

		return $step_type['uses_handler'] ?? true;
	}

	/**
	 * Validate a step type slug.
	 *
	 * @param string $slug Step type slug to validate.
	 * @return array{valid: bool, error?: string}
	 */
	public function validateStepType( string $slug ): array {
		if ( empty( $slug ) ) {
			return array(
				'valid' => false,
				'error' => 'step_type is required',
			);
		}

		if ( ! $this->stepTypeExists( $slug ) ) {
			$available = array_keys( $this->getAllStepTypes() );
			return array(
				'valid' => false,
				'error' => "Step type '{$slug}' not found. Available: " . implode( ', ', $available ),
			);
		}

		return array( 'valid' => true );
	}
}
