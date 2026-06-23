<?php
/**
 * Host-owned tool execution policy.
 *
 * @package DataMachine\Engine\AI\Tools
 */

namespace DataMachine\Engine\AI\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves whether a tool source declaration is locally runnable in this host.
 */
final class HostToolPolicy {

	private const ENV_POLICY_JSON = 'DATAMACHINE_HOST_TOOL_POLICY_JSON';
	private const SCHEMA_SANDBOX_TOOL_POLICY = 'datamachine/sandbox-tool-policy/v1';
	private const SCHEMA_RUNTIME_TOOL_POLICY = 'agents-api/runtime-tool-policy/v1';

	/** @var array<string,mixed> */
	private array $policy;

	/**
	 * @param array<string,mixed> $policy Normalized policy document.
	 */
	private function __construct( array $policy ) {
		$this->policy = $policy;
	}

	/**
	 * Build the active host tool policy for a resolver context.
	 *
	 * Hosts can provide policy directly in resolver args, via the generic
	 * environment variable, or through the filter for deeper integrations.
	 *
	 * @param array<string,mixed> $context Resolver context.
	 */
	public static function fromContext( array $context ): ?self {
		$policy = null;
		foreach ( array( 'host_tool_policy', 'external_tool_ownership_policy' ) as $key ) {
			if ( is_array( $context[ $key ] ?? null ) ) {
				$policy = $context[ $key ];
				break;
			}
		}

		if ( null === $policy ) {
			$policy = self::policyFromEnvironment();
		}

		if ( function_exists( 'apply_filters' ) ) {
			$policy = apply_filters( 'datamachine_host_tool_policy', $policy, $context );
		}

		$policy = self::normalizePolicy( $policy );
		return null !== $policy ? new self( $policy ) : null;
	}

	/**
	 * Return a normalized policy snapshot from the process environment.
	 *
	 * This lets durable job runners capture host ownership while the launching
	 * process still has the host-provided environment available.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function environmentSnapshot(): ?array {
		return self::normalizePolicy( self::policyFromEnvironment() );
	}

	/**
	 * Return the execution location assigned to a tool by host policy.
	 */
	public function executionLocation( string $tool_name ): string {
		$tools = is_array( $this->policy['tools'] ?? null ) ? $this->policy['tools'] : array();
		$rule  = is_array( $tools[ $tool_name ] ?? null ) ? $tools[ $tool_name ] : array();

		$value = is_string( $rule['execution_location'] ?? null )
			? $rule['execution_location']
			: $this->defaultExecutionLocation();

		$value = strtolower( trim( $value ) );
		return '' !== $value ? $value : 'disabled';
	}

	/**
	 * Whether host policy allows the current PHP runner to execute a tool locally.
	 */
	public function isLocallyRunnable( string $tool_name ): bool {
		return 'runner' === $this->executionLocation( $tool_name );
	}

