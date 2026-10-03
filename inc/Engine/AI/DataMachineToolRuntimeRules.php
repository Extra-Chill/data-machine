<?php
/**
 * Generic Data Machine tool runtime rules.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

use AgentsAPI\AI\WP_Agent_Message;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces bounded tool sequencing rules during an AI conversation.
 */
class DataMachineToolRuntimeRules {

	/** @var array<int,array<string,mixed>> */
	private array $rules;

	/**
	 * @param array $rules Runtime rule config.
	 */
	public function __construct( array $rules = array() ) {
		$this->rules = $this->normalizeRules( $rules );
	}

	/**
	 * Whether any active rules are configured.
	 */
	public function hasRules(): bool {
		return ! empty( $this->rules );
	}

	/**
	 * Evaluate a proposed tool call against configured runtime rules.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $messages  Current conversation messages.
	 * @return array{allowed:bool,error:string,context:array<string,mixed>}
	 */
	public function evaluate( string $tool_name, array $messages ): array {
		foreach ( $this->rules as $rule ) {
			$result = $this->evaluateRule( $rule, $tool_name, $messages );
			if ( ! $result['allowed'] ) {
				return $result;
			}
		}

		return array(
			'allowed' => true,
			'error'   => '',
			'context' => array(),
		);
	}

	/**
	 * @param array<string,mixed> $rule Runtime rule.
	 * @return array{allowed:bool,error:string,context:array<string,mixed>}
	 */
	private function evaluateRule( array $rule, string $tool_name, array $messages ): array {
		if ( 'require_prior_tool' === ( $rule['type'] ?? '' ) ) {
			return $this->evaluateRequirePriorToolRule( $rule, $tool_name, $messages );
		}

		if ( 'block_until_tool' === ( $rule['type'] ?? '' ) ) {
			return $this->evaluateBlockUntilToolRule( $rule, $tool_name, $messages );
		}

		$after_tool  = (string) $rule['after_tool'];
		$after_index = $this->lastToolCallIndex( $messages, $after_tool );
		if ( $after_index < 0 ) {
			return array(
				'allowed' => true,
				'error'   => '',
				'context' => array(),
			);
		}

		$then_require_one_of = (array) $rule['then_require_one_of'];
		if ( in_array( $tool_name, $then_require_one_of, true ) ) {
			return array(
				'allowed' => true,
				'error'   => '',
				'context' => array(),
			);
		}
		if ( $this->hasToolCallAfter( $messages, $after_index, $then_require_one_of ) ) {
			return array(
				'allowed' => true,
				'error'   => '',
				'context' => array(),
			);
		}

		$limited_tools = (array) $rule['limited_tools'];
		$max_calls     = (int) $rule['max_calls'];
		$limited_count = $this->countToolCallsAfter( $messages, $after_index, $limited_tools );
		if ( $limited_count < $max_calls ) {
			return array(
				'allowed' => true,
				'error'   => '',
				'context' => array(),
			);
		}

		$required = implode( ', ', $then_require_one_of );
		$error    = sprintf(
			'TOOL POLICY REJECTED: After %1$s, you already used %2$d of %3$d allowed inspection tools. Do not inspect more. Next tool must be one of: %4$s.',
			$after_tool,
			$limited_count,
			$max_calls,
			$required
		);

		return array(
			'allowed' => false,
			'error'   => $error,
			'context' => array(
				'rule_id'             => (string) $rule['id'],
				'after_tool'          => $after_tool,
				'limited_tools'       => $limited_tools,
				'limited_count'       => $limited_count,
				'max_calls'           => $max_calls,
				'then_require_one_of' => $then_require_one_of,
				'rejected_tool'       => $tool_name,
			),
		);
	}

	/**
	 * @param array<string,mixed> $rule Runtime rule.
	 * @return array{allowed:bool,error:string,context:array<string,mixed>}
	 */
	private function evaluateRequirePriorToolRule( array $rule, string $tool_name, array $messages ): array {
		$before_tool = (string) $rule['before_tool'];
		if ( $tool_name !== $before_tool ) {
			return array(
				'allowed' => true,
				'error'   => '',
				'context' => array(),
			);
		}

		$required_tools      = (array) $rule['require_prior_tool'];
		$required_parameters = is_array( $rule['require_prior_tool_parameters'] ?? null ) ? $rule['require_prior_tool_parameters'] : array();
		foreach ( $required_tools as $required_tool ) {
			if ( $this->lastToolCallIndex( $messages, (string) $required_tool, is_array( $required_parameters[ $required_tool ] ?? null ) ? $required_parameters[ $required_tool ] : array() ) >= 0 ) {
				return array(
					'allowed' => true,
					'error'   => '',
					'context' => array(),
				);
			}
		}

		$required = implode( ', ', $required_tools );
		$error    = sprintf(
			'TOOL POLICY REJECTED: Before using %1$s, first use one of: %2$s.',
			$before_tool,
			$required
		);

		return array(
			'allowed' => false,
			'error'   => $error,
			'context' => array(
				'rule_id'                       => (string) $rule['id'],
				'before_tool'                   => $before_tool,
				'require_prior_tool'            => $required_tools,
				'require_prior_tool_parameters' => $required_parameters,
				'rejected_tool'                 => $tool_name,
			),
		);
	}

