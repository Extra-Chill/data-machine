<?php
/**
 * Event trigger value object.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Triggers;

defined( 'ABSPATH' ) || exit;

/**
 * Code-defined WordPress hook trigger for an agent run.
 */
final class WP_Agent_Event_Trigger {

	private string $id;
	private string $label;
	private string $agent_slug;
	private bool $enabled;
	private string $hook_name;
	/** @var array<int, string> */
	private array $args_shape;
	/** @var array<string, callable|string> */
	private array $placeholders;
	/** @var array<string, mixed> */
	private array $conditions;
	private string $prompt_template;
	private string $session_id;
	/** @var array<string, mixed> */
	private array $meta = array();

	/**
	 * @param string               $id   Unique trigger slug.
	 * @param array<string, mixed> $args Trigger arguments.
	 */
	public function __construct( string $id, array $args ) {
		$id = sanitize_title( $id );
		if ( '' === $id ) {
			throw new \InvalidArgumentException( 'Event trigger id cannot be empty.' );
		}

		$agent = self::string_field( $args['agent'] ?? '' );
		if ( '' === $agent ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Event trigger "%s" must specify an agent slug.', $id ) ) );
		}

		$hook_name = self::string_field( $args['hook_name'] ?? '' );
		if ( '' === $hook_name ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Event trigger "%s" must specify a hook_name.', $id ) ) );
		}

		$this->id              = $id;
		$this->label           = self::string_field( $args['label'] ?? $id );
		$this->agent_slug      = $agent;
		$this->enabled         = isset( $args['enabled'] ) ? (bool) $args['enabled'] : true;
		$this->hook_name       = $hook_name;
		$this->args_shape      = self::normalize_string_list( $args['args_shape'] ?? array() );
		$this->placeholders    = self::normalize_placeholders( $args['placeholders'] ?? array() );
		$this->conditions      = self::normalize_associative_array( $args['conditions'] ?? array() );
		$this->prompt_template = self::string_field( $args['prompt_template'] ?? '' );
		$session_id            = self::string_field( $args['session_id'] ?? '' );
		$this->session_id      = '' !== $session_id
			? $session_id
			: 'event-trigger:' . $id;

		$this->meta = self::normalize_associative_array( $args['meta'] ?? array() );
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

	public function is_enabled(): bool {
		return $this->enabled;
	}

	public function get_hook_name(): string {
		return $this->hook_name;
	}

	/** @return array<int, string> */
	public function get_args_shape(): array {
		return $this->args_shape;
	}

	/** @return array<string, callable|string> */
	public function get_placeholders(): array {
		return $this->placeholders;
	}

	/** @return array<string, mixed> */
	public function get_conditions(): array {
		return $this->conditions;
	}

	public function get_prompt_template(): string {
		return $this->prompt_template;
	}

	public function get_session_id(): string {
		return $this->session_id;
	}

	/** @return array<string, mixed> */
	public function get_meta(): array {
		return $this->meta;
	}

	/**
	 * Project positional hook arguments onto named payload keys.
	 *
	 * @param array<int, mixed> $hook_args Positional WordPress hook arguments.
	 * @return array<string, mixed>
	 */
	public function payload_from_hook_args( array $hook_args ): array {
		$payload = array();
		foreach ( $this->args_shape as $index => $key ) {
			$payload[ $key ] = $hook_args[ $index ] ?? null;
		}
		return $payload;
	}

	/**
	 * Evaluate pre-dispatch conditions against the named payload.
	 *
	 * @param array<string, mixed> $payload Named hook payload.
	 */
	public function conditions_match( array $payload ): bool {
		foreach ( $this->conditions as $key => $expected ) {
			if ( is_callable( $expected ) ) {
				if ( ! (bool) call_user_func( $expected, $payload ) ) {
					return false;
				}
				continue;
			}

			if ( self::read_path( $payload, (string) $key ) !== $expected ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Resolve placeholders and render the prompt template.
	 *
	 * @param array<string, mixed> $payload Named hook payload.
	 */
	public function render_prompt( array $payload ): string {
		$values = array();
		foreach ( $this->placeholders as $name => $extractor ) {
			$value           = is_callable( $extractor )
				? call_user_func( $extractor, $payload )
				: self::read_path( $payload, $extractor );
			$encoded         = is_scalar( $value ) || null === $value ? (string) $value : wp_json_encode( $value );
			$values[ $name ] = is_string( $encoded ) ? $encoded : '';
		}

		$prompt = $this->prompt_template;
		foreach ( $values as $name => $value ) {
			$prompt = str_replace( '{{' . $name . '}}', $value, $prompt );
		}

		return $prompt;
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'label'           => $this->label,
			'agent'           => $this->agent_slug,
			'enabled'         => $this->enabled,
			'hook_name'       => $this->hook_name,
			'args_shape'      => $this->args_shape,
			'prompt_template' => $this->prompt_template,
			'session_id'      => $this->session_id,
			'meta'            => $this->meta,
		);
	}

	/**
	 * @param mixed $values Raw list.
	 * @return array<int, string>
	 */
	private static function normalize_string_list( $values ): array {
		$values     = is_array( $values ) ? $values : array( $values );
		$normalized = array();
		foreach ( $values as $value ) {
			$value = self::string_field( $value );
			if ( '' !== $value ) {
				$normalized[] = $value;
			}
		}

		return $normalized;
	}

	/**
	 * Normalize placeholder extractors to string keys and callable/string values.
	 *
	 * @param mixed $values Raw placeholders.
	 * @return array<string, callable|string>
	 */
	private static function normalize_placeholders( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $values as $key => $value ) {
			if ( ! is_string( $key ) || ( ! is_string( $value ) && ! is_callable( $value ) ) ) {
				continue;
			}

			$normalized[ $key ] = $value;
		}

		return $normalized;
	}

	/**
	 * Normalize an associative array to string keys.
	 *
	 * @param mixed $values Raw array.
	 * @return array<string, mixed>
	 */
	private static function normalize_associative_array( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $values as $key => $value ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $value;
			}
		}

		return $normalized;
	}

	/**
	 * Normalize scalar string fields accepted by trigger definitions.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function string_field( $value ): string {
		if ( is_string( $value ) ) {
			return trim( $value );
		}

		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return trim( strval( $value ) );
		}

		return '';
	}

	/**
	 * Read a dotted path from arrays or objects.
	 *
	 * @param mixed  $source Source value.
	 * @param string $path   Dotted path.
	 * @return mixed
	 */
	private static function read_path( $source, string $path ) {
		if ( '' === $path ) {
			return null;
		}

		$value = $source;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( is_array( $value ) && array_key_exists( $segment, $value ) ) {
				$value = $value[ $segment ];
				continue;
			}

			if ( is_object( $value ) && isset( $value->{$segment} ) ) {
				$value = $value->{$segment};
				continue;
			}

			return null;
		}

		return $value;
	}
}
