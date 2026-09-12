<?php
/**
 * Deterministic declarative tool-call gate.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enforces bounded tool-sequencing rules during an agent conversation run.
 *
 * The gate is a pure, deterministic primitive: given a proposed tool call (or a
 * pending completion) and the current transcript, it decides whether the loop
 * may proceed. Enforcement lives in the runtime ({@see WP_Agent_Conversation_Loop}),
 * not in a system prompt — a model cannot opt out of a configured rule.
 *
 * Each rule expresses one bounded-discovery contract:
 *
 *   "After `after_tool` is called, at most `max_calls` of `limited_tools` may run
 *    before the agent must call one of `require_one_of`; and the run may not
 *    finish until one of `require_one_of` has been called."
 *
 * Rule shape (declarative, provider-agnostic):
 *
 *   array(
 *     'id'              => 'bounded-discovery',         // optional, sanitized
 *     'after_tool'      => 'workspace_show',            // anchor that activates the rule
 *     'limited_tools'   => array( 'workspace_read', … ), // rate-limited after the anchor
 *     'max_calls'       => 12,                          // budget for limited_tools
 *     'require_one_of'  => array( 'workspace_write', … ), // tools that satisfy/reset the rule
 *     'gate_calls'      => true,                        // enforce per-call limit (default true)
 *     'gate_completion' => true,                        // block completion until satisfied (default true)
 *   )
 *
 * A second, anchor-independent rule shape enforces "do not finish without doing
 * real work". It blocks completion whenever the run is about to end with fewer
 * than `min_tool_calls` qualifying tool calls in the whole transcript — even on
 * turn one, with no anchor required. This catches the failure mode where a model
 * narrates its intended action as prose ("List root.") instead of emitting a
 * tool call, which the loop would otherwise treat as natural completion:
 *
 *   array(
 *     'id'               => 'require-tool-use',
 *     'require_tool_use' => true,                       // unconditional commit guarantee
 *     'min_tool_calls'   => 1,                          // minimum qualifying calls (default 1)
 *     'require_one_of'   => array( 'workspace_write' ), // optional: only these calls qualify;
 *                                                       // omit to count ANY tool call
 *   )
 *
 * Field aliases accepted for portability with prior bundle shapes:
 *   `then_require_one_of` => `require_one_of`, `limited_tool_names` => `limited_tools`.
 */
class WP_Agent_Tool_Call_Gate {

	public const EVENT_CALL_REJECTED      = 'tool_call_gate_rejected';
	public const EVENT_COMPLETION_BLOCKED = 'tool_call_gate_completion_blocked';

	public const ERROR_TYPE_CALL_REJECTED      = 'tool_call_gate_rejected';
	public const ERROR_TYPE_COMPLETION_BLOCKED = 'tool_call_gate_completion_blocked';

	/** @var array<int,array{id:string,after_tool:string,limited_tools:list<string>,max_calls:int,require_one_of:list<string>,require_tool_use:bool,min_tool_calls:int,gate_calls:bool,gate_completion:bool}> Normalized rules. */
	private array $rules;

	/**
	 * @param array<mixed> $rules Raw rule configuration.
	 */
	public function __construct( array $rules = array() ) {
		$this->rules = self::normalize_rules( $rules );
	}

	/**
	 * Build a gate from a loop options/config array, or null when no rules apply.
	 *
	 * @param mixed $rules Raw `tool_call_rules` value.
	 * @return self|null
	 */
	public static function from_config( $rules ): ?self {
		if ( ! is_array( $rules ) ) {
			return null;
		}

		$gate = new self( $rules );

		return $gate->has_rules() ? $gate : null;
	}

	/**
	 * Whether any active rules are configured.
	 *
	 * @return bool
	 */
	public function has_rules(): bool {
		return ! empty( $this->rules );
	}

