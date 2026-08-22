<?php
/**
 * Runtime tool policy projection.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Projects gathered tool declarations into a generic runtime-local policy.
 */
class WP_Agent_Runtime_Tool_Policy {

	public const SCHEMA  = 'agents-api/runtime-tool-policy/v1';
	public const VERSION = 1;

	/**
	 * Build a runtime-local tool policy from gathered tool declarations.
	 *
	 * Storage, UI, approval, and apply behavior remain host responsibilities; this
	 * projection only carries the generic execution/scope contract consumers need
	 * before invoking an ephemeral runtime.
	 *
	 * @param array<string,array<string,mixed>> $tools   Tool declarations keyed by tool id.
	 * @param array<string,mixed>               $context Runtime context.
	 * @return array<string,mixed> Runtime tool policy envelope.
	 */
	public static function fromTools( array $tools, array $context = array() ): array {
		$policy_tools = array();

		foreach ( $tools as $tool_id => $tool ) {
			$id = self::toolId( $tool_id, $tool );
			if ( '' === $id ) {
				continue;
			}

			$runtime          = self::runtimeMetadata( $tool );
			$environment      = $runtime[ WP_Agent_Tool_Declaration::RUNTIME_ENVIRONMENT ];
			$capability_scope = $runtime[ WP_Agent_Tool_Declaration::RUNTIME_CAPABILITY_SCOPE ];
			$policy_tool = array(
				'id'                   => $id,
				'runtime_tool_id'      => self::runtimeToolId( $id, $tool ),
				'allowed'              => self::isRuntimeLocal( $runtime ),
				'environment'          => $environment,
				'capability_scope'     => $capability_scope,
				'runtime'              => $runtime,
				'execution_location'   => self::legacyExecutionLocation( $runtime ),
				'transport_visibility' => self::legacyTransportVisibility( $runtime ),
				'legacy_fields'        => array( 'execution_location', 'transport_visibility' ),
			);
			if ( is_string( $tool['source'] ?? null ) && '' !== $tool['source'] ) {
				$policy_tool['source'] = $tool['source'];
			}

			$policy_tools[] = $policy_tool;
		}

		$policy = array(
			'schema'  => self::SCHEMA,
			'version' => self::VERSION,
			'tools'   => $policy_tools,
		);

		if ( ! empty( $context ) ) {
			$policy['context'] = self::scalarContext( $context );
		}

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'agents_api_runtime_tool_policy', $policy, $tools, $context );
			if ( is_array( $filtered ) ) {
				$policy = self::stringKeyedArray( $filtered );
			}
		}

		return $policy;
	}

	/**
	 * @param array<mixed> $value Filtered policy array.
	 * @return array<string,mixed>
	 */
	private static function stringKeyedArray( array $value ): array {
		$normalized = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $item;
			}
		}

		return $normalized;
	}

	/** @param array<string,mixed> $tool */
	private static function toolId( string $tool_id, array $tool ): string {
		$id = is_string( $tool['name'] ?? null ) && '' !== trim( $tool['name'] ) ? (string) $tool['name'] : $tool_id;
		return trim( $id );
	}

	/** @param array<string,mixed> $tool */
	private static function runtimeToolId( string $tool_id, array $tool ): string {
		$runtime_tool_id = is_string( $tool['runtime_tool_id'] ?? null ) ? trim( $tool['runtime_tool_id'] ) : '';
		if ( '' !== $runtime_tool_id ) {
			return $runtime_tool_id;
		}

		return str_replace( array( '/', '-' ), '_', $tool_id );
	}

	/**
	 * @param array<string,mixed> $tool Tool declaration.
	 * @return array{environment:string, capability_scope:string}
	 */
	private static function runtimeMetadata( array $tool ): array {
		$runtime = is_array( $tool['runtime'] ?? null ) ? $tool['runtime'] : array();

		return array(
			WP_Agent_Tool_Declaration::RUNTIME_ENVIRONMENT => self::runtimeValue(
				$runtime[ WP_Agent_Tool_Declaration::RUNTIME_ENVIRONMENT ] ?? null,
				WP_Agent_Tool_Declaration::ENVIRONMENT_CONTROL_PLANE
			),
			WP_Agent_Tool_Declaration::RUNTIME_CAPABILITY_SCOPE => self::runtimeValue(
				$runtime[ WP_Agent_Tool_Declaration::RUNTIME_CAPABILITY_SCOPE ] ?? null,
				WP_Agent_Tool_Declaration::CAPABILITY_SCOPE_CONTROL_PLANE
			),
		);
	}

	private static function runtimeValue( mixed $value, string $fallback ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		return '' !== $value ? $value : $fallback;
	}

	/** @param array{environment:string,capability_scope:string} $runtime */
	private static function isRuntimeLocal( array $runtime ): bool {
		return WP_Agent_Tool_Declaration::ENVIRONMENT_RUNTIME_LOCAL === $runtime[ WP_Agent_Tool_Declaration::RUNTIME_ENVIRONMENT ]
			&& WP_Agent_Tool_Declaration::CAPABILITY_SCOPE_RUNTIME_LOCAL === $runtime[ WP_Agent_Tool_Declaration::RUNTIME_CAPABILITY_SCOPE ];
	}

	/** @param array{environment:string,capability_scope:string} $runtime */
	private static function legacyExecutionLocation( array $runtime ): string {
		return WP_Agent_Tool_Declaration::ENVIRONMENT_RUNTIME_LOCAL === $runtime[ WP_Agent_Tool_Declaration::RUNTIME_ENVIRONMENT ] ? 'sandbox' : 'parent';
	}

	/** @param array{environment:string,capability_scope:string} $runtime */
	private static function legacyTransportVisibility( array $runtime ): string {
		return WP_Agent_Tool_Declaration::CAPABILITY_SCOPE_RUNTIME_LOCAL === $runtime[ WP_Agent_Tool_Declaration::RUNTIME_CAPABILITY_SCOPE ] ? 'sandbox' : 'parent';
	}

	/**
	 * @param array<string,mixed> $context Runtime context.
	 * @return array<string,string|int|float|bool>
	 */
	private static function scalarContext( array $context ): array {
		$allowed = array();
		foreach ( $context as $key => $value ) {
			if ( '' === $key ) {
				continue;
			}
			if ( is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
				$allowed[ $key ] = $value;
			}
		}

		return $allowed;
	}
}
