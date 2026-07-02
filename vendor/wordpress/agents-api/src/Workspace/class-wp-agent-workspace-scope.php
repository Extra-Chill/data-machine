<?php
/**
 * Agent Workspace Scope
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\Core\Workspace;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable value object for the workspace dimension shared by agent stores.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exceptions are not rendered output.
final class WP_Agent_Workspace_Scope {

	/**
	 * @param string $workspace_type Generic workspace kind (for example site, network, runtime, code_workspace, pull_request).
	 * @param string $workspace_id   Stable workspace identifier within the workspace type.
	 */
	public function __construct(
		public readonly string $workspace_type,
		public readonly string $workspace_id,
	) {
		$this->validate();
	}

	/**
	 * Create a scope from raw values.
	 *
	 * @param string $workspace_type Generic workspace kind.
	 * @param string $workspace_id   Stable workspace identifier.
	 * @return self
	 */
	public static function from_parts( string $workspace_type, string $workspace_id ): self {
		return new self( self::normalize_type( $workspace_type ), self::normalize_id( $workspace_id ) );
	}

	/**
	 * Create a scope from a JSON-friendly array.
	 *
	 * @param array<array-key, mixed> $value Raw workspace scope.
	 * @return self
	 */
	public static function from_array( array $value ): self {
		$type = $value['workspace_type'] ?? '';
		$id   = $value['workspace_id'] ?? '';
		return self::from_parts(
			is_scalar( $type ) ? (string) $type : '',
			is_scalar( $id ) ? (string) $id : ''
		);
	}

	/**
	 * Return a JSON-friendly representation.
	 *
	 * @return array{workspace_type:string, workspace_id:string}
	 */
	public function to_array(): array {
		return array(
			'workspace_type' => $this->workspace_type,
			'workspace_id'   => $this->workspace_id,
		);
	}

	/**
	 * Stable string key for caching / map lookups.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->workspace_type . ':' . $this->workspace_id;
	}

	/**
	 * Normalize a workspace type.
	 *
	 * @param string $workspace_type Raw workspace type.
	 * @return string
	 */
	private static function normalize_type( string $workspace_type ): string {
		return strtolower( trim( $workspace_type ) );
	}

	/**
	 * Normalize a workspace ID.
	 *
	 * @param string $workspace_id Raw workspace ID.
	 * @return string
	 */
	private static function normalize_id( string $workspace_id ): string {
		return trim( $workspace_id );
	}

	/**
	 * Validate the normalized scope.
	 *
	 * @return void
	 */
	private function validate(): void {
		if ( '' === $this->workspace_type ) {
			throw new \InvalidArgumentException( 'invalid_agent_workspace_scope: workspace_type must be non-empty' );
		}

		if ( 1 !== preg_match( '/^[a-z][a-z0-9_-]*$/', $this->workspace_type ) ) {
			throw new \InvalidArgumentException( 'invalid_agent_workspace_scope: workspace_type must be a lowercase slug' );
		}

		if ( '' === $this->workspace_id ) {
			throw new \InvalidArgumentException( 'invalid_agent_workspace_scope: workspace_id must be non-empty' );
		}
	}
}
