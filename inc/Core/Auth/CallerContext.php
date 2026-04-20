<?php
/**
 * Caller Context
 *
 * Identifies WHO made the current inbound request and WHERE in a
 * cross-site agent chain it lives. Populated by {@see AgentAuthMiddleware}
 * after bearer-token resolution from `X-Datamachine-Caller-*` and
 * `X-Datamachine-Chain-*` headers, and consulted by any code that cares
 * whether it's being called by an agent on another site vs. a human vs.
 * a pipeline step.
 *
 * The companion to this class is {@see RemoteAgentClient}, which
 * generates the headers on outbound calls so receiving sites can
 * reconstruct a CallerContext symmetrically.
 *
 * Value object. Immutable. Safe to pass around.
 *
 * @package DataMachine\Core\Auth
 * @since 0.71.0
 */

namespace DataMachine\Core\Auth;

defined( 'ABSPATH' ) || exit;

final class CallerContext {

	/**
	 * Header names, canonical casing. Keep in sync with
	 * {@see RemoteAgentClient::buildCallerHeaders()}.
	 */
	public const HEADER_CALLER_SITE  = 'X-Datamachine-Caller-Site';
	public const HEADER_CALLER_AGENT = 'X-Datamachine-Caller-Agent';
	public const HEADER_CHAIN_ID     = 'X-Datamachine-Chain-Id';
	public const HEADER_CHAIN_DEPTH  = 'X-Datamachine-Chain-Depth';

	/**
	 * @param string $caller_site  Fully-qualified host of the calling site.
	 *                             Empty string means "unknown" (e.g. non-A2A call).
	 * @param string $caller_agent Slug of the calling agent on its own site.
	 *                             Empty string means "unknown" or non-agent caller.
	 * @param string $chain_id     Stable UUID correlating all hops of a single A2A chain.
	 *                             Generated fresh if absent from incoming headers.
	 * @param int    $chain_depth  How many A2A hops have already happened in this chain.
	 *                             Zero for a top-of-chain request.
	 */
	public function __construct(
		private string $caller_site = '',
		private string $caller_agent = '',
		private string $chain_id = '',
		private int $chain_depth = 0,
	) {
	}

	/**
	 * Build a CallerContext from an incoming request's headers.
	 *
	 * Missing headers resolve to safe defaults: empty strings for
	 * identity fields, a freshly-generated UUID for chain_id, and zero
	 * for chain_depth. A request without any A2A headers becomes a
	 * valid top-of-chain CallerContext with no caller identity.
	 *
	 * @param array|object|\WP_REST_Request $source Header source. Accepts:
	 *                                              - WP_REST_Request (uses get_header)
	 *                                              - array of headers (case-insensitive lookup)
	 * @return self
	 */
	public static function fromRequest( $source ): self {
		$get = self::header_accessor( $source );

		$site  = (string) $get( self::HEADER_CALLER_SITE );
		$agent = (string) $get( self::HEADER_CALLER_AGENT );
		$chain = (string) $get( self::HEADER_CHAIN_ID );
		$depth = (int) $get( self::HEADER_CHAIN_DEPTH );

		if ( '' === $chain ) {
			$chain = self::generate_chain_id();
		}

		if ( $depth < 0 ) {
			$depth = 0;
		}

		return new self( $site, $agent, $chain, $depth );
	}

	/**
	 * Build a CallerContext for the site's own outbound call.
	 *
	 * Used by {@see RemoteAgentClient} to mint the caller identity for
	 * this request: the calling site's host, the acting agent's slug,
	 * and either the incoming chain's ID+depth (propagating) or a fresh
	 * chain if this is the top of a new chain.
	 *
	 * @param string      $caller_site  This site's host.
	 * @param string      $caller_agent Acting agent slug on this site (empty if unknown).
	 * @param self|null   $inbound      Incoming caller context to propagate from,
	 *                                  or null to start a fresh chain.
	 * @return self
	 */
	public static function forOutbound( string $caller_site, string $caller_agent, ?self $inbound = null ): self {
		$chain_id    = $inbound !== null && '' !== $inbound->chain_id ? $inbound->chain_id : self::generate_chain_id();
		$chain_depth = $inbound !== null ? $inbound->chain_depth + 1 : 1;

		return new self( $caller_site, $caller_agent, $chain_id, $chain_depth );
	}

	/**
	 * Emit headers suitable for wp_remote_request().
	 *
	 * @return array<string,string>
	 */
	public function toOutboundHeaders(): array {
		$headers = array(
			self::HEADER_CHAIN_ID    => $this->chain_id,
			self::HEADER_CHAIN_DEPTH => (string) $this->chain_depth,
		);

		if ( '' !== $this->caller_site ) {
			$headers[ self::HEADER_CALLER_SITE ] = $this->caller_site;
		}

		if ( '' !== $this->caller_agent ) {
			$headers[ self::HEADER_CALLER_AGENT ] = $this->caller_agent;
		}

		return $headers;
	}

	public function callerSite(): string {
		return $this->caller_site;
	}

	public function callerAgent(): string {
		return $this->caller_agent;
	}

	public function chainId(): string {
		return $this->chain_id;
	}

	public function chainDepth(): int {
		return $this->chain_depth;
	}

	/**
	 * Whether this context describes an actual cross-site agent caller.
	 *
	 * Top-of-chain (chain_depth=0, no caller_site) means the request
	 * originated locally (e.g. admin UI, a CLI command, a human
	 * triggering /chat directly). Any depth >= 1 with a caller_site
	 * means it came from another site.
	 */
	public function isCrossSite(): bool {
		return $this->chain_depth >= 1 && '' !== $this->caller_site;
	}

	public function toLogContext(): array {
		return array(
			'caller_site'  => $this->caller_site,
			'caller_agent' => $this->caller_agent,
			'chain_id'     => $this->chain_id,
			'chain_depth'  => $this->chain_depth,
		);
	}

	/**
	 * Build a case-insensitive header accessor over a request-ish value.
	 *
	 * @param array|object|\WP_REST_Request $source
	 * @return callable(string): string
	 */
	private static function header_accessor( $source ): callable {
		if ( $source instanceof \WP_REST_Request ) {
			return function ( string $name ) use ( $source ): string {
				$value = $source->get_header( $name );
				return null === $value ? '' : (string) $value;
			};
		}

		// Normalize array keys to lower-case for case-insensitive lookup.
		$normalized = array();
		if ( is_array( $source ) ) {
			foreach ( $source as $k => $v ) {
				$normalized[ strtolower( (string) $k ) ] = is_array( $v ) ? (string) reset( $v ) : (string) $v;
			}
		}

		return function ( string $name ) use ( $normalized ): string {
			return $normalized[ strtolower( $name ) ] ?? '';
		};
	}

	/**
	 * Generate a chain ID.
	 *
	 * Uses wp_generate_uuid4() when available (WordPress runtime),
	 * falls back to a hex random string for testability in environments
	 * where WordPress functions aren't loaded.
	 */
	private static function generate_chain_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		return bin2hex( random_bytes( 16 ) );
	}
}