	/**
	 * Decide whether a proposed tool call may proceed against per-call rules.
	 *
	 * `$prior_messages` must contain the transcript BEFORE the proposed call (the
	 * current tool-call message excluded) so the proposed tool is not counted
	 * against its own limit.
	 *
	 * @param string            $tool_name      Proposed tool name.
	 * @param array<int,mixed>  $prior_messages Transcript before the proposed call.
	 * @return array{allowed:bool,reason:string,context:array<string,mixed>}
	 */
	public function evaluate_call( string $tool_name, array $prior_messages ): array {
		foreach ( $this->rules as $rule ) {
			if ( empty( $rule['gate_calls'] ) ) {
				continue;
			}

			$after_index = self::last_tool_call_index( $prior_messages, $rule['after_tool'] );
			if ( $after_index < 0 ) {
				continue;
			}

			if ( in_array( $tool_name, $rule['require_one_of'], true ) ) {
				continue;
			}

			if ( self::has_tool_call_after( $prior_messages, $after_index, $rule['require_one_of'] ) ) {
				continue;
			}

			$limited_count = self::count_tool_calls_after( $prior_messages, $after_index, $rule['limited_tools'] );
			if ( $limited_count < $rule['max_calls'] ) {
				continue;
			}

			$required = implode( ', ', $rule['require_one_of'] );
			$reason   = sprintf(
				'TOOL CALL BLOCKED: after %1$s you have already used %2$d of %3$d permitted discovery tools. Do not inspect further. Your next tool call must be one of: %4$s.',
				$rule['after_tool'],
				$limited_count,
				$rule['max_calls'],
				$required
			);

			return array(
				'allowed' => false,
				'reason'  => $reason,
				'context' => array(
					'rule_id'        => $rule['id'],
					'after_tool'     => $rule['after_tool'],
					'limited_tools'  => $rule['limited_tools'],
					'limited_count'  => $limited_count,
					'max_calls'      => $rule['max_calls'],
					'require_one_of' => $rule['require_one_of'],
					'rejected_tool'  => $tool_name,
				),
			);
		}

		return array(
			'allowed' => true,
			'reason'  => '',
			'context' => array(),
		);
	}

	/**
	 * Decide whether the run may complete against completion rules.
	 *
	 * A completion rule blocks the run from finishing once its `after_tool` anchor
	 * has been reached but none of its `require_one_of` tools has been called — the
	 * restored "bounded discovery, then commit" guarantee.
	 *
	 * @param array<int,mixed> $messages Current transcript.
	 * @return array{allowed:bool,reason:string,context:array<string,mixed>}
	 */
	public function evaluate_completion( array $messages ): array {
		foreach ( $this->rules as $rule ) {
			if ( empty( $rule['gate_completion'] ) ) {
				continue;
			}

			// Anchor-independent "do real work before finishing" rule. Blocks
			// completion until at least `min_tool_calls` qualifying tool calls
			// have run, with no anchor required — so a turn-one stop with zero
			// tool calls (the model narrated instead of acting) is refused.
			if ( ! empty( $rule['require_tool_use'] ) ) {
				$qualifying = empty( $rule['require_one_of'] ) ? null : $rule['require_one_of'];
				$seen       = self::count_any_tool_calls( $messages, $qualifying );
				if ( $seen >= $rule['min_tool_calls'] ) {
					continue;
				}

				if ( null !== $qualifying ) {
					$reason = sprintf(
						'COMPLETION BLOCKED: the task is not finished and you have not used the required tools yet. Make concrete progress now by calling one of these tools: %s. Perform actions as tool calls rather than describing them in text.',
						implode( ', ', $rule['require_one_of'] )
					);
				} else {
					$reason = 'COMPLETION BLOCKED: the task is not finished and you have not used any tools yet. Make concrete progress now by calling one of your available tools. Perform actions as tool calls rather than describing them in text.';
				}

				return array(
					'allowed' => false,
					'reason'  => $reason,
					'context' => array(
						'rule_id'          => $rule['id'],
						'require_tool_use' => true,
						'min_tool_calls'   => $rule['min_tool_calls'],
						'tool_calls_seen'  => $seen,
						'require_one_of'   => $rule['require_one_of'],
					),
				);
			}

			$anchor_index = self::first_tool_call_index( $messages, $rule['after_tool'] );
			if ( $anchor_index < 0 ) {
				continue;
			}

			if ( self::has_tool_call_after( $messages, $anchor_index - 1, $rule['require_one_of'] ) ) {
				continue;
			}

			$required = implode( ', ', $rule['require_one_of'] );
			$reason   = sprintf(
				'COMPLETION BLOCKED: you began discovery with %1$s but have not yet committed your work. Call one of these tools before finishing: %2$s.',
				$rule['after_tool'],
				$required
			);

			return array(
				'allowed' => false,
				'reason'  => $reason,
				'context' => array(
					'rule_id'        => $rule['id'],
					'after_tool'     => $rule['after_tool'],
					'require_one_of' => $rule['require_one_of'],
				),
			);
		}

		return array(
			'allowed' => true,
			'reason'  => '',
			'context' => array(),
		);
	}

