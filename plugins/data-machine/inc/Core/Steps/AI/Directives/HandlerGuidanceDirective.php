<?php
/**
 * Handler Guidance Directive - Priority 55
 *
 * Injects AI guidance contributed by the source handler(s) that produced the
 * current AI step's input packets. Handlers declare guidance on registration
 * via `meta['ai_guidance']`, either as a string or as a callable receiving the
 * handler's flow-step config:
 *
 *     fn( array $handler_config ): string
 *
 * Guidance describes how to read the handler's item shape (e.g. how a post
 * relates to its context items, how to resolve relative dates), so it lives
 * with the handler instead of being copied into every flow's prompt.
 *
 * Ordering: pipeline system prompt (priority 50) → handler guidance (this
 * class, priority 55) → per-flow prompt (user message). A per-flow prompt
 * stays an optional per-flow delta.
 *
 * The producing handler is resolved from the nearest preceding flow step whose
 * step type declares the `source` handler category (core `fetch` plus any
 * extension-registered source step type). Guidance is emitted once per
 * handler per AI request, regardless of how many packets the handler emitted.
 *
 * @package DataMachine\Core\Steps\AI\Directives
 */

namespace DataMachine\Core\Steps\AI\Directives;

use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\FlowStepConfig;
use DataMachine\Core\Steps\StepTypeMetadata;
use DataMachine\Engine\ExecutionPlan;

defined( 'ABSPATH' ) || exit;

class HandlerGuidanceDirective implements \DataMachine\Engine\AI\Directives\DirectiveInterface {

	/**
	 * Handler meta key carrying AI guidance.
	 */
	public const META_KEY = 'ai_guidance';

	/**
	 * Directive priority (after pipeline system prompt at 50).
	 */
	public const PRIORITY = 55;

	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		$flow_step_id = (string) ( $payload['flow_step_id'] ?? '' );
		if ( '' === $flow_step_id ) {
			return array();
		}

		// No input packets means no producing handler to describe.
		if ( array_key_exists( 'data', $payload ) && is_array( $payload['data'] ) && empty( $payload['data'] ) ) {
			return array();
		}

		$flow_config = self::resolveFlowConfig( $payload );
		if ( empty( $flow_config ) ) {
			return array();
		}

		$source_step = self::findSourceStepConfig( $flow_config, $flow_step_id );
		if ( null === $source_step ) {
			return array();
		}

		$outputs = array();
		$seen    = array();
		foreach ( self::collectGuidance( $source_step ) as $entry ) {
			$hash = md5( $entry['guidance'] );
			if ( isset( $seen[ $hash ] ) ) {
				continue;
			}
			$seen[ $hash ] = true;

			$outputs[] = array(
				'type'    => 'system_text',
				'content' => sprintf( "SOURCE HANDLER GUIDANCE (%s):\n%s", $entry['label'], $entry['guidance'] ),
			);
		}

		return $outputs;
	}

	/**
	 * Find the nearest preceding source step config for a flow step.
	 *
	 * @param array  $flow_config  Flow config keyed by flow step ID.
	 * @param string $flow_step_id Current (AI) flow step ID.
	 * @return array|null Source step config, or null when none precedes the step.
	 */
	public static function findSourceStepConfig( array $flow_config, string $flow_step_id ): ?array {
		if ( ! isset( $flow_config[ $flow_step_id ] ) ) {
			return null;
		}

		try {
			$plan = ExecutionPlan::from_flow_config( $flow_config );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}

		$cursor = $plan->previous_step_id( $flow_step_id );
		while ( null !== $cursor ) {
			$step_config = is_array( $flow_config[ $cursor ] ?? null ) ? $flow_config[ $cursor ] : array();
			$step_type   = (string) ( $step_config['step_type'] ?? '' );

			if ( '' !== $step_type && StepTypeMetadata::hasHandlerCategory( $step_type, 'source' ) ) {
				return $step_config;
			}

			$cursor = $plan->previous_step_id( $cursor );
		}

		return null;
	}

	/**
	 * Collect guidance declared by the handlers configured on a source step.
	 *
	 * @param array $step_config Source flow step config.
	 * @return array<int, array{handler_slug: string, label: string, guidance: string}>
	 */
	public static function collectGuidance( array $step_config ): array {
		$step_type         = (string) ( $step_config['step_type'] ?? '' );
		$handler_abilities = new HandlerAbilities();
		$entries           = array();

		foreach ( FlowStepConfig::getConfiguredHandlerSlugs( $step_config ) as $slug ) {
			$handler = $handler_abilities->getHandler( $slug, '' !== $step_type ? $step_type : null );
			if ( ! is_array( $handler ) ) {
				continue;
			}

			$meta     = is_array( $handler['meta'] ?? null ) ? $handler['meta'] : array();
			$guidance = self::resolveGuidance(
				$meta[ self::META_KEY ] ?? null,
				FlowStepConfig::getHandlerConfigForSlug( $step_config, $slug ),
				$slug
			);

			if ( '' === $guidance ) {
				continue;
			}

			$entries[] = array(
				'handler_slug' => $slug,
				'label'        => (string) ( $handler['label'] ?? $slug ),
				'guidance'     => $guidance,
			);
		}

		return $entries;
	}

	/**
	 * Resolve a declared guidance value into a trimmed string.
	 *
	 * @param mixed  $declared       String or callable guidance declaration.
	 * @param array  $handler_config Handler config for the source step.
	 * @param string $handler_slug   Handler slug (for logging).
	 * @return string Guidance text, or empty string when none.
	 */
	private static function resolveGuidance( $declared, array $handler_config, string $handler_slug ): string {
		if ( is_string( $declared ) ) {
			return trim( $declared );
		}

		if ( ! is_callable( $declared ) ) {
			return '';
		}

		try {
			$result = call_user_func( $declared, $handler_config );
		} catch ( \Throwable $e ) {
			do_action(
				'datamachine_log',
				'warning',
				'Handler AI guidance callback failed',
				array(
					'handler_slug' => $handler_slug,
					'error'        => $e->getMessage(),
				)
			);
			return '';
		}

		return is_string( $result ) ? trim( $result ) : '';
	}

	/**
	 * Resolve the flow config for the current execution.
	 *
	 * @param array $payload Directive payload.
	 * @return array Flow config keyed by flow step ID.
	 */
	private static function resolveFlowConfig( array $payload ): array {
		$engine = $payload['engine'] ?? null;
		if ( $engine instanceof EngineData ) {
			$flow_config = $engine->getFlowConfig();
			if ( ! empty( $flow_config ) ) {
				return $flow_config;
			}
		}

		$job_id = (int) ( $payload['job_id'] ?? 0 );
		if ( $job_id <= 0 || ! function_exists( 'datamachine_get_engine_data' ) ) {
			return array();
		}

		$engine_data = datamachine_get_engine_data( $job_id );
		return is_array( $engine_data['flow_config'] ?? null ) ? $engine_data['flow_config'] : array();
	}
}

// Register with universal agent directive system (Priority 55 = handler guidance).
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'    => HandlerGuidanceDirective::class,
			'priority' => HandlerGuidanceDirective::PRIORITY,
			'modes'    => array( 'pipeline' ),
		);
		return $directives;
	}
);