	private function defaultExecutionLocation(): string {
		foreach ( array( 'default_execution_location', 'default_location', 'execution_location' ) as $key ) {
			if ( is_string( $this->policy[ $key ] ?? null ) && '' !== trim( $this->policy[ $key ] ) ) {
				return (string) $this->policy[ $key ];
			}
		}

		return 'disabled';
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function policyFromEnvironment(): ?array {
		$json = getenv( self::ENV_POLICY_JSON );
		if ( ! is_string( $json ) || '' === trim( $json ) ) {
			return null;
		}

		$policy = json_decode( $json, true );
		return is_array( $policy ) ? $policy : null;
	}

	/**
	 * @param mixed $policy Raw policy candidate.
	 * @return array<string,mixed>|null
	 */
	private static function normalizePolicy( $policy ): ?array {
		if ( ! is_array( $policy ) ) {
			return null;
		}

		$unwrapped = self::unwrapPolicyDocument( $policy );
		if ( $unwrapped !== $policy ) {
			return self::normalizePolicy( $unwrapped );
		}

		$transport_policy = self::normalizeTransportPolicy( $policy );
		if ( $transport_policy !== $policy ) {
			return self::normalizePolicy( $transport_policy );
		}

		$tools = is_array( $policy['tools'] ?? null ) ? $policy['tools'] : array();
		foreach ( $tools as $tool_name => $rule ) {
			if ( ! is_string( $tool_name ) || '' === $tool_name || ! is_array( $rule ) ) {
				unset( $tools[ $tool_name ] );
				continue;
			}
			if ( isset( $rule['execution_location'] ) && ! is_string( $rule['execution_location'] ) ) {
				unset( $rule['execution_location'] );
			}
			$tools[ $tool_name ] = $rule;
		}
		$policy['tools'] = $tools;

		$has_default = false;
		foreach ( array( 'default_execution_location', 'default_location', 'execution_location' ) as $key ) {
			if ( is_string( $policy[ $key ] ?? null ) && '' !== trim( $policy[ $key ] ) ) {
				$has_default = true;
				break;
			}
		}

		return $has_default || ! empty( $tools ) ? $policy : null;
	}

	/**
	 * Normalize runtime transport schemas into the host policy document shape.
	 *
	 * @param array<string,mixed> $policy Policy candidate.
	 * @return array<string,mixed>
	 */
	private static function normalizeTransportPolicy( array $policy ): array {
		$schema = is_string( $policy['schema'] ?? null ) ? trim( (string) $policy['schema'] ) : '';
		if ( '' === $schema || ! in_array( $schema, self::transportPolicySchemas(), true ) ) {
			return $policy;
		}

		$tools = is_array( $policy['tools'] ?? null ) ? $policy['tools'] : array();
		if ( empty( $tools ) || ! array_is_list( $tools ) ) {
			return $policy;
		}

		$normalized_tools = array();
		foreach ( $tools as $tool ) {
			if ( ! is_array( $tool ) ) {
				continue;
			}

			$tool_name = is_string( $tool['name'] ?? null )
				? (string) $tool['name']
				: ( is_string( $tool['id'] ?? null ) ? (string) $tool['id'] : '' );
			$tool_name = trim( $tool_name );
			if ( '' === $tool_name ) {
				continue;
			}

			$rule = array();
			if ( is_string( $tool['execution_location'] ?? null ) ) {
				$rule['execution_location'] = (string) $tool['execution_location'];
			}

			$normalized_tools[ $tool_name ] = $rule;
		}

		$policy['tools'] = $normalized_tools;
		return $policy;
	}

	/**
	 * Return list-shaped transport schemas that can be normalized into host policy.
	 *
	 * @return array<int,string>
	 */
	private static function transportPolicySchemas(): array {
		$schemas = array(
			self::SCHEMA_SANDBOX_TOOL_POLICY,
			self::SCHEMA_RUNTIME_TOOL_POLICY,
		);

		if ( function_exists( 'apply_filters' ) ) {
			$schemas = apply_filters( 'datamachine_host_tool_policy_transport_schemas', $schemas );
		}

		if ( ! is_array( $schemas ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $schemas as $schema ) {
			if ( is_string( $schema ) && '' !== trim( $schema ) ) {
				$normalized[] = trim( $schema );
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Unwrap host policy documents embedded in broader runtime payloads.
	 *
	 * @param array<string,mixed> $policy Policy candidate.
	 * @return array<string,mixed>
	 */
	private static function unwrapPolicyDocument( array $policy ): array {
		if ( is_array( $policy['policy'] ?? null ) ) {
			return $policy['policy'];
		}

		if ( is_array( $policy['host_tool_policy'] ?? null ) ) {
			return $policy['host_tool_policy'];
		}

		if ( is_array( $policy['external_tool_ownership_policy'] ?? null ) ) {
			return $policy['external_tool_ownership_policy'];
		}

		$tools = is_array( $policy['tools'] ?? null ) ? $policy['tools'] : null;
		if ( is_array( $tools ) && is_array( $tools['tools'] ?? null ) ) {
			foreach ( array( 'schema', 'default_location', 'default_execution_location', 'execution_location' ) as $key ) {
				if ( isset( $tools[ $key ] ) ) {
					return $tools;
				}
			}
		}

		return $policy;
	}
}
