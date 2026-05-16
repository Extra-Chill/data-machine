<?php
/**
 * Directive Policy Resolver.
 *
 * Applies per-agent directive policy from agent_config.directive_policy to the
 * registered directive stack. Policy entries match either the fully-qualified
 * class name or the short class name (for example CoreMemoryFilesDirective).
 *
 * @package DataMachine\Engine\AI\Directives
 */

namespace DataMachine\Engine\AI\Directives;

use DataMachine\Core\Database\Agents\Agents;

defined( 'ABSPATH' ) || exit;

class DirectivePolicyResolver {

	/**
	 * Resolve directives after applying optional per-agent policy.
	 *
	 * @param array $directives Registered directive configs.
	 * @param array $args       Resolution args: modes, agent_id.
	 * @return array{directives: array, suppressed: array}
	 */
	public function resolve( array $directives, array $args ): array {
		$modes    = self::normalizeModes( $args['modes'] ?? ( array_key_exists( 'mode', $args ) ? array( $args['mode'] ) : array() ) );
		$agent_id = isset( $args['agent_id'] ) ? (int) $args['agent_id'] : 0;

		/**
		 * Filter whether Data Machine directives should be applied to the request.
		 *
		 * Returning false removes every registered directive before directive classes
		 * are invoked, which lets eval/training runners guarantee that Data Machine
		 * context does not leak into the model input.
		 *
		 * @param bool  $enabled    Whether directives are enabled.
		 * @param array $directives  Registered directive configs.
		 * @param array $args        Resolution args.
		 */
		$enabled = apply_filters( 'datamachine_directives_enabled', true, $directives, $args );
		if ( false === $enabled ) {
			return array(
				'directives' => array(),
				'suppressed' => $this->getDirectiveNames( $directives ),
			);
		}

		$policy = $agent_id > 0 ? $this->getAgentDirectivePolicy( $agent_id ) : null;

		$result = $this->applyPolicy( $directives, $policy, $modes );

		/**
		 * Filter the directive stack after per-agent policy is applied.
		 *
		 * @param array $result     Array with directives and suppressed keys.
		 * @param array $directives  Original registered directive configs.
		 * @param array $args        Resolution args.
		 */
		return apply_filters( 'datamachine_resolved_directives', $result, $directives, $args );
	}

	/**
	 * Read an agent's directive policy from agent_config.
	 *
	 * Supported shape:
	 * {
	 *   "directive_policy": {
	 *     "mode": "deny"|"allow_only",
	 *     "deny": ["CoreMemoryFilesDirective"],
	 *     "allow_only": ["AgentModeDirective"],
	 *     "modes": ["pipeline"]
	 *   }
	 * }
	 *
	 * The optional modes list scopes the policy to request modes. Without it,
	 * the policy applies to every mode.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array|null Normalized policy, or null for no-op/invalid policy.
	 */
	public function getAgentDirectivePolicy( int $agent_id ): ?array {
		if ( $agent_id <= 0 ) {
			return null;
		}

		$agents_repo = new Agents();
		$agent       = $agents_repo->get_agent( $agent_id );

		if ( ! $agent ) {
			return null;
		}

		$config = $agent['agent_config'] ?? array();

		if ( empty( $config['directive_policy'] ) || ! is_array( $config['directive_policy'] ) ) {
			return null;
		}

		return $this->normalizePolicy( $config['directive_policy'] );
	}

	/**
	 * Apply a normalized directive policy.
	 *
	 * @param array      $directives Registered directive configs.
	 * @param array|null $policy     Normalized policy.
	 * @param array      $modes      Current agent modes.
	 * @return array{directives: array, suppressed: array}
	 */
	public function applyPolicy( array $directives, ?array $policy, array $modes = array() ): array {
		$modes = self::normalizeModes( $modes );
		if ( null === $policy || ! $this->policyAppliesToModes( $policy, $modes ) ) {
			return array(
				'directives' => $directives,
				'suppressed' => array(),
			);
		}

		$filtered   = array();
		$suppressed = array();
		$mode_name  = $policy['mode'];
		$deny       = array_flip( $policy['deny'] ?? array() );
		$allow_only = array_flip( $policy['allow_only'] ?? array() );

		foreach ( $directives as $directive ) {
			$class = $directive['class'] ?? null;
			if ( ! is_string( $class ) || '' === $class ) {
				$filtered[] = $directive;
				continue;
			}

			$identifiers = $this->getClassIdentifiers( $class );
			$matches     = $this->matchesAny( $identifiers, 'deny' === $mode_name ? $deny : $allow_only );
			$short_name  = $identifiers[ count( $identifiers ) - 1 ];

			if ( 'deny' === $mode_name && $matches ) {
				$suppressed[] = $short_name;
				continue;
			}

			if ( 'allow_only' === $mode_name && ! $matches ) {
				$suppressed[] = $short_name;
				continue;
			}

			$filtered[] = $directive;
		}

		return array(
			'directives' => $filtered,
			'suppressed' => array_values( array_unique( $suppressed ) ),
		);
	}

