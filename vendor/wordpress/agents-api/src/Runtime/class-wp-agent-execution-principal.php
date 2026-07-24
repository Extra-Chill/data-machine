<?php
/**
 * Agent execution principal context.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable identity context for one agent execution.
 *
 * This class records who is acting, which agent is effective for the run, which
 * workspace/client scope applies, and how the request was authenticated. It
 * intentionally does not decide access, grant scoped resources, or persist tokens.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exceptions are not rendered output.
final class WP_Agent_Execution_Principal {

	public const AUTH_SOURCE_USER                 = 'user';
	public const AUTH_SOURCE_APPLICATION_PASSWORD = 'application_password';
	public const AUTH_SOURCE_AGENT_TOKEN          = 'agent_token';
	public const AUTH_SOURCE_AUDIENCE             = 'audience';
	public const AUTH_SOURCE_RUNTIME              = 'runtime';
	public const AUTH_SOURCE_SYSTEM               = 'system';

	public const KNOWN_AUTH_SOURCES = array(
		self::AUTH_SOURCE_USER,
		self::AUTH_SOURCE_APPLICATION_PASSWORD,
		self::AUTH_SOURCE_AGENT_TOKEN,
		self::AUTH_SOURCE_AUDIENCE,
		self::AUTH_SOURCE_RUNTIME,
		self::AUTH_SOURCE_SYSTEM,
	);

	public const OWNER_TYPE_USER     = 'user';
	public const OWNER_TYPE_AUDIENCE = 'audience';
	public const OWNER_TYPE_RUNTIME  = 'runtime';
	public const OWNER_TYPE_TOKEN    = 'token';
	public const OWNER_TYPE_SYSTEM   = 'system';

	public const REQUEST_CONTEXT_REST    = 'rest';
	public const REQUEST_CONTEXT_CLI     = 'cli';
	public const REQUEST_CONTEXT_CRON    = 'cron';
	public const REQUEST_CONTEXT_CHAT    = 'chat';
	public const REQUEST_CONTEXT_RUNTIME = 'runtime';

	public const AUDIENCE_CLAIM_RUNTIME_TYPE = 'runtime_type';

	/**
	 * @param int         $acting_user_id    WordPress user ID on whose behalf the run executes. 0 = system/anonymous context.
	 * @param string      $effective_agent_id Registered agent ID/slug effective for the run.
	 * @param string      $auth_source       Authentication source identifier.
	 * @param string      $request_context   Request context such as rest, cli, cron, or chat.
	 * @param int|null    $token_id          Optional caller-owned token identifier. Agents API does not load or store the token.
	 * @param array<string,mixed>       $request_metadata  JSON-serializable request metadata supplied by the caller.
	 * @param string|null $workspace_id      Optional host workspace/scope identifier.
	 * @param string|null $client_id         Optional client/login identifier.
	 * @param \WP_Agent_Capability_Ceiling|null $capability_ceiling Optional capability ceiling for this execution.
	 * @param \WP_Agent_Caller_Context|null     $caller_context     Optional cross-site caller context claims.
	 * @param string|null                       $audience_id        Optional non-user audience/principal identifier.
	 * @param array<string,mixed>               $audience_claims    Optional host-owned audience claims.
	 * @param string|null                       $owner_type         Optional canonical transcript owner type.
	 * @param string|null                       $owner_key          Optional opaque transcript owner key scoped to the owner type.
	 * @param array<string,mixed>|null          $binding            Optional host-owned cryptographic binding claims.
	 */
	public function __construct(
		public readonly int $acting_user_id,
		public readonly string $effective_agent_id,
		public readonly string $auth_source,
		public readonly string $request_context,
		public readonly ?int $token_id = null,
		public readonly array $request_metadata = array(),
		public readonly ?string $workspace_id = null,
		public readonly ?string $client_id = null,
		public readonly ?\WP_Agent_Capability_Ceiling $capability_ceiling = null,
		public readonly ?\WP_Agent_Caller_Context $caller_context = null,
		public readonly ?string $audience_id = null,
		public readonly array $audience_claims = array(),
		public readonly ?string $owner_type = null,
		public readonly ?string $owner_key = null,
		private readonly ?array $binding = null,
	) {
		if ( $this->acting_user_id < 0 ) {
			throw self::invalid( 'acting_user_id', 'must be zero or a positive integer' );
		}

		if ( '' === $this->effective_agent_id ) {
			throw self::invalid( 'effective_agent_id', 'must be a non-empty string' );
		}

		if ( '' === $this->auth_source ) {
			throw self::invalid( 'auth_source', 'must be a non-empty string' );
		}

		if ( ! self::is_known_auth_source( $this->auth_source ) ) {
			throw self::invalid( 'auth_source', 'must be a known authentication source' );
		}

		if ( '' === $this->request_context ) {
			throw self::invalid( 'request_context', 'must be a non-empty string' );
		}

		if ( null !== $this->token_id && $this->token_id <= 0 ) {
			throw self::invalid( 'token_id', 'must be null or a positive integer' );
		}

		if ( null !== $this->audience_id && '' === trim( $this->audience_id ) ) {
			throw self::invalid( 'audience_id', 'must be null or a non-empty string' );
		}

		if ( false === self::jsonEncode( $this->request_metadata ) ) {
			throw self::invalid( 'request_metadata', 'must be JSON serializable' );
		}

		if ( false === self::jsonEncode( $this->audience_claims ) ) {
			throw self::invalid( 'audience_claims', 'must be JSON serializable' );
		}

		if ( null !== $this->binding && false === self::jsonEncode( $this->binding ) ) {
			throw self::invalid( 'binding', 'must be null or JSON serializable' );
		}

		if ( ( null === $this->owner_type ) !== ( null === $this->owner_key ) ) {
			throw self::invalid( 'owner', 'type and key must both be present or both be null' );
		}

		if ( null !== $this->owner_type && '' === trim( $this->owner_type ) ) {
			throw self::invalid( 'owner_type', 'must be null or a non-empty string' );
		}

		if ( null !== $this->owner_key && '' === trim( $this->owner_key ) ) {
			throw self::invalid( 'owner_key', 'must be null or a non-empty string' );
		}
	}

	/**
	 * Resolve a principal through host-provided request hooks.
	 *
	 * Host plugins can derive principals from REST, CLI, cron, bearer-token, or
	 * user-session state by returning either an WP_Agent_Execution_Principal instance
	 * or a raw principal array from the `agents_api_execution_principal` filter.
	 *
	 * @param array<string, mixed> $request_context Request-specific context for resolvers.
	 * @return self|null Principal when a resolver provides one.
	 */
	public static function resolve( array $request_context = array() ): ?self {
		$principal = null;

		if ( function_exists( 'apply_filters' ) ) {
			$principal = apply_filters( 'agents_api_execution_principal', $principal, $request_context );
		}

		if ( null === $principal || $principal instanceof self ) {
			return $principal;
		}

		if ( is_array( $principal ) ) {
			return self::from_array( self::assoc_array( $principal ) );
		}

		throw self::invalid( 'principal', 'resolver must return null, an array, or an WP_Agent_Execution_Principal' );
	}

	/**
	 * Whether an auth source is known to Agents API or declared by the host.
	 *
	 * @param string $auth_source Authentication source identifier.
	 * @return bool True when the auth source is allowed.
	 */
	public static function is_known_auth_source( string $auth_source ): bool {
		$allowed = self::KNOWN_AUTH_SOURCES;

		if ( function_exists( 'apply_filters' ) ) {
			$allowed = apply_filters( 'wp_agent_known_auth_sources', $allowed );
		}

		return is_array( $allowed ) && in_array( $auth_source, $allowed, true );
	}

	/**
	 * Build a principal from a user-session request shape.
	 *
	 * @param int    $acting_user_id    WordPress user ID.
	 * @param string $effective_agent_id Registered agent ID/slug.
	 * @param string $request_context   Request context.
	 * @param array<string,mixed>  $request_metadata  Request metadata.
	 * @return self
	 */
	public static function user_session( int $acting_user_id, string $effective_agent_id, string $request_context = self::REQUEST_CONTEXT_REST, array $request_metadata = array(), ?string $workspace_id = null, ?string $client_id = null, ?\WP_Agent_Capability_Ceiling $capability_ceiling = null, ?\WP_Agent_Caller_Context $caller_context = null ): self {
		return new self( $acting_user_id, $effective_agent_id, self::AUTH_SOURCE_USER, $request_context, null, $request_metadata, $workspace_id, $client_id, $capability_ceiling, $caller_context );
	}

	/**
	 * Build a principal from a caller-owned agent token shape.
	 *
	 * @param int    $acting_user_id    WordPress user ID represented by the token.
	 * @param string $effective_agent_id Registered agent ID/slug.
	 * @param int    $token_id          Caller-owned token identifier.
	 * @param string $request_context   Request context.
	 * @param array<string,mixed>  $request_metadata  Request metadata.
	 * @return self
	 */
	public static function agent_token( int $acting_user_id, string $effective_agent_id, int $token_id, string $request_context = self::REQUEST_CONTEXT_REST, array $request_metadata = array(), ?string $workspace_id = null, ?string $client_id = null, ?\WP_Agent_Capability_Ceiling $capability_ceiling = null, ?\WP_Agent_Caller_Context $caller_context = null ): self {
		return new self( $acting_user_id, $effective_agent_id, self::AUTH_SOURCE_AGENT_TOKEN, $request_context, $token_id, $request_metadata, $workspace_id, $client_id, $capability_ceiling, $caller_context );
	}

	/**
	 * Build a principal for a non-user audience resolved by the host.
	 *
	 * @param string $audience_id        Host-owned audience identifier.
	 * @param string $effective_agent_id Registered agent ID/slug effective for the run.
	 * @param string $request_context    Request context.
	 * @param array<string,mixed>  $request_metadata   Request metadata.
	 * @param string|null $workspace_id  Optional host workspace/scope identifier.
	 * @param string|null $client_id     Optional client/login identifier.
	 * @param array<string,mixed>  $audience_claims    Host-owned audience claims.
	 * @return self
	 */
	public static function audience( string $audience_id, string $effective_agent_id, string $request_context = self::REQUEST_CONTEXT_REST, array $request_metadata = array(), ?string $workspace_id = null, ?string $client_id = null, array $audience_claims = array(), ?string $owner_key = null ): self {
		return new self( 0, $effective_agent_id, self::AUTH_SOURCE_AUDIENCE, $request_context, null, $request_metadata, $workspace_id, $client_id, null, null, $audience_id, $audience_claims, null !== $owner_key ? self::OWNER_TYPE_AUDIENCE : null, $owner_key );
	}

	/**
	 * Build a principal for a host-attested delegated runtime.
	 *
	 * Delegated runtime principals are non-user principals with an opaque runtime
	 * owner key so transcripts and policy decisions do not bleed into the parent
	 * control plane or another runtime session.
	 *
	 * @param string              $runtime_id        Host-owned runtime/session identifier.
	 * @param string              $effective_agent_id Registered agent ID/slug effective for the run.
	 * @param array<string,mixed> $request_metadata  Request metadata.
	 * @param string|null         $workspace_id      Optional host workspace/scope identifier.
	 * @param string|null         $client_id         Optional client/runtime identifier.
	 * @param array<string,mixed> $audience_claims   Additional host-owned runtime claims.
	 * @param string|null         $owner_key         Optional opaque transcript owner key. Defaults to the runtime id.
	 * @return self
	 */
	public static function runtime( string $runtime_id, string $effective_agent_id, array $request_metadata = array(), ?string $workspace_id = null, ?string $client_id = null, array $audience_claims = array(), ?string $owner_key = null ): self {
		return new self(
			0,
			$effective_agent_id,
			self::AUTH_SOURCE_RUNTIME,
			self::REQUEST_CONTEXT_RUNTIME,
			null,
			$request_metadata,
			$workspace_id,
			$client_id,
			null,
			null,
			$runtime_id,
			$audience_claims,
			self::OWNER_TYPE_RUNTIME,
			$owner_key ?? $runtime_id
		);
	}

	/**
	 * Build a principal from a request/context array.
	 *
	 * @param array<string,mixed> $principal Raw principal fields.
	 * @return self
	 */
	public static function from_array( array $principal ): self {
		$capability_ceiling = null;
		if ( isset( $principal['capability_ceiling'] ) ) {
			if ( $principal['capability_ceiling'] instanceof \WP_Agent_Capability_Ceiling ) {
				$capability_ceiling = $principal['capability_ceiling'];
			} elseif ( is_array( $principal['capability_ceiling'] ) && class_exists( '\WP_Agent_Capability_Ceiling' ) ) {
				$capability_ceiling = \WP_Agent_Capability_Ceiling::from_array( self::assoc_array( $principal['capability_ceiling'] ) );
			}
		}

		$caller_context = null;
		if ( isset( $principal['caller_context'] ) ) {
			if ( $principal['caller_context'] instanceof \WP_Agent_Caller_Context ) {
				$caller_context = $principal['caller_context'];
			} elseif ( is_array( $principal['caller_context'] ) && class_exists( '\WP_Agent_Caller_Context' ) ) {
				$caller_context = \WP_Agent_Caller_Context::from_array( self::assoc_array( $principal['caller_context'] ) );
			}
		}

		return new self(
			self::int_field( $principal, 'acting_user_id' ),
			self::string_field( $principal, 'effective_agent_id' ),
			self::string_field( $principal, 'auth_source' ),
			self::string_field( $principal, 'request_context' ),
			array_key_exists( 'token_id', $principal ) && null !== $principal['token_id'] ? self::int_field( $principal, 'token_id' ) : null,
			self::assoc_array_field( $principal, 'request_metadata' ),
			self::nullable_string_field( $principal, 'workspace_id' ),
			self::nullable_string_field( $principal, 'client_id' ),
			$capability_ceiling,
			$caller_context,
			self::nullable_string_field( $principal, 'audience_id' ),
			self::assoc_array_field( $principal, 'audience_claims' ),
			self::nullable_string_field( $principal, 'owner_type' ),
			self::nullable_string_field( $principal, 'owner_key' ),
			isset( $principal['binding'] ) && is_array( $principal['binding'] ) ? self::assoc_array( $principal['binding'] ) : null
		);
	}

	/**
	 * Export the principal to a stable, JSON-friendly shape.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'acting_user_id'     => $this->acting_user_id,
			'effective_agent_id' => $this->effective_agent_id,
			'auth_source'        => $this->auth_source,
			'request_context'    => $this->request_context,
			'token_id'           => $this->token_id,
			'request_metadata'   => $this->request_metadata,
			'workspace_id'       => $this->workspace_id,
			'client_id'          => $this->client_id,
			'capability_ceiling' => $this->capability_ceiling instanceof \WP_Agent_Capability_Ceiling ? $this->capability_ceiling->to_array() : null,
			'caller_context'     => $this->caller_context instanceof \WP_Agent_Caller_Context ? $this->caller_context->to_array() : null,
			'audience_id'        => $this->audience_id,
			'audience_claims'    => $this->audience_claims,
			'owner_type'         => $this->owner_type,
			'owner_key'          => $this->owner_key,
			'binding'            => $this->binding,
		);
	}

	/**
	 * Export the safe principal metadata shape for citations and diagnostics.
	 *
	 * This intentionally omits request metadata, token ids, audience claims,
	 * capability details, owner keys, and binding claims. Those fields may carry
	 * credentials, opaque session ids, or host-specific authorization material.
	 * Hosts that need richer audit data should persist it in a private audit log
	 * rather than attaching it to user-visible citations or tool diagnostics.
	 *
	 * @return array<string, mixed>
	 */
	public function to_safe_metadata(): array {
		$owner = $this->conversation_owner();

		return array(
			'schema_version'         => 1,
			'effective_agent_id'     => $this->effective_agent_id,
			'auth_source'            => $this->auth_source,
			'request_context'        => $this->request_context,
			'acting_user_id'         => $this->acting_user_id,
			'workspace_id'           => $this->workspace_id,
			'client_id'              => $this->client_id,
			'audience_id'            => $this->audience_id,
			'owner_type'             => is_array( $owner ) ? $owner['type'] : null,
			'has_conversation_owner' => is_array( $owner ),
			'has_capability_ceiling' => $this->capability_ceiling instanceof \WP_Agent_Capability_Ceiling,
			'has_caller_context'     => $this->caller_context instanceof \WP_Agent_Caller_Context,
		);
	}

	/**
	 * Return host-owned cryptographic binding claims for this principal.
	 *
	 * @return array<string,mixed>|null Binding claims, or null when unbound.
	 */
	public function binding(): ?array {
		return $this->binding;
	}

	/**
	 * Return the canonical transcript owner for this principal.
	 *
	 * Runtime authorization and transcript ownership are intentionally separate.
	 * User principals can safely derive ownership from the WordPress user ID. Non-user
	 * principals must provide an opaque owner key resolved by the host, such as a
	 * browser-session key; audience access alone is not a transcript owner.
	 *
	 * @return array{type:string,key:string}|null Principal owner, or null when this principal is not transcript-ownable.
	 */
	public function conversation_owner(): ?array {
		if ( null !== $this->owner_type && null !== $this->owner_key ) {
			return array(
				'type' => $this->owner_type,
				'key'  => $this->owner_key,
			);
		}

		if ( $this->acting_user_id > 0 && self::AUTH_SOURCE_AGENT_TOKEN !== $this->auth_source ) {
			return array(
				'type' => self::OWNER_TYPE_USER,
				'key'  => (string) $this->acting_user_id,
			);
		}

		if ( self::AUTH_SOURCE_AGENT_TOKEN === $this->auth_source && null !== $this->token_id ) {
			return array(
				'type' => self::OWNER_TYPE_TOKEN,
				'key'  => (string) $this->token_id,
			);
		}

		return null;
	}

	/**
	 * Whether this principal represents an autonomous execution.
	 *
	 * Autonomous executions are driven by automation rather than a live human
	 * authorizing each action. The determination delegates to the substrate
	 * autonomous capability policy so hosts can adjust it through the
	 * `agents_api_autonomous_auth_sources` and `agents_api_principal_is_autonomous`
	 * filters.
	 *
	 * @return bool
	 */
	public function is_autonomous_execution(): bool {
		return class_exists( 'WP_Agent_Autonomous_Capability_Policy' )
			&& \WP_Agent_Autonomous_Capability_Policy::is_autonomous( $this );
	}

	/**
	 * Whether this principal represents a host-resolved non-user audience.
	 */
	public function has_audience(): bool {
		return null !== $this->audience_id;
	}

	/**
	 * Return a copy with additional request metadata.
	 *
	 * @param array<string,mixed> $request_metadata Replacement request metadata.
	 * @return self
	 */
	public function with_request_metadata( array $request_metadata ): self {
		return new self(
			$this->acting_user_id,
			$this->effective_agent_id,
			$this->auth_source,
			$this->request_context,
			$this->token_id,
			$request_metadata,
			$this->workspace_id,
			$this->client_id,
			$this->capability_ceiling,
			$this->caller_context,
			$this->audience_id,
			$this->audience_claims,
			$this->owner_type,
			$this->owner_key,
			$this->binding
		);
	}

	/**
	 * Encode JSON without throwing on older PHP configurations.
	 *
	 * @param mixed $value Value to encode.
	 * @return string|false
	 */
	private static function jsonEncode( $value ) {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This pure-PHP value object also runs outside WordPress in smoke tests.
			return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return false;
		}
	}

	/**
	 * @param array<string,mixed> $source Source fields.
	 */
	private static function int_field( array $source, string $key ): int {
		$value = $source[ $key ] ?? null;
		return is_int( $value ) || is_float( $value ) || is_string( $value ) || is_bool( $value ) ? (int) $value : 0;
	}

	/**
	 * @param array<string,mixed> $source Source fields.
	 */
	private static function string_field( array $source, string $key ): string {
		$value = $source[ $key ] ?? null;
		return is_int( $value ) || is_float( $value ) || is_string( $value ) || is_bool( $value ) ? (string) $value : '';
	}

	/**
	 * @param array<string,mixed> $source Source fields.
	 */
	private static function nullable_string_field( array $source, string $key ): ?string {
		if ( ! array_key_exists( $key, $source ) || null === $source[ $key ] ) {
			return null;
		}

		return self::string_field( $source, $key );
	}

	/**
	 * @param array<string,mixed> $source Source fields.
	 * @return array<string,mixed>
	 */
	private static function assoc_array_field( array $source, string $key ): array {
		$value = $source[ $key ] ?? null;
		return is_array( $value ) ? self::assoc_array( $value ) : array();
	}

	/**
	 * @param array<mixed> $value Raw array.
	 * @return array<string,mixed>
	 */
	private static function assoc_array( array $value ): array {
		$assoc = array();
		foreach ( $value as $field => $field_value ) {
			if ( is_string( $field ) ) {
				$assoc[ $field ] = $field_value;
			}
		}

		return $assoc;
	}

	/**
	 * Build a machine-readable validation exception.
	 *
	 * @param string $path Field path.
	 * @param string $reason Failure reason.
	 * @return \InvalidArgumentException Validation exception.
	 */
	private static function invalid( string $path, string $reason ): \InvalidArgumentException {
		return new \InvalidArgumentException( 'invalid_agent_execution_principal: ' . $path . ' ' . $reason );
	}
}
