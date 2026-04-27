<?php
/**
 * Flow step target resolver.
 *
 * @package DataMachine\Core\Steps
 */

namespace DataMachine\Core\Steps;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a flow-step config target from explicit identifiers or step_type shorthand.
 */
class FlowStepTargetResolver {

	/**
	 * Resolve a config entry to one flow step.
	 *
	 * @param array  $flow_config Flow config keyed by flow_step_id.
	 * @param string $config_key Step config key, historically the step_type shorthand.
	 * @param array  $config Step config payload.
	 * @return array{success: bool, flow_step_id?: string, step_type?: string, error?: array}
	 */
	public static function resolve( array $flow_config, string $config_key, array $config ): array {
		$explicit_fields = array( 'flow_step_id', 'pipeline_step_id', 'execution_order' );

		foreach ( $explicit_fields as $field ) {
			if ( array_key_exists( $field, $config ) && null !== $config[ $field ] && '' !== $config[ $field ] ) {
				return self::resolveExplicitField( $flow_config, $field, $config[ $field ], $config_key );
			}
		}

		$step_type = self::resolveStepType( $config_key, $config );
		$matches   = self::findMatches( $flow_config, 'step_type', $step_type );

		if ( 1 === count( $matches ) ) {
			return array(
				'success'      => true,
				'flow_step_id' => $matches[0]['flow_step_id'],
				'step_type'    => $matches[0]['step_type'] ?? $step_type,
			);
		}

		if ( count( $matches ) > 1 ) {
			return array(
				'success' => false,
				'error'   => self::ambiguityError( 'step_type', $step_type, $matches ),
			);
		}

		return array(
			'success' => false,
			'error'   => array(
				'step_type' => $step_type,
				'error'     => "No step of type '{$step_type}' found in flow",
			),
		);
	}

	/**
	 * Resolve a config entry using an explicit target field.
	 *
	 * @param array  $flow_config Flow config keyed by flow_step_id.
	 * @param string $field Explicit target field.
	 * @param mixed  $value Explicit target value.
	 * @param string $config_key Original config key.
	 * @return array{success: bool, flow_step_id?: string, step_type?: string, error?: array}
	 */
	private static function resolveExplicitField( array $flow_config, string $field, $value, string $config_key ): array {
		$matches = self::findMatches( $flow_config, $field, $value );

		if ( 1 === count( $matches ) ) {
			return array(
				'success'      => true,
				'flow_step_id' => $matches[0]['flow_step_id'],
				'step_type'    => $matches[0]['step_type'] ?? $config_key,
			);
		}

		if ( count( $matches ) > 1 ) {
			return array(
				'success' => false,
				'error'   => self::ambiguityError( $field, $value, $matches ),
			);
		}

		return array(
			'success' => false,
			'error'   => array(
				$field   => $value,
				'error'  => "No step found for {$field} '{$value}'",
			),
		);
	}

	/**
	 * Find matching flow steps by field.
	 *
	 * @param array  $flow_config Flow config keyed by flow_step_id.
	 * @param string $field Field to match.
	 * @param mixed  $value Value to match.
	 * @return array<int, array<string, mixed>>
	 */
	private static function findMatches( array $flow_config, string $field, $value ): array {
		$matches = array();

		foreach ( $flow_config as $flow_step_id => $step_data ) {
			$actual = 'flow_step_id' === $field ? $flow_step_id : ( $step_data[ $field ] ?? null );
			if ( 'execution_order' === $field ) {
				$is_match = is_numeric( $actual ) && is_numeric( $value ) && (int) $actual === (int) $value;
			} else {
				$is_match = (string) $actual === (string) $value;
			}

			if ( ! $is_match ) {
				continue;
			}

			$matches[] = self::candidate( (string) $flow_step_id, $step_data );
		}

		usort(
			$matches,
			static function ( array $a, array $b ): int {
				return ( (int) ( $a['execution_order'] ?? 0 ) ) <=> ( (int) ( $b['execution_order'] ?? 0 ) );
			}
		);

		return $matches;
	}

	/**
	 * Build an ambiguity error payload.
	 *
	 * @param string $field Ambiguous target field.
	 * @param mixed  $value Ambiguous target value.
	 * @param array  $matches Matching candidates.
	 * @return array<string, mixed>
	 */
	private static function ambiguityError( string $field, $value, array $matches ): array {
		return array(
			'error_type' => 'ambiguous_step_target',
			$field       => $value,
			'error'      => "Multiple flow steps match {$field} '{$value}'. Use flow_step_id, pipeline_step_id, or execution_order to target one step explicitly.",
			'candidates' => array_map(
				static function ( array $candidate ): array {
					return array(
						'flow_step_id'     => $candidate['flow_step_id'],
						'pipeline_step_id' => $candidate['pipeline_step_id'] ?? null,
						'execution_order'  => $candidate['execution_order'] ?? null,
					);
				},
				$matches
			),
		);
	}

	/**
	 * Build a candidate payload.
	 *
	 * @param string $flow_step_id Flow step ID.
	 * @param array  $step_data Step config.
	 * @return array<string, mixed>
	 */
	private static function candidate( string $flow_step_id, array $step_data ): array {
		return array(
			'flow_step_id'     => $flow_step_id,
			'pipeline_step_id' => $step_data['pipeline_step_id'] ?? null,
			'execution_order'  => $step_data['execution_order'] ?? null,
			'step_type'        => $step_data['step_type'] ?? null,
		);
	}

	/**
	 * Resolve the step_type shorthand for a config entry.
	 *
	 * @param string $config_key Config array key.
	 * @param array  $config Config payload.
	 * @return string
	 */
	private static function resolveStepType( string $config_key, array $config ): string {
		if ( ! empty( $config['step_type'] ) && is_string( $config['step_type'] ) ) {
			return $config['step_type'];
		}

		return $config_key;
	}
}
