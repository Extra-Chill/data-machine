<?php
/**
 * Flow step config factory.
 *
 * @package DataMachine\Core\Steps
 */

namespace DataMachine\Core\Steps;

use DataMachine\Abilities\Flow\QueueAbility;

defined( 'ABSPATH' ) || exit;

/**
 * Builds canonical flow step configuration rows from explicit inputs.
 */
class FlowStepConfigFactory {

	/**
	 * Build a canonical flow step config row from an ephemeral workflow step.
	 *
	 * @param array $step  Workflow step input.
	 * @param int   $index Zero-based workflow step index.
	 * @return array Flow step config row.
	 */
	public static function buildFromWorkflowStep( array $step, int $index ): array {
		$step_type = self::getWorkflowStepType( $step );
		$config    = self::build(
			array_merge(
				array(
					'flow_step_id'     => "ephemeral_step_{$index}",
					'pipeline_step_id' => "ephemeral_pipeline_{$index}",
					'step_type'        => $step_type,
					'execution_order'  => $index,
					'enabled_tools'    => ( 'ai' === $step_type && ! empty( $step['enabled_tools'] ) && is_array( $step['enabled_tools'] ) )
						? array_values( $step['enabled_tools'] )
						: array(),
				),
				self::promptQueueFromWorkflowStep( $step ),
				array(
					'queue_mode'         => 'static',
					'agent_modes'        => self::sanitizeAgentModes( $step['agent_modes'] ?? array() ),
					'disabled_tools'     => $step['disabled_tools'] ?? array(),
					'tool_recorders'     => is_array( $step['tool_recorders'] ?? null ) ? array_values( $step['tool_recorders'] ) : array(),
					'pipeline_id'        => 'direct',
					'flow_id'            => 'direct',
					'handler_slugs'      => $step['handler_slugs'] ?? array(),
					'handler_configs'    => $step['handler_configs'] ?? array(),
					'flow_step_settings' => $step['flow_step_settings'] ?? array(),
				)
			)
		);

		$config = self::withQueueState( $config, $step );

		$workflow_user_message = is_string( $step['user_message'] ?? null )
			? trim( $step['user_message'] )
			: '';
		if ( 'ai' === $step_type && '' !== $workflow_user_message ) {
			$config = self::withUserMessage( $config, $workflow_user_message );
		}

		return $config;
	}

	/**
	 * Build a canonical flow step config row from a pipeline step for a flow.
	 *
	 * @param array      $step                 Pipeline step row.
	 * @param int|string $pipeline_id          Pipeline ID.
	 * @param int|string $flow_id              Flow ID.
	 * @param string     $flow_step_id         Generated flow step ID.
	 * @param array      $pipeline_step_config Pipeline step config keyed by pipeline step ID.
	 * @return array Flow step config row.
	 */
	public static function buildFromPipelineStep(
		array $step,
		$pipeline_id,
		$flow_id,
		string $flow_step_id,
		array $pipeline_step_config = array()
	): array {
		$step_type        = $step['step_type'] ?? '';
		$pipeline_step_id = $step['pipeline_step_id'] ?? '';

		return self::build(
			array_merge(
				array(
					'flow_step_id'     => $flow_step_id,
					'step_type'        => $step_type,
					'pipeline_step_id' => $pipeline_step_id,
					'pipeline_id'      => $pipeline_id,
					'flow_id'          => $flow_id,
					'execution_order'  => $step['execution_order'] ?? 0,
					'agent_modes'      => self::sanitizeAgentModes( $pipeline_step_config['agent_modes'] ?? array() ),
					'disabled_tools'   => $pipeline_step_config['disabled_tools'] ?? array(),
				),
				self::queueDefaultsForStepType( $step_type )
			)
		);
	}