	/**
	 * Return the subset of messages that precede the given tool-call id.
	 *
	 * Used to exclude the in-flight proposed tool-call message from per-call
	 * counts so a tool is never gated against itself.
	 *
	 * @param array<mixed> $messages     Transcript messages.
	 * @param string       $tool_call_id Current tool-call id.
	 * @return array<int,mixed>
	 */
	public static function messages_before_tool_call( array $messages, string $tool_call_id ): array {
		if ( '' === $tool_call_id ) {
			return array_values( $messages );
		}

		$prior = array();
		foreach ( array_values( $messages ) as $message ) {
			if ( ! is_array( $message ) ) {
				$prior[] = $message;
				continue;
			}
			$envelope = WP_Agent_Message::normalize( $message );
			if ( WP_Agent_Message::TYPE_TOOL_CALL === $envelope['type'] ) {
				$metadata     = $envelope['metadata'];
				$message_call = is_array( $metadata ) ? ( $metadata['tool_call_id'] ?? '' ) : '';
				if ( $message_call === $tool_call_id ) {
					break;
				}
			}
			$prior[] = $message;
		}

		return $prior;
	}

	/**
	 * Normalize raw rules to a deterministic internal shape.
	 *
	 * @param array<mixed> $rules Raw rules.
	 * @return array<int,array{id:string,after_tool:string,limited_tools:list<string>,max_calls:int,require_one_of:list<string>,require_tool_use:bool,min_tool_calls:int,gate_calls:bool,gate_completion:bool}>
	 */
	private static function normalize_rules( array $rules ): array {
		$normalized = array();
		foreach ( $rules as $index => $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$max_raw          = $rule['max_calls'] ?? 0;
			$min_raw          = $rule['min_tool_calls'] ?? 1;
			$after_tool       = self::sanitize_tool_name( $rule['after_tool'] ?? '' );
			$limited_tools    = self::sanitize_tool_list( $rule['limited_tools'] ?? ( $rule['limited_tool_names'] ?? array() ) );
			$require_one_of   = self::sanitize_tool_list( $rule['require_one_of'] ?? ( $rule['then_require_one_of'] ?? array() ) );
			$max_calls        = max( 0, is_numeric( $max_raw ) ? (int) $max_raw : 0 );
			$min_tool_calls   = max( 1, is_numeric( $min_raw ) ? (int) $min_raw : 1 );
			$require_tool_use = self::bool_flag( $rule['require_tool_use'] ?? false );
			$gate_calls       = self::bool_flag( $rule['gate_calls'] ?? true );
			$gate_complete    = self::bool_flag( $rule['gate_completion'] ?? true );

			// A `require_tool_use` rule is anchor-independent: it needs no anchor
			// or commit set and enforces only completion. Every other rule must
			// declare an anchor and at least one required commit tool.
			if ( $require_tool_use ) {
				if ( ! $gate_complete ) {
					continue;
				}
			} elseif ( '' === $after_tool || empty( $require_one_of ) ) {
				continue;
			}

			// Per-call gating needs an anchor and a bounded limited-tool budget;
			// without one, only completion gating remains meaningful.
			$can_gate_calls = $gate_calls && '' !== $after_tool && ! empty( $limited_tools ) && $max_calls > 0;
			if ( ! $require_tool_use && ! $can_gate_calls && ! $gate_complete ) {
				continue;
			}

			$normalized[] = array(
				'id'               => self::sanitize_rule_id( $rule['id'] ?? ( 'tool-call-rule-' . $index ) ),
				'after_tool'       => $after_tool,
				'limited_tools'    => $limited_tools,
				'max_calls'        => $max_calls,
				'require_one_of'   => $require_one_of,
				'require_tool_use' => $require_tool_use,
				'min_tool_calls'   => $min_tool_calls,
				'gate_calls'       => $can_gate_calls,
				'gate_completion'  => $gate_complete,
			);
		}

		return $normalized;
	}

