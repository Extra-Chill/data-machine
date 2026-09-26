<?php
/**
 * Routine value object.
 *
 * A *routine* is a persistent, scheduled invocation of an agent. Unlike a
 * workflow (deterministic recipe with fresh inputs per run) or a one-shot
 * background task (single ability dispatched into Action Scheduler), a
 * routine reuses the same conversation session across every wake — so
 * context accumulates and the agent can pick up where the last wake left
 * off.
 *
 * Conceptually the Anthropic deck pattern: an agent that has a "main loop"
 * separate from a one-shot task, with a long-running session and
 * active-vs-total time semantics.
 *
 * Substrate scope: contracts only. The actual scheduler is the existing
 * {@see WP_Agent_Routine_Action_Scheduler_Bridge}, the actual dispatch on
 * wake-up is the canonical `agents/chat` ability (or any other ability
 * the routine names), and persistence of the per-routine session is the
 * consumer's responsibility (typically the chat conversation store).
 *
 * @package AgentsAPI
 * @since   0.105.0
 */

namespace AgentsAPI\AI\Routines;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Routine {

	public const TRIGGER_INTERVAL   = 'interval';
	public const TRIGGER_EXPRESSION = 'expression';

	/**
	 * Wake target types. A `chat` target dispatches the routine's prompt to
	 * its agent through the canonical `agents/chat` ability; an `ability`
	 * target executes the named ability directly with the routine's input.
	 */
	public const TARGET_CHAT    = 'chat';
	public const TARGET_ABILITY = 'ability';

	/**
	 * Default upper bound for the deterministic first-run stagger window, in
	 * seconds. The effective window is always capped by the routine's own
	 * interval so a routine never waits longer than one interval to first run.
	 */
	public const MAX_STAGGER_SECONDS = 3600;

	private string $id;
	private string $label;
	private string $agent_slug;
	private string $ability_slug = '';
	/** @var array<string, mixed> */
	private array $ability_input = array();
	private string $trigger_type;
	private int $interval_s     = 0;
	private string $expression  = '';
	private string $prompt      = '';
	private string $session_id  = '';
	private int $stagger_window = 0;
	/** @var array<string, mixed> */
	private array $meta = array();

	/**
	 * @param string                $id   Unique routine slug.
	 * @param array<string, mixed>  $args Recognised keys: `label` (string),
	 *                                    `agent` (string) OR `ability`
	 *                                    (string — exactly one of the two is
	 *                                    required), `input` (array, ability
	 *                                    targets only), `interval` (int
	 *                                    seconds) OR `expression` (cron
	 *                                    string), `prompt` (string),
	 *                                    `session_id` (string), `stagger`
	 *                                    (bool|int — see
	 *                                    {@see stagger_offset()}), `meta`
	 *                                    (array).
	 */
	public function __construct( string $id, array $args ) {
		$id = sanitize_title( $id );
		if ( '' === $id ) {
			throw new \InvalidArgumentException( 'Routine id cannot be empty.' );
		}
		$this->id = $id;

		$this->label = isset( $args['label'] ) && is_scalar( $args['label'] ) ? (string) $args['label'] : $id;

		$agent   = isset( $args['agent'] ) && is_scalar( $args['agent'] ) ? (string) $args['agent'] : '';
		$ability = isset( $args['ability'] ) && is_scalar( $args['ability'] ) ? (string) $args['ability'] : '';

		if ( '' !== $agent && '' !== $ability ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Routine "%s" must specify either an agent or an ability wake target, not both.', $id ) ) );
		}
		if ( '' === $agent && '' === $ability ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Routine "%s" must specify an agent slug or an ability slug.', $id ) ) );
		}

		$this->agent_slug   = $agent;
		$this->ability_slug = $ability;

		if ( isset( $args['input'] ) && is_array( $args['input'] ) ) {
			foreach ( $args['input'] as $key => $value ) {
				if ( is_string( $key ) ) {
					$this->ability_input[ $key ] = $value;
				}
			}
		}

		$has_interval   = isset( $args['interval'] ) && is_numeric( $args['interval'] ) && (int) $args['interval'] > 0;
		$has_expression = isset( $args['expression'] ) && is_scalar( $args['expression'] ) && '' !== trim( (string) $args['expression'] );

		if ( $has_interval && $has_expression ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Routine "%s" must specify either `interval` or `expression`, not both.', $id ) ) );
		}
		if ( ! $has_interval && ! $has_expression ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Routine "%s" must specify a trigger via `interval` (seconds) or `expression` (cron string).', $id ) ) );
		}

		if ( $has_interval ) {
			$this->trigger_type = self::TRIGGER_INTERVAL;
			$this->interval_s   = (int) $args['interval'];
		} else {
			$this->trigger_type = self::TRIGGER_EXPRESSION;
			$this->expression   = trim( (string) $args['expression'] );
		}

		$this->stagger_window = self::resolve_stagger_window( $args['stagger'] ?? null, $this->trigger_type );

		$this->prompt     = isset( $args['prompt'] ) && is_scalar( $args['prompt'] ) ? (string) $args['prompt'] : '';
		$this->session_id = isset( $args['session_id'] ) && is_scalar( $args['session_id'] ) && '' !== (string) $args['session_id']
			? (string) $args['session_id']
			: 'routine:' . $id;

		if ( isset( $args['meta'] ) && is_array( $args['meta'] ) ) {
			foreach ( $args['meta'] as $key => $value ) {
				if ( is_string( $key ) ) {
					$this->meta[ $key ] = $value;
				}
			}
		}
	}