	/**
	 * Build a canonical flow step configuration row.
	 *
	 * @param array $args Explicit config inputs.
	 * @return array Flow step config row.
	 */
	public static function build( array $args ): array {
		$step_config = array();
		$copy_fields = array(
			'flow_step_id'                        => true,
			'pipeline_step_id'                    => true,
			'step_type'                           => true,
			'execution_order'                     => true,
			'enabled_tools'                       => true,
			'agent_modes'                         => true,
			'disabled_tools'                      => true,
			'completion_assertions'               => true,
			'tool_runtime_rules'                  => true,
			'tool_recorders'                      => true,
			QueueAbility::SLOT_PROMPT_QUEUE       => true,
			QueueAbility::SLOT_CONFIG_PATCH_QUEUE => true,
			'queue_mode'                          => true,
			'pipeline_id'                         => true,
			'flow_id'                             => true,
		);

		foreach ( $args as $field => $value ) {
			if ( isset( $copy_fields[ $field ] ) ) {
				$step_config[ $field ] = $value;
			}
		}

		$flow_step_settings = is_array( $args['flow_step_settings'] ?? null ) ? $args['flow_step_settings'] : array();
		$handler_slugs      = is_array( $args['handler_slugs'] ?? null ) ? $args['handler_slugs'] : array();
		$handler_configs    = is_array( $args['handler_configs'] ?? null ) ? $args['handler_configs'] : array();

		if ( FlowStepConfig::usesHandler( $step_config ) ) {
			$step_config = FlowStepConfig::normalizeHandlerShape(
				array_merge(
					$step_config,
					array(
						'handler_slugs'   => $handler_slugs,
						'handler_configs' => $handler_configs,
					)
				)
			);
		} elseif ( ! empty( $flow_step_settings ) ) {
			$step_config['flow_step_settings'] = $flow_step_settings;
		}

		return $step_config;
	}

	/**
	 * Return queue defaults for a step type.
	 *
	 * @param string $step_type Step type slug.
	 * @return array Queue fields.
	 */
	private static function queueDefaultsForStepType( string $step_type ): array {
		$defaults = array( 'queue_mode' => 'static' );

		if ( 'fetch' === $step_type ) {
			$defaults[ QueueAbility::SLOT_CONFIG_PATCH_QUEUE ] = array();
		} else {
			$defaults[ QueueAbility::SLOT_PROMPT_QUEUE ] = array();
		}

		return $defaults;
	}

	/**
	 * Convert workflow user_message input into AIStep's prompt queue slot.
	 *
	 * @param array $step Workflow step input.
	 * @return array Prompt queue override or empty array.
	 */
	private static function promptQueueFromWorkflowStep( array $step ): array {
		$workflow_user_message = is_string( $step['user_message'] ?? null )
			? trim( $step['user_message'] )
			: '';

		if ( 'ai' !== self::getWorkflowStepType( $step ) || '' === $workflow_user_message ) {
			return array( QueueAbility::SLOT_PROMPT_QUEUE => array() );
		}

		return array(
			QueueAbility::SLOT_PROMPT_QUEUE => array(
				array(
					'prompt'   => $workflow_user_message,
					'added_at' => gmdate( 'c' ),
				),
			),
		);
	}

	/**
	 * Overlay canonical handler fields onto a step config.
	 *
	 * @param array $step_config Step configuration array.
	 * @param array $handler_fields Source fields containing handler shape data.
	 * @return array Step config with canonical handler shape.
	 */
	public static function withHandlerFields( array $step_config, array $handler_fields ): array {
		$overlay = array();
		foreach ( array( 'handler_slugs', 'handler_configs', 'flow_step_settings' ) as $field ) {
			if ( array_key_exists( $field, $handler_fields ) ) {
				$overlay[ $field ] = $handler_fields[ $field ];
			}
		}

		if ( empty( $overlay ) ) {
			return $step_config;
		}

		return FlowStepConfig::normalizeHandlerShape( array_merge( $step_config, $overlay ) );
	}

