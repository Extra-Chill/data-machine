<?php
/**
 * Owner-safe pipeline and flow configuration contracts.
 *
 * @package DataMachine\Abilities\Pipeline
 */

namespace DataMachine\Abilities\Pipeline;

use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Abilities\PipelineStepAbilities;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Core\Steps\FlowStepConfig;

defined( 'ABSPATH' ) || exit;

class PipelineConfigurationAbilities {

	private const SCHEMA_VERSION = 'datamachine.pipeline_configuration.v1';
	private static bool $registered = false;

	private Pipelines $pipelines;
	private Flows $flows;
	private HandlerAbilities $handlers;

	public function __construct() {
		$this->pipelines = new Pipelines();
		$this->flows     = new Flows();
		$this->handlers  = new HandlerAbilities();
		if ( self::$registered ) {
			return;
		}

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init(
			function (): void {
				$this->registerGetAbility();
				$this->registerUpdateAbility();
			}
		);
		self::$registered = true;
	}

	private function registerGetAbility(): void {
		wp_register_ability(
			'datamachine/get-pipeline-configuration',
			array(
				'label'               => __( 'Get Pipeline Configuration', 'data-machine' ),
				'description'         => __( 'Resolve a pipeline by ID or exact name and return normalized pipeline and flow step configuration.', 'data-machine' ),
				'category'            => 'datamachine-pipeline',
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'pipeline_id'   => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'pipeline_name' => array(
							'type'      => 'string',
							'minLength' => 1,
						),
					),
				),
				'output_schema'       => $this->outputSchema(),
				'execute_callback'    => array( $this, 'executeGet' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerUpdateAbility(): void {
		wp_register_ability(
			'datamachine/update-step-configuration',
			array(
				'label'               => __( 'Update Step Configuration', 'data-machine' ),
				'description'         => __( 'Atomically update supported pipeline or flow step configuration using an expected revision.', 'data-machine' ),
				'category'            => 'datamachine-pipeline',
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'target', 'expected_revision', 'configuration' ),
					'properties'           => array(
						'target'            => array(
							'type' => 'string',
							'enum' => array( 'pipeline', 'flow' ),
						),
						'pipeline_id'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'pipeline_name'     => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'flow_id'           => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'step_id'           => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'step_type'         => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'expected_revision' => array(
							'type'    => 'string',
							'pattern' => '^sha256:[a-f0-9]{64}$',
						),
						'configuration'     => array( 'type' => 'object' ),
					),
				),
				'output_schema'       => $this->outputSchema(),
				'execute_callback'    => array( $this, 'executeUpdate' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function outputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success'        => array( 'type' => 'boolean' ),
				'schema_version' => array( 'type' => 'string' ),
				'pipeline'       => array( 'type' => 'object' ),
				'flows'          => array( 'type' => 'array' ),
				'target'         => array( 'type' => 'string' ),
				'step_id'        => array( 'type' => 'string' ),
				'revision'       => array( 'type' => 'string' ),
				'error'          => array( 'type' => 'string' ),
				'error_code'     => array( 'type' => 'string' ),
				'status'         => array( 'type' => 'integer' ),
			),
		);
	}

	public function checkPermission(): bool {
		return PermissionHelper::can( 'manage_flows' );
	}

	public function executeGet( array $input ): array|\WP_Error {
		$resolved = $this->resolvePipeline( $input );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$pipeline = $resolved['pipeline'];
		$snapshot = $this->pipelineSnapshot( (int) $pipeline['pipeline_id'] );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$flows = array();
		foreach ( $this->flows->get_flows_for_pipeline( (int) $pipeline['pipeline_id'] ) as $flow ) {
			$flow_snapshot = $this->flowSnapshot( (int) $flow['flow_id'] );
			if ( is_wp_error( $flow_snapshot ) ) {
				return $flow_snapshot;
			}
			$flows[] = array(
				'flow_id'   => (int) $flow['flow_id'],
				'flow_name' => (string) $flow['flow_name'],
				'revision'  => $flow_snapshot['revision'],
				'steps'     => $this->normalizeSteps( $flow_snapshot['config'], 'flow_step_id' ),
			);
		}

		return array(
			'success'        => true,
			'schema_version' => self::SCHEMA_VERSION,
			'pipeline'       => array(
				'pipeline_id'   => (int) $pipeline['pipeline_id'],
				'pipeline_name' => (string) $pipeline['pipeline_name'],
				'revision'      => $snapshot['revision'],
				'steps'         => $this->normalizeSteps( $snapshot['config'], 'pipeline_step_id' ),
			),
			'flows'          => $flows,
		);
	}

	public function executeUpdate( array $input ): array|\WP_Error {
		$unknown = array_diff( array_keys( $input ), array( 'target', 'pipeline_id', 'pipeline_name', 'flow_id', 'step_id', 'step_type', 'expected_revision', 'configuration' ) );
		if ( ! empty( $unknown ) ) {
			return $this->error( 'unknown_field', 'Unknown input fields: ' . implode( ', ', $unknown ), 400 );
		}

		$target   = $input['target'] ?? '';
		$expected = $input['expected_revision'] ?? '';
		$config   = $input['configuration'] ?? null;
		if ( ! in_array( $target, array( 'pipeline', 'flow' ), true ) || ! is_string( $expected ) || ! is_array( $config ) ) {
			return $this->error( 'invalid_request', 'target, expected_revision, and configuration are required', 400 );
		}

		return 'pipeline' === $target
			? $this->updatePipelineStep( $input, $expected, $config )
			: $this->updateFlowStep( $input, $expected, $config );
	}

	private function updatePipelineStep( array $input, string $expected, array $patch ): array|\WP_Error {
		$resolved = $this->resolvePipeline( $input );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$pipeline_id = (int) $resolved['pipeline']['pipeline_id'];
		$snapshot    = $this->pipelineSnapshot( $pipeline_id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( ! hash_equals( $snapshot['revision'], $expected ) ) {
			return $this->error( 'configuration_conflict', 'Pipeline configuration revision is stale', 409 );
		}

		$allowed = array( 'system_prompt', 'agent_modes', 'disabled_tools', 'tool_categories' );
		$unknown = array_diff( array_keys( $patch ), $allowed );
		if ( ! empty( $unknown ) ) {
			return $this->error( 'unknown_field', 'Unknown pipeline step configuration fields: ' . implode( ', ', $unknown ), 400 );
		}
		if ( empty( $patch ) ) {
			return $this->error( 'invalid_request', 'configuration must contain at least one supported field', 400 );
		}

		$step_id = $this->resolveStepId( $snapshot['config'], $input, 'pipeline_step_id' );
		if ( is_wp_error( $step_id ) ) {
			return $step_id;
		}
		$step = $snapshot['config'][ $step_id ];

		if ( array_key_exists( 'system_prompt', $patch ) ) {
			if ( 'ai' !== ( $step['step_type'] ?? '' ) || ! is_string( $patch['system_prompt'] ) ) {
				return $this->error( 'invalid_configuration', 'system_prompt is only supported as a string on AI steps', 400 );
			}
			$step['system_prompt'] = wp_unslash( $patch['system_prompt'] );
		}

		foreach ( array( 'agent_modes', 'disabled_tools', 'tool_categories' ) as $field ) {
			if ( ! array_key_exists( $field, $patch ) ) {
				continue;
			}
			$value = PipelineStepAbilities::sanitizeStringListField( $patch[ $field ], $field );
			if ( is_wp_error( $value ) ) {
				return $this->error( 'invalid_configuration', $value->get_error_message(), 400 );
			}
			if ( 'disabled_tools' === $field ) {
				$value = ( new \DataMachine\Engine\AI\Tools\ToolManager() )->save_step_tool_selections( $step_id, $value );
			}
			$step[ $field ] = $value;
		}

		$snapshot['config'][ $step_id ] = $step;
		if ( ! $this->pipelines->compare_and_swap_pipeline_config( $pipeline_id, $snapshot['raw'], $snapshot['config'] ) ) {
			return $this->error( 'configuration_conflict', 'Pipeline configuration changed before the update could be saved', 409 );
		}

		return $this->updated( 'pipeline', $step_id, $this->pipelines->get_pipeline_config_json( $pipeline_id ) );
	}

	private function updateFlowStep( array $input, string $expected, array $patch ): array|\WP_Error {
		$flow_id = isset( $input['flow_id'] ) ? (int) $input['flow_id'] : 0;
		$flow    = $flow_id > 0 ? $this->flows->get_flow( $flow_id ) : null;
		if ( ! $flow ) {
			return $this->error( 'flow_not_found', 'Flow not found', 404 );
		}

		$snapshot = $this->flowSnapshot( $flow_id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( ! hash_equals( $snapshot['revision'], $expected ) ) {
			return $this->error( 'configuration_conflict', 'Flow configuration revision is stale', 409 );
		}

		$allowed = array( 'handler_slug', 'handler_config', 'handler_configs', 'flow_step_settings', 'user_message', 'enabled_tools', 'disabled_tools' );
		$unknown = array_diff( array_keys( $patch ), $allowed );
		if ( ! empty( $unknown ) ) {
			return $this->error( 'unknown_field', 'Unknown flow step configuration fields: ' . implode( ', ', $unknown ), 400 );
		}
		if ( empty( $patch ) ) {
			return $this->error( 'invalid_request', 'configuration must contain at least one supported field', 400 );
		}

		$step_id = $this->resolveStepId( $snapshot['config'], $input, 'flow_step_id' );
		if ( is_wp_error( $step_id ) ) {
			return $step_id;
		}
		$step = $snapshot['config'][ $step_id ];

		foreach ( array( 'enabled_tools', 'disabled_tools' ) as $field ) {
			if ( ! array_key_exists( $field, $patch ) ) {
				continue;
			}
			$value = PipelineStepAbilities::sanitizeStringListField( $patch[ $field ], $field );
			if ( is_wp_error( $value ) ) {
				return $this->error( 'invalid_configuration', $value->get_error_message(), 400 );
			}
			$step[ $field ] = $value;
		}

		if ( array_key_exists( 'user_message', $patch ) ) {
			if ( ! is_string( $patch['user_message'] ) || 'ai' !== ( $step['step_type'] ?? '' ) ) {
				return $this->error( 'invalid_configuration', 'user_message is only supported as a string on AI steps', 400 );
			}
			$message              = wp_unslash( sanitize_textarea_field( $patch['user_message'] ) );
			$step['prompt_queue'] = '' === trim( $message ) ? array() : array(
				array(
					'prompt'   => $message,
					'added_at' => gmdate( 'c' ),
				),
			);
			$step['queue_mode']   = 'static';
		}

		$handler_result = $this->applyHandlerPatch( $step, $patch );
		if ( is_wp_error( $handler_result ) ) {
			return $handler_result;
		}
		$step = $handler_result['step'];

		$snapshot['config'][ $step_id ] = $step;
		if ( ! $this->flows->compare_and_swap_flow_config( $flow_id, $snapshot['raw'], $snapshot['config'] ) ) {
			return $this->error( 'configuration_conflict', 'Flow configuration changed or was rejected before the update could be saved', 409 );
		}

		return $this->updated( 'flow', $step_id, $this->flows->get_flow_config_json( $flow_id ) );
	}

	private function applyHandlerPatch( array $step, array $patch ): array|\WP_Error {
		$has_handler_fields = array_intersect( array_keys( $patch ), array( 'handler_slug', 'handler_config', 'handler_configs', 'flow_step_settings' ) );
		if ( empty( $has_handler_fields ) ) {
			return array( 'step' => $step );
		}

		$uses_handler = FlowStepConfig::usesHandler( $step );
		if ( $uses_handler && array_key_exists( 'flow_step_settings', $patch ) ) {
			return $this->error( 'invalid_configuration', 'flow_step_settings is only supported on handler-free steps', 400 );
		}
		if ( ! $uses_handler && array_intersect( array_keys( $patch ), array( 'handler_slug', 'handler_config', 'handler_configs' ) ) ) {
			return $this->error( 'invalid_configuration', 'handler fields are only supported on handler-backed steps', 400 );
		}

		if ( ! $uses_handler ) {
			if ( ! is_array( $patch['flow_step_settings'] ?? null ) ) {
				return $this->error( 'invalid_configuration', 'flow_step_settings must be an object', 400 );
			}
			$slug       = (string) ( $step['step_type'] ?? '' );
			$validation = $this->validateHandlerConfig( $slug, $patch['flow_step_settings'] );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
			$existing                   = is_array( $step['flow_step_settings'] ?? null ) ? $step['flow_step_settings'] : array();
			$step['flow_step_settings'] = $this->handlers->applyDefaults( $slug, $this->deepMerge( $existing, $this->sanitizeHandlerConfig( $slug, $patch['flow_step_settings'] ) ) );
			return array( 'step' => $step );
		}

		$configs = is_array( $patch['handler_configs'] ?? null ) ? $patch['handler_configs'] : array();
		$slug    = is_string( $patch['handler_slug'] ?? null ) ? sanitize_key( $patch['handler_slug'] ) : '';
		if ( isset( $patch['handler_config'] ) ) {
			if ( ! is_array( $patch['handler_config'] ) ) {
				return $this->error( 'invalid_configuration', 'handler_config must be an object', 400 );
			}
			$slug = '' !== $slug ? $slug : ( FlowStepConfig::getPrimaryHandlerSlug( $step ) ?? '' );
			if ( '' === $slug ) {
				return $this->error( 'invalid_configuration', 'handler_slug is required when no handler is configured', 400 );
			}
			$configs[ $slug ] = $patch['handler_config'];
		}
		if ( '' !== $slug && ! isset( $configs[ $slug ] ) ) {
			$configs[ $slug ] = array();
		}
		if ( empty( $configs ) ) {
			return $this->error( 'invalid_configuration', 'handler configuration must identify at least one handler', 400 );
		}

		$stored_configs = FlowStepConfig::getHandlerConfigs( $step );
		$stored_slugs   = FlowStepConfig::getHandlerSlugs( $step );
		foreach ( $configs as $handler_slug => $handler_config ) {
			if ( ! is_string( $handler_slug ) || ! is_array( $handler_config ) || ! $this->handlers->handlerExists( $handler_slug ) ) {
				return $this->error( 'invalid_configuration', "Handler '{$handler_slug}' is unavailable or has invalid configuration", 400 );
			}
			$validation = $this->validateHandlerConfig( $handler_slug, $handler_config );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
			$existing                        = is_array( $stored_configs[ $handler_slug ] ?? null ) ? $stored_configs[ $handler_slug ] : array();
			$stored_configs[ $handler_slug ] = $this->handlers->applyDefaults( $handler_slug, $this->deepMerge( $existing, $this->sanitizeHandlerConfig( $handler_slug, $handler_config ) ) );
			if ( ! in_array( $handler_slug, $stored_slugs, true ) ) {
				$stored_slugs[] = $handler_slug;
			}
		}
		if ( '' !== $slug ) {
			$stored_slugs = array_values( array_unique( array_merge( array( $slug ), $stored_slugs ) ) );
		}
		$step['handler_slugs']   = $stored_slugs;
		$step['handler_configs'] = $stored_configs;
		$step['enabled']         = true;
		return array( 'step' => $step );
	}

	private function validateHandlerConfig( string $slug, array $config ): array|\WP_Error {
		$fields  = $this->handlers->getConfigFields( $slug );
		$unknown = array_diff( array_keys( $config ), array_keys( $fields ) );
		if ( ! empty( $fields ) && ! empty( $unknown ) ) {
			return $this->error( 'unknown_field', "Unknown configuration fields for {$slug}: " . implode( ', ', $unknown ), 400 );
		}
		return array();
	}

	private function sanitizeHandlerConfig( string $slug, array $config ): array {
		$class = $this->handlers->getSettingsClass( $slug );
		return $class && method_exists( $class, 'sanitize' ) ? $class->sanitize( $config ) : $config;
	}

	private function resolvePipeline( array $input ): array|\WP_Error {
		$has_id   = isset( $input['pipeline_id'] );
		$has_name = isset( $input['pipeline_name'] ) && '' !== trim( (string) $input['pipeline_name'] );
		if ( $has_id === $has_name ) {
			return $this->error( 'invalid_selector', 'Provide exactly one of pipeline_id or pipeline_name', 400 );
		}

		if ( $has_id ) {
			$pipeline = $this->pipelines->get_pipeline( (int) $input['pipeline_id'] );
			return $pipeline ? array( 'pipeline' => $pipeline ) : $this->error( 'pipeline_not_found', 'Pipeline not found', 404 );
		}

		$matches = $this->pipelines->get_pipelines_by_name( sanitize_text_field( wp_unslash( $input['pipeline_name'] ) ) );
		if ( 0 === count( $matches ) ) {
			return $this->error( 'pipeline_not_found', 'Pipeline not found', 404 );
		}
		if ( count( $matches ) > 1 ) {
			return $this->error( 'pipeline_name_conflict', 'Pipeline name is not unique; resolve by ID', 409 );
		}
		return array( 'pipeline' => $matches[0] );
	}

	private function pipelineSnapshot( int $pipeline_id ): array|\WP_Error {
		$raw = $this->pipelines->get_pipeline_config_json( $pipeline_id );
		return $this->snapshot( $raw, 'pipeline_configuration_unavailable', 'Pipeline configuration is unavailable' );
	}

	private function flowSnapshot( int $flow_id ): array|\WP_Error {
		$raw = $this->flows->get_flow_config_json( $flow_id );
		return $this->snapshot( $raw, 'flow_configuration_unavailable', 'Flow configuration is unavailable' );
	}

	private function snapshot( ?string $raw, string $code, string $message ): array|\WP_Error {
		if ( null === $raw ) {
			return $this->error( $code, $message, 503 );
		}
		$config = json_decode( $raw, true );
		if ( ! is_array( $config ) ) {
			return $this->error( $code, $message, 503 );
		}
		return array(
			'raw'      => $raw,
			'config'   => $config,
			'revision' => $this->revision( $raw ),
		);
	}

	private function normalizeSteps( array $config, string $id_field ): array {
		$steps = array();
		foreach ( $config as $step_id => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$step[ $id_field ] = (string) $step_id;
			$steps[]           = FlowStepConfig::normalizeHandlerShape( $step );
		}
		usort( $steps, fn( array $a, array $b ): int => ( $a['execution_order'] ?? 0 ) <=> ( $b['execution_order'] ?? 0 ) );
		return $steps;
	}

	private function resolveStepId( array $config, array $input, string $id_field ): string|\WP_Error {
		$step_id   = isset( $input['step_id'] ) ? (string) $input['step_id'] : '';
		$step_type = isset( $input['step_type'] ) ? (string) $input['step_type'] : '';
		if ( ( '' === $step_id ) === ( '' === $step_type ) ) {
			return $this->error( 'invalid_selector', 'Provide exactly one of step_id or step_type', 400 );
		}
		if ( '' !== $step_id ) {
			return isset( $config[ $step_id ] ) ? $step_id : $this->error( 'step_not_found', 'Step not found', 404 );
		}

		$matches = array();
		foreach ( $config as $key => $step ) {
			if ( is_array( $step ) && ( $step['step_type'] ?? '' ) === $step_type ) {
				$matches[] = (string) $key;
			}
		}
		if ( 0 === count( $matches ) ) {
			return $this->error( 'step_not_found', 'Step not found', 404 );
		}
		if ( count( $matches ) > 1 ) {
			return $this->error( 'step_type_conflict', "Multiple {$step_type} steps exist; resolve by {$id_field}", 409 );
		}
		return $matches[0];
	}

	private function updated( string $target, string $step_id, ?string $raw ): array|\WP_Error {
		if ( null === $raw ) {
			return $this->error( $target . '_configuration_unavailable', ucfirst( $target ) . ' configuration is unavailable', 503 );
		}
		$revision = $this->revision( $raw );
		do_action( 'datamachine_step_configuration_updated', $target, $step_id, $revision );
		do_action( 'datamachine_log', 'info', 'Step configuration updated via owner contract', compact( 'target', 'step_id', 'revision' ) );
		return array(
			'success'        => true,
			'schema_version' => self::SCHEMA_VERSION,
			'target'         => $target,
			'step_id'        => $step_id,
			'revision'       => $revision,
		);
	}

	private function revision( string $raw ): string {
		return 'sha256:' . hash( 'sha256', $raw );
	}

	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	private function deepMerge( array $existing, array $patch ): array {
		foreach ( $patch as $key => $value ) {
			if ( is_string( $key ) && isset( $existing[ $key ] ) && is_array( $existing[ $key ] ) && is_array( $value ) && ! array_is_list( $existing[ $key ] ) && ! array_is_list( $value ) ) {
				$existing[ $key ] = $this->deepMerge( $existing[ $key ], $value );
				continue;
			}
			$existing[ $key ] = $value;
		}
		return $existing;
	}
}