	/**
	 * @param array<string,mixed> $rule Runtime rule.
	 * @return array{allowed:bool,error:string,context:array<string,mixed>}
	 */
	private function evaluateBlockUntilToolRule( array $rule, string $tool_name, array $messages ): array {
		$after_tool  = (string) $rule['after_tool'];
		$after_index = $this->lastToolCallIndex( $messages, $after_tool );
		if ( $after_index < 0 ) {
			return array(
				'allowed' => true,
				'error'   => '',
				'context' => array(),
			);
		}

		$until_one_of = (array) $rule['until_one_of'];
		if ( in_array( $tool_name, $until_one_of, true ) || $this->hasToolCallAfter( $messages, $after_index, $until_one_of ) ) {
			return array(
				'allowed' => true,
				'error'   => '',
				'context' => array(),
			);
		}

		$blocked_tools = (array) $rule['blocked_tools'];
		if ( ! in_array( $tool_name, $blocked_tools, true ) ) {
			return array(
				'allowed' => true,
				'error'   => '',
				'context' => array(),
			);
		}

		$required = implode( ', ', $until_one_of );
		$error    = sprintf(
			'TOOL POLICY REJECTED: After %1$s, do not use %2$s again until one of these follow-up tools succeeds: %3$s.',
			$after_tool,
			$tool_name,
			$required
		);

		return array(
			'allowed' => false,
			'error'   => $error,
			'context' => array(
				'rule_id'       => (string) $rule['id'],
				'after_tool'    => $after_tool,
				'blocked_tools' => $blocked_tools,
				'until_one_of'  => $until_one_of,
				'rejected_tool' => $tool_name,
			),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function normalizeRules( array $rules ): array {
		$normalized = array();
		foreach ( $rules as $index => $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$type = $this->sanitizeRuleType( $rule['type'] ?? '' );
			if ( 'require_prior_tool' === $type ) {
				$before_tool                   = $this->sanitizeToolName( $rule['before_tool'] ?? '' );
				$require_prior_tool            = $this->sanitizeToolList( $rule['require_prior_tool'] ?? $rule['require_prior_tools'] ?? array() );
				$require_prior_tool_parameters = $this->sanitizeToolParameterConditions( $rule['require_prior_tool_parameters'] ?? array(), $require_prior_tool );
				if ( '' === $before_tool || empty( $require_prior_tool ) ) {
					continue;
				}

				$normalized[] = array(
					'id'                            => $this->sanitizeRuleId( $rule['id'] ?? 'tool-runtime-rule-' . $index ),
					'type'                          => 'require_prior_tool',
					'before_tool'                   => $before_tool,
					'require_prior_tool'            => $require_prior_tool,
					'require_prior_tool_parameters' => $require_prior_tool_parameters,
				);
				continue;
			}

			if ( 'block_until_tool' === $type ) {
				$after_tool    = $this->sanitizeToolName( $rule['after_tool'] ?? '' );
				$blocked_tools = $this->sanitizeToolList( $rule['blocked_tools'] ?? $rule['blocked_tool_names'] ?? array() );
				$until_one_of  = $this->sanitizeToolList( $rule['until_one_of'] ?? $rule['then_require_one_of'] ?? array() );
				if ( '' === $after_tool || empty( $blocked_tools ) || empty( $until_one_of ) ) {
					continue;
				}

				$normalized[] = array(
					'id'            => $this->sanitizeRuleId( $rule['id'] ?? 'tool-runtime-rule-' . $index ),
					'type'          => 'block_until_tool',
					'after_tool'    => $after_tool,
					'blocked_tools' => $blocked_tools,
					'until_one_of'  => $until_one_of,
				);
				continue;
			}

			$after_tool          = $this->sanitizeToolName( $rule['after_tool'] ?? '' );
			$limited_tools       = $this->sanitizeToolList( $rule['limited_tools'] ?? $rule['limited_tool_names'] ?? array() );
			$then_require_one_of = $this->sanitizeToolList( $rule['then_require_one_of'] ?? array() );
			$max_calls           = max( 0, (int) ( $rule['max_calls'] ?? 0 ) );

			if ( '' === $after_tool || empty( $limited_tools ) || empty( $then_require_one_of ) || $max_calls <= 0 ) {
				continue;
			}

			$normalized[] = array(
				'id'                  => $this->sanitizeRuleId( $rule['id'] ?? 'tool-runtime-rule-' . $index ),
				'after_tool'          => $after_tool,
				'limited_tools'       => $limited_tools,
				'max_calls'           => $max_calls,
				'then_require_one_of' => $then_require_one_of,
			);
		}

		return $normalized;
	}

	/**
	 * @param array<int,array<string,mixed>> $messages Conversation messages.
	 */
	private function lastToolCallIndex( array $messages, string $tool_name, array $parameter_conditions = array() ): int {
		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			$call = $this->toolCallFromMessage( $messages[ $i ] );
			if ( $call && $call['tool_name'] === $tool_name && $this->toolCallMatchesParameters( $call, $parameter_conditions ) ) {
				return $i;
			}
		}

		return -1;
	}

	/**
	 * @param array<int,array<string,mixed>> $messages Conversation messages.
	 * @param array<int,string>              $tool_names Limited tool names.
	 */
	private function countToolCallsAfter( array $messages, int $after_index, array $tool_names ): int {
		$count         = 0;
		$message_count = count( $messages );
		for ( $i = $after_index + 1; $i < $message_count; $i++ ) {
			$call = $this->toolCallFromMessage( $messages[ $i ] );
			if ( $call && in_array( $call['tool_name'], $tool_names, true ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @param array<int,array<string,mixed>> $messages Conversation messages.
	 * @param array<int,string>              $tool_names Tool names.
	 */
	private function hasToolCallAfter( array $messages, int $after_index, array $tool_names ): bool {
		$message_count = count( $messages );
		for ( $i = $after_index + 1; $i < $message_count; $i++ ) {
			$call = $this->toolCallFromMessage( $messages[ $i ] );
			if ( $call && in_array( $call['tool_name'], $tool_names, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $message Conversation message.
	 * @return array{tool_name:string,parameters:array<string,mixed>}|null
	 */
	private function toolCallFromMessage( array $message ): ?array {
		$envelope = WP_Agent_Message::normalize( $message );
		if ( WP_Agent_Message::TYPE_TOOL_CALL !== $envelope['type'] ) {
			return null;
		}

		$tool_name = $envelope['payload']['tool_name'] ?? null;
		if ( ! is_string( $tool_name ) || '' === $tool_name ) {
			return null;
		}

		$parameters = $envelope['payload']['parameters'] ?? array();

		return array(
			'tool_name'  => $tool_name,
			'parameters' => is_array( $parameters ) ? $parameters : array(),
		);
	}

	/**
	 * @param array{tool_name:string,parameters:array<string,mixed>} $call Tool call.
	 * @param array<string,mixed>                                    $conditions Parameter conditions.
	 */
	private function toolCallMatchesParameters( array $call, array $conditions ): bool {
		if ( empty( $conditions ) ) {
			return true;
		}

		$parameters = is_array( $call['parameters'] ?? null ) ? $call['parameters'] : array();
		foreach ( $conditions as $key => $expected ) {
			if ( ! array_key_exists( $key, $parameters ) || $parameters[ $key ] !== $expected ) {
				return false;
			}
		}

		return true;
	}

	private function sanitizeRuleId( $value ): string {
		$id = preg_replace( '/[^a-zA-Z0-9_\-]/', '-', (string) $value ) ?? '';
		return '' !== $id ? $id : 'tool-runtime-rule';
	}

	private function sanitizeRuleType( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( 'require_prior_tool', 'block_until_tool' ), true ) ? $value : '';
	}

	/**
	 * @param mixed             $value Raw parameter condition map.
	 * @param array<int,string> $tool_names Tools allowed by the prerequisite rule.
	 * @return array<string,array<string,mixed>>
	 */
	private function sanitizeToolParameterConditions( $value, array $tool_names ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$allowed_tools = array_flip( $tool_names );
		$conditions    = array();
		foreach ( $value as $tool_name => $tool_conditions ) {
			$tool_name = $this->sanitizeToolName( $tool_name );
			if ( '' === $tool_name || ! isset( $allowed_tools[ $tool_name ] ) || ! is_array( $tool_conditions ) ) {
				continue;
			}

			foreach ( $tool_conditions as $parameter => $expected ) {
				$parameter = sanitize_key( (string) $parameter );
				if ( '' === $parameter || is_array( $expected ) || is_object( $expected ) ) {
					continue;
				}

				$conditions[ $tool_name ][ $parameter ] = $expected;
			}
		}

		return $conditions;
	}

	private function sanitizeToolName( $value ): string {
		$value = trim( (string) $value );
		return '' !== $value ? $value : '';
	}

	/**
	 * @param mixed $value Raw list.
	 * @return array<int,string>
	 */
	private function sanitizeToolList( $value ): array {
		if ( is_string( $value ) && '' !== $value ) {
			$value = array( $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$tools = array();
		foreach ( $value as $tool ) {
			$tool = $this->sanitizeToolName( $tool );
			if ( '' !== $tool ) {
				$tools[] = $tool;
			}
		}

		return array_values( array_unique( $tools ) );
	}
}