	/**
	 * Count tool-call messages across the whole transcript.
	 *
	 * @param array<int,mixed>       $messages   Transcript messages.
	 * @param array<int,string>|null $tool_names Tool names to count, or null to count any tool call.
	 * @return int
	 */
	private static function count_any_tool_calls( array $messages, ?array $tool_names ): int {
		$count = 0;
		foreach ( array_values( $messages ) as $message ) {
			$name = self::tool_call_name( $message );
			if ( '' === $name ) {
				continue;
			}
			if ( null === $tool_names || in_array( $name, $tool_names, true ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Index of the last tool-call message for a given tool name.
	 *
	 * @param array<int,mixed> $messages  Transcript messages.
	 * @param string           $tool_name Tool name.
	 * @return int -1 when not found.
	 */
	private static function last_tool_call_index( array $messages, string $tool_name ): int {
		$messages = array_values( $messages );
		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			if ( self::tool_call_name( $messages[ $i ] ) === $tool_name ) {
				return $i;
			}
		}

		return -1;
	}

	/**
	 * Index of the first tool-call message for a given tool name.
	 *
	 * @param array<int,mixed> $messages  Transcript messages.
	 * @param string           $tool_name Tool name.
	 * @return int -1 when not found.
	 */
	private static function first_tool_call_index( array $messages, string $tool_name ): int {
		$messages = array_values( $messages );
		$count    = count( $messages );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( self::tool_call_name( $messages[ $i ] ) === $tool_name ) {
				return $i;
			}
		}

		return -1;
	}

	/**
	 * Count tool-call messages for any of the given names after an index.
	 *
	 * @param array<int,mixed>  $messages    Transcript messages.
	 * @param int               $after_index Exclusive lower bound.
	 * @param array<int,string> $tool_names  Tool names to count.
	 * @return int
	 */
	private static function count_tool_calls_after( array $messages, int $after_index, array $tool_names ): int {
		$messages = array_values( $messages );
		$count    = 0;
		$total    = count( $messages );
		for ( $i = $after_index + 1; $i < $total; $i++ ) {
			$name = self::tool_call_name( $messages[ $i ] );
			if ( '' !== $name && in_array( $name, $tool_names, true ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Whether any of the given tool names was called after an index.
	 *
	 * @param array<int,mixed>  $messages    Transcript messages.
	 * @param int               $after_index Exclusive lower bound.
	 * @param array<int,string> $tool_names  Tool names to look for.
	 * @return bool
	 */
	private static function has_tool_call_after( array $messages, int $after_index, array $tool_names ): bool {
		$messages = array_values( $messages );
		$total    = count( $messages );
		for ( $i = $after_index + 1; $i < $total; $i++ ) {
			$name = self::tool_call_name( $messages[ $i ] );
			if ( '' !== $name && in_array( $name, $tool_names, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read the tool name from a tool-call message, or '' when it is not one.
	 *
	 * @param mixed $message Transcript message.
	 * @return string
	 */
	private static function tool_call_name( $message ): string {
		if ( ! is_array( $message ) ) {
			return '';
		}

		$envelope = WP_Agent_Message::normalize( $message );
		if ( WP_Agent_Message::TYPE_TOOL_CALL !== $envelope['type'] ) {
			return '';
		}

		$payload = is_array( $envelope['payload'] ?? null ) ? $envelope['payload'] : array();
		$name    = $payload['tool_name'] ?? '';

		return is_string( $name ) ? $name : '';
	}

	/**
	 * Coerce a flag value to bool, treating absence as the provided default.
	 *
	 * @param mixed $value Raw flag value.
	 * @return bool
	 */
	private static function bool_flag( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), array( '', '0', 'false', 'no', 'off' ), true );
		}

		return (bool) $value;
	}

	/**
	 * Sanitize a rule id to a stable slug.
	 *
	 * @param mixed $value Raw id.
	 * @return string
	 */
	private static function sanitize_rule_id( $value ): string {
		$raw = is_scalar( $value ) ? (string) $value : '';
		$id  = preg_replace( '/[^a-zA-Z0-9_\-]/', '-', $raw );
		$id = is_string( $id ) ? trim( $id, '-' ) : '';

		return '' !== $id ? $id : 'tool-call-rule';
	}

	/**
	 * Sanitize a single tool name.
	 *
	 * @param mixed $value Raw tool name.
	 * @return string
	 */
	private static function sanitize_tool_name( $value ): string {
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Sanitize a list of tool names, dropping blanks and duplicates.
	 *
	 * @param mixed $value Raw list (string or array).
	 * @return list<string>
	 */
	private static function sanitize_tool_list( $value ): array {
		if ( is_string( $value ) && '' !== $value ) {
			$value = array( $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$tools = array();
		foreach ( $value as $tool ) {
			$tool = self::sanitize_tool_name( $tool );
			if ( '' !== $tool ) {
				$tools[] = $tool;
			}
		}

		return array_values( array_unique( $tools ) );
	}
}