	/**
	 * Set or merge the primary handler/settings config for a step.
	 *
	 * @param array  $step_config Step configuration array.
	 * @param string $handler_slug Explicit handler slug. Empty preserves the current effective slug.
	 * @param array  $handler_config Handler/settings config to store.
	 * @param bool   $merge Whether to merge into the existing primary config.
	 * @return array Step config with canonical handler shape.
	 */
	public static function withHandlerConfig( array $step_config, string $handler_slug = '', array $handler_config = array(), bool $merge = false ): array {
		$uses_handler   = FlowStepConfig::usesHandler( $step_config );
		$effective_slug = FlowStepConfig::getEffectiveSlug( $step_config, $handler_slug );

		if ( ! $uses_handler ) {
			$existing_config = $merge ? FlowStepConfig::getPrimaryHandlerConfig( $step_config ) : array();
			return self::withHandlerFields(
				$step_config,
				array( 'flow_step_settings' => array_merge( $existing_config, $handler_config ) )
			);
		}

		if ( '' === $effective_slug ) {
			return $step_config;
		}

		$existing_config = $merge ? FlowStepConfig::getHandlerConfigForSlug( $step_config, $effective_slug ) : array();
		$stored_config   = array_merge( $existing_config, $handler_config );

		$slugs = FlowStepConfig::getHandlerSlugs( $step_config );
		if ( ! in_array( $effective_slug, $slugs, true ) ) {
			$slugs[] = $effective_slug;
		}

		$configs                    = FlowStepConfig::getHandlerConfigs( $step_config );
		$configs[ $effective_slug ] = $stored_config;

		return self::withHandlerFields(
			$step_config,
			array(
				'handler_slugs'   => $slugs,
				'handler_configs' => $configs,
			)
		);
	}

	/**
	 * Copy queue state fields from a source step config.
	 *
	 * @param array $step_config Step configuration array.
	 * @param array $source_step Source step configuration array.
	 * @return array Step config with copied queue state.
	 */
	public static function withQueueState( array $step_config, array $source_step ): array {
		if ( isset( $source_step[ QueueAbility::SLOT_PROMPT_QUEUE ] ) && is_array( $source_step[ QueueAbility::SLOT_PROMPT_QUEUE ] ) ) {
			$step_config[ QueueAbility::SLOT_PROMPT_QUEUE ] = $source_step[ QueueAbility::SLOT_PROMPT_QUEUE ];
		}
		if ( isset( $source_step[ QueueAbility::SLOT_CONFIG_PATCH_QUEUE ] ) && is_array( $source_step[ QueueAbility::SLOT_CONFIG_PATCH_QUEUE ] ) ) {
			$step_config[ QueueAbility::SLOT_CONFIG_PATCH_QUEUE ] = $source_step[ QueueAbility::SLOT_CONFIG_PATCH_QUEUE ];
		}
		if ( isset( $source_step['queue_mode'] )
			&& in_array( $source_step['queue_mode'], array( 'drain', 'loop', 'static' ), true )
		) {
			$step_config['queue_mode'] = $source_step['queue_mode'];
		}

		return $step_config;
	}

	/**
	 * Store a public user_message input as a one-entry static prompt queue.
	 *
	 * @param array       $step_config Step configuration array.
	 * @param string      $user_message User message text.
	 * @param string|null $added_at Optional timestamp for tests/importers.
	 * @return array Step config with prompt queue state.
	 */
	public static function withUserMessage( array $step_config, string $user_message, ?string $added_at = null ): array {
		$step_config[ QueueAbility::SLOT_PROMPT_QUEUE ] = array(
			array(
				'prompt'   => $user_message,
				'added_at' => $added_at ?? gmdate( 'c' ),
			),
		);
		$step_config['queue_mode']                      = 'static';

		return $step_config;
	}

	/**
	 * Sanitize agent mode slugs without requiring full WordPress bootstrap.
	 *
	 * @param mixed $modes Raw modes.
	 * @return array<int,string> Sanitized modes.
	 */
	private static function sanitizeAgentModes( mixed $modes ): array {
		if ( ! is_array( $modes ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $modes as $mode ) {
			if ( ! is_scalar( $mode ) ) {
				continue;
			}
			$mode = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $mode ) : strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $mode ) ?? '' );
			if ( '' !== $mode ) {
				$sanitized[] = $mode;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Resolve the workflow step type from the canonical field.
	 *
	 * @param array $step Workflow step input.
	 * @return string Step type slug.
	 */
	private static function getWorkflowStepType( array $step ): string {
		$step_type = $step['step_type'] ?? '';
		return is_string( $step_type ) ? $step_type : '';
	}
}
