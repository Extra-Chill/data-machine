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

	private string $id;
	private string $label;
	private string $agent_slug;
	private string $trigger_type;
	private int $interval_s    = 0;
	private string $expression = '';
	private string $prompt     = '';
	private string $session_id = '';
	/** @var array<string, mixed> */
	private array $meta = array();

	/**
	 * @param string                $id   Unique routine slug.
	 * @param array<string, mixed>  $args Recognised keys: `label` (string),
	 *                                    `agent` (string, required),
	 *                                    `interval` (int seconds) OR
	 *                                    `expression` (cron string),
	 *                                    `prompt` (string), `session_id`
	 *                                    (string), `meta` (array).
	 */
	public function __construct( string $id, array $args ) {
		$id = sanitize_title( $id );
		if ( '' === $id ) {
			throw new \InvalidArgumentException( 'Routine id cannot be empty.' );
		}
		$this->id = $id;

		$this->label = isset( $args['label'] ) && is_scalar( $args['label'] ) ? (string) $args['label'] : $id;

		$agent = isset( $args['agent'] ) && is_scalar( $args['agent'] ) ? (string) $args['agent'] : '';
		if ( '' === $agent ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Routine "%s" must specify an agent slug.', $id ) ) );
		}
		$this->agent_slug = $agent;

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

	public function get_trigger_type(): string {
		return $this->trigger_type;
	}

	public function get_interval_seconds(): int {
		return $this->interval_s;
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
			'agent'      => $this->agent_slug,
			'prompt'     => $this->prompt,
			'session_id' => $this->session_id,
			'meta'       => $this->meta,
		);
		if ( self::TRIGGER_INTERVAL === $this->trigger_type ) {
			$out['interval'] = $this->interval_s;
		} else {
			$out['expression'] = $this->expression;
		}
		return $out;
	}
}