	/**
	 * Normalize a raw directive policy.
	 *
	 * @param array $policy Raw policy.
	 * @return array|null Normalized policy, or null for invalid/no-op policy.
	 */
	private function normalizePolicy( array $policy ): ?array {
		$mode = $policy['mode'] ?? 'default';
		if ( ! in_array( $mode, array( 'default', 'deny', 'allow_only' ), true ) ) {
			return null;
		}

		if ( 'default' === $mode ) {
			return null;
		}

		$deny       = isset( $policy['deny'] ) && is_array( $policy['deny'] )
			? $this->normalizeClassList( $policy['deny'] )
			: array();
		$allow_only = isset( $policy['allow_only'] ) && is_array( $policy['allow_only'] )
			? $this->normalizeClassList( $policy['allow_only'] )
			: array();
		$modes      = isset( $policy['modes'] ) && is_array( $policy['modes'] )
			? $this->normalizeStringList( $policy['modes'] )
			: array();

		if ( 'deny' === $mode && empty( $deny ) ) {
			return null;
		}

		return array(
			'mode'       => $mode,
			'deny'       => $deny,
			'allow_only' => $allow_only,
			'modes'      => $modes,
		);
	}

	/**
	 * Check whether a policy applies to the current mode.
	 *
	 * @param array  $policy Normalized policy.
	 * @param string $mode   Current mode.
	 * @return bool
	 */
	private function policyAppliesToModes( array $policy, array $active_modes ): bool {
		$modes = $policy['modes'] ?? array();
		return empty( $modes ) || ! empty( array_intersect( $active_modes, $modes ) );
	}

	/** @return array<int,string> */
	private static function normalizeModes( mixed $modes ): array {
		if ( is_string( $modes ) ) {
			$modes = array( $modes );
		}
		if ( ! is_array( $modes ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $modes as $mode ) {
			if ( ! is_scalar( $mode ) ) {
				continue;
			}
			$mode = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $mode ) : strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $mode ) ?? '' );
			if ( '' !== $mode ) {
				$normalized[] = $mode;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Normalize class names from policy config.
	 *
	 * @param array $values Raw values.
	 * @return string[]
	 */
	private function normalizeClassList( array $values ): array {
		return $this->normalizeStringList( $values, true );
	}

	/**
	 * Normalize strings.
	 *
	 * @param array $values Raw values.
	 * @param bool  $class_names Whether values are class names.
	 * @return string[]
	 */
	private function normalizeStringList( array $values, bool $class_names = false ): array {
		$normalized = array();

		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$value = trim( $value );
			if ( '' === $value ) {
				continue;
			}
			$normalized[] = $class_names ? ltrim( $value, '\\' ) : $value;
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Get the FQCN and short-name identifiers for a directive class.
	 *
	 * @param string $class Directive class.
	 * @return string[] FQCN first, short class name second.
	 */
	private function getClassIdentifiers( string $class_name ): array {
		$class_name = ltrim( $class_name, '\\' );
		$pos        = strrpos( $class_name, '\\' );
		$short      = false === $pos ? $class_name : substr( $class_name, $pos + 1 );

		return array_values( array_unique( array( $class_name, $short ) ) );
	}

	/**
	 * Get stable short names for registered directives.
	 *
	 * @param array $directives Registered directive configs.
	 * @return string[] Directive short names.
	 */
	private function getDirectiveNames( array $directives ): array {
		$names = array();
		foreach ( $directives as $directive ) {
			$class = $directive['class'] ?? null;
			if ( ! is_string( $class ) || '' === $class ) {
				continue;
			}
			$identifiers = $this->getClassIdentifiers( $class );
			$names[]     = $identifiers[ count( $identifiers ) - 1 ];
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Check class identifiers against a normalized policy set.
	 *
	 * @param string[] $identifiers Class identifiers.
	 * @param array    $policy_set  Flipped policy list.
	 * @return bool
	 */
	private function matchesAny( array $identifiers, array $policy_set ): bool {
		foreach ( $identifiers as $identifier ) {
			if ( isset( $policy_set[ $identifier ] ) ) {
				return true;
			}
		}

		return false;
	}
}