	public function get_id(): string {
		return $this->id;
	}

	public function get_label(): string {
		return $this->label;
	}

	public function get_agent_slug(): string {
		return $this->agent_slug;
	}

	/**
	 * The wake target type: {@see TARGET_CHAT} for agent/chat dispatch, or
	 * {@see TARGET_ABILITY} for a direct ability execution.
	 *
	 * @since 0.11.0
	 */
	public function get_target_type(): string {
		return '' !== $this->ability_slug ? self::TARGET_ABILITY : self::TARGET_CHAT;
	}

	/**
	 * The target ability slug for {@see TARGET_ABILITY} routines (empty
	 * otherwise).
	 *
	 * @since 0.11.0
	 */
	public function get_ability(): string {
		return $this->ability_slug;
	}

	/**
	 * Input passed to the target ability on each wake ({@see TARGET_ABILITY}
	 * routines only).
	 *
	 * @return array<string, mixed>
	 *
	 * @since 0.11.0
	 */
	public function get_input(): array {
		return $this->ability_input;
	}

	public function get_trigger_type(): string {
		return $this->trigger_type;
	}

	public function get_interval_seconds(): int {
		return $this->interval_s;
	}

	/**
	 * Deterministic first-run stagger offset in seconds.
	 *
	 * Routines registered with the same interval would otherwise all fire in
	 * the same second. The offset spreads them across a bounded window:
	 * `crc32( 'agents_routine_stagger_' . $id ) % min( interval, window )`.
	 * It depends only on the routine id, so re-registration always lands the
	 * routine back in the same slot.
	 *
	 * The `stagger` arg controls the window: `true` uses
	 * {@see MAX_STAGGER_SECONDS}, an int sets an explicit max window in
	 * seconds, and `false` (or `0`) disables staggering. Defaults to `true`
	 * for interval routines and `false` for cron-expression routines, where
	 * the expression already *is* the slot.
	 */
	public function stagger_offset(): int {
		if ( self::TRIGGER_INTERVAL !== $this->trigger_type || $this->stagger_window <= 0 || $this->interval_s <= 0 ) {
			return 0;
		}

		$max_offset = min( $this->interval_s, $this->stagger_window );
		return crc32( 'agents_routine_stagger_' . $this->id ) % $max_offset;
	}

	/**
	 * Configured stagger window ceiling in seconds (0 = staggering disabled).
	 */
	public function get_stagger_window(): int {
		return self::TRIGGER_INTERVAL === $this->trigger_type ? $this->stagger_window : 0;
	}

	/**
	 * @param mixed  $stagger     Raw `stagger` arg (bool|int|null).
	 * @param string $trigger_type Resolved trigger type.
	 */
	private static function resolve_stagger_window( $stagger, string $trigger_type ): int {
		if ( self::TRIGGER_INTERVAL !== $trigger_type ) {
			return 0;
		}

		if ( null === $stagger ) {
			$stagger = true;
		}

		if ( is_bool( $stagger ) ) {
			return $stagger ? self::MAX_STAGGER_SECONDS : 0;
		}

		if ( is_numeric( $stagger ) ) {
			return max( 0, (int) $stagger );
		}

		return 0;
	}

	public function get_expression(): string {
		return $this->expression;
	}

	public function get_prompt(): string {
		return $this->prompt;
	}

	/**
	 * Persistent session id. Reused across every wake so the agent's
	 * conversation history accumulates.
	 */
	public function get_session_id(): string {
		return $this->session_id;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_meta(): array {
		return $this->meta;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$out = array(
			'id'         => $this->id,
			'label'      => $this->label,
			'target'     => $this->get_target_type(),
			'agent'      => $this->agent_slug,
			'prompt'     => $this->prompt,
			'session_id' => $this->session_id,
			'meta'       => $this->meta,
		);
		if ( self::TARGET_ABILITY === $this->get_target_type() ) {
			$out['ability'] = $this->ability_slug;
			$out['input']   = $this->ability_input;
		}
		if ( self::TRIGGER_INTERVAL === $this->trigger_type ) {
			$out['interval'] = $this->interval_s;
			$out['stagger']  = $this->stagger_window;
		} else {
			$out['expression'] = $this->expression;
		}
		return $out;
	}
}
