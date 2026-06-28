<?php
/**
 * Generic pending approval action value object.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Approvals;

use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a proposed action and its durable resolution audit fields.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exceptions are not rendered output.
class WP_Agent_Pending_Action {

	/**
	 * @var array{action_id: string, kind: string, summary: string, preview: mixed, apply_input: mixed, workspace: array<string, mixed>|null, agent: string|null, creator: string|null, status: string, created_at: string, expires_at: string|null, resolved_at: string|null, resolver: string|null, resolution_result: mixed, resolution_error: string|null, resolution_metadata: array<string, mixed>, metadata: array<string, mixed>}
	 */
	private array $data;

	/**
	 * @param array{action_id: string, kind: string, summary: string, preview: mixed, apply_input: mixed, workspace: array<string, mixed>|null, agent: string|null, creator: string|null, status: string, created_at: string, expires_at: string|null, resolved_at: string|null, resolver: string|null, resolution_result: mixed, resolution_error: string|null, resolution_metadata: array<string, mixed>, metadata: array<string, mixed>} $data Canonical pending action data.
	 */
	private function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Build a pending action from an array shape.
	 *
	 * @param array<string, mixed> $action Raw action data.
	 * @return self
	 */
	public static function from_array( array $action ): self {
		$data = array(
			'action_id'           => self::normalize_string( self::required_value( $action, 'action_id' ), 'action_id' ),
			'kind'                => self::normalize_string( self::required_value( $action, 'kind' ), 'kind' ),
			'summary'             => self::normalize_string( self::required_value( $action, 'summary' ), 'summary' ),
			'preview'             => self::normalize_json_value( self::required_value( $action, 'preview' ), 'preview' ),
			'apply_input'         => self::normalize_json_value( self::required_value( $action, 'apply_input' ), 'apply_input' ),
			'workspace'           => self::normalize_optional_workspace( $action['workspace'] ?? null ),
			'agent'               => self::normalize_optional_string( $action['agent'] ?? null, 'agent' ),
			'creator'             => self::normalize_optional_string( $action['creator'] ?? null, 'creator' ),
			'status'              => self::normalize_status( $action['status'] ?? WP_Agent_Pending_Action_Status::PENDING ),
			'created_at'          => self::normalize_string( self::required_value( $action, 'created_at' ), 'created_at' ),
			'expires_at'          => self::normalize_optional_string( $action['expires_at'] ?? null, 'expires_at' ),
			'resolved_at'         => self::normalize_optional_string( $action['resolved_at'] ?? null, 'resolved_at' ),
			'resolver'            => self::normalize_optional_string( $action['resolver'] ?? null, 'resolver' ),
			'resolution_result'   => self::normalize_json_value( $action['resolution_result'] ?? null, 'resolution_result' ),
			'resolution_error'    => self::normalize_optional_string( $action['resolution_error'] ?? null, 'resolution_error' ),
			'resolution_metadata' => self::normalize_json_array( $action['resolution_metadata'] ?? array(), 'resolution_metadata' ),
			'metadata'            => self::normalize_json_array( $action['metadata'] ?? array(), 'metadata' ),
		);

		self::assert_resolution_audit_is_consistent( $data );

		return new self( $data );
	}

	/**
	 * Convert the action to its canonical public shape.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}

	public function get_action_id(): string {
		return $this->data['action_id'];
	}

	public function get_kind(): string {
		return $this->data['kind'];
	}

	public function get_summary(): string {
		return $this->data['summary'];
	}

	/** @return mixed */
	public function get_preview() {
		return $this->data['preview'];
	}

	/** @return mixed */
	public function get_apply_input() {
		return $this->data['apply_input'];
	}

	public function get_workspace(): ?WP_Agent_Workspace_Scope {
		return null === $this->data['workspace'] ? null : WP_Agent_Workspace_Scope::from_array( $this->data['workspace'] );
	}

	public function get_agent(): ?string {
		return $this->data['agent'];
	}

	public function get_creator(): ?string {
		return $this->data['creator'];
	}

	public function get_status(): string {
		return $this->data['status'];
	}

	public function get_created_at(): string {
		return $this->data['created_at'];
	}

	public function get_expires_at(): ?string {
		return $this->data['expires_at'];
	}

	public function get_resolved_at(): ?string {
		return $this->data['resolved_at'];
	}

	public function get_resolver(): ?string {
		return $this->data['resolver'];
	}

	/** @return mixed */
	public function get_resolution_result() {
		return $this->data['resolution_result'];
	}

	public function get_resolution_error(): ?string {
		return $this->data['resolution_error'];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_resolution_metadata(): array {
		return $this->data['resolution_metadata'];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_metadata(): array {
		return $this->data['metadata'];
	}

	/**
	 * Read a required array value.
	 *
	 * @param array<string, mixed> $source Source data.
	 * @param string               $field  Field name.
	 * @return mixed
	 */
	private static function required_value( array $source, string $field ) {
		if ( ! array_key_exists( $field, $source ) ) {
			throw new \InvalidArgumentException( 'invalid_ai_pending_action: ' . $field . ' is required' );
		}

		return $source[ $field ];
	}

	/**
	 * Normalize a required string field.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $field Field name.
	 * @return string
	 */
	private static function normalize_string( $value, string $field ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			throw new \InvalidArgumentException( 'invalid_ai_pending_action: ' . $field . ' must be a non-empty string' );
		}

		return trim( $value );
	}

	/**
	 * Normalize an optional string field.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $field Field name.
	 * @return string|null
	 */
	private static function normalize_optional_string( $value, string $field ): ?string {
		if ( null === $value ) {
			return null;
		}

		return self::normalize_string( $value, $field );
	}

	/**
	 * Normalize an optional workspace identity.
	 *
	 * @param mixed $value Raw workspace value.
	 * @return array{workspace_type:string,workspace_id:string}|null
	 */
	private static function normalize_optional_workspace( $value ): ?array {
		if ( null === $value ) {
			return null;
		}

		if ( ! is_array( $value ) ) {
			throw new \InvalidArgumentException( 'invalid_ai_pending_action: workspace must be an WP_Agent_Workspace_Scope array' );
		}

		try {
			return WP_Agent_Workspace_Scope::from_array( $value )->to_array();
		} catch ( \InvalidArgumentException $error ) {
			throw new \InvalidArgumentException( 'invalid_ai_pending_action: workspace must include valid workspace_type and workspace_id', 0, $error );
		}
	}

	/**
	 * Normalize a JSON-serializable array.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $field Field name.
	 * @return array<string, mixed>
	 */
	private static function normalize_json_array( $value, string $field ): array {
		if ( ! is_array( $value ) ) {
			throw new \InvalidArgumentException( 'invalid_ai_pending_action: ' . $field . ' must be an array' );
		}

		self::assert_json_serializable( $value, $field );
		/** @var array<string, mixed> $value */
		return $value;
	}

	/**
	 * Normalize any JSON-serializable value.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $field Field name.
	 * @return mixed
	 */
	private static function normalize_json_value( $value, string $field ) {
		self::assert_json_serializable( $value, $field );
		return $value;
	}

	/**
	 * Normalize a pending action status with the value-object error prefix.
	 *
	 * @param mixed $value Raw status.
	 * @return string
	 */
	private static function normalize_status( $value ): string {
		if ( ! is_string( $value ) ) {
			throw new \InvalidArgumentException( 'invalid_ai_pending_action: status must be a string' );
		}

		try {
			return WP_Agent_Pending_Action_Status::normalize( $value );
		} catch ( \InvalidArgumentException $error ) {
			throw new \InvalidArgumentException( 'invalid_ai_pending_action: status must be pending, accepted, rejected, expired, or deleted', 0, $error );
		}
	}

	/**
	 * Terminal statuses must carry resolver and resolved_at audit fields.
	 *
	 * @param array<string,mixed> $data Normalized data.
	 */
	private static function assert_resolution_audit_is_consistent( array $data ): void {
		$status = is_string( $data['status'] ?? null ) ? $data['status'] : '';
		if ( ! WP_Agent_Pending_Action_Status::is_terminal( $status ) ) {
			return;
		}

		if ( null === $data['resolved_at'] ) {
			throw new \InvalidArgumentException( 'invalid_ai_pending_action: resolved_at is required for terminal status' );
		}

		if ( null === $data['resolver'] && WP_Agent_Pending_Action_Status::EXPIRED !== $data['status'] ) {
			throw new \InvalidArgumentException( 'invalid_ai_pending_action: resolver is required for terminal status' );
		}
	}

	/**
	 * Validate JSON serializability with a pure-PHP fallback for smokes.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $field Field name.
	 */
	private static function assert_json_serializable( $value, string $field ): void {
		$encoded = self::json_encode( $value );
		if ( false === $encoded || JSON_ERROR_NONE !== json_last_error() ) {
			throw new \InvalidArgumentException( 'invalid_ai_pending_action: ' . $field . ' must be JSON serializable' );
		}
	}

	/**
	 * Encode data with a WordPress-aware fallback.
	 *
	 * @param mixed $data Data to encode.
	 * @return string|false Encoded JSON or false on failure.
	 */
	private static function json_encode( $data ) {
		if ( function_exists( 'wp_json_encode' ) ) {
			return wp_json_encode( $data );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure-PHP smoke tests run without WordPress loaded.
		return json_encode( $data );
	}
}
