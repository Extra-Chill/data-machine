<?php
/**
 * Abstract base class for agent messaging channels.
 *
 * A "channel" is a transport adapter, registered by a consuming product,
 * that connects an external messaging surface to a chat ability registered
 * through the WordPress Abilities API. The channel handles transport-specific I/O
 * (how to extract a user message from a webhook payload, how to send a reply
 * back) while delegating the actual agent run to the configured chat ability.
 *
 * Two entry points:
 * - {@see receive()}: webhook side. Default schedules an async job; override
 *   for concurrency control (per-conversation lock, debounced drain).
 * - {@see handle()}: job side. Runs the full pipeline:
 *     validate → extract message → look up session → run agent →
 *     persist session → deliver responses → lifecycle hooks.
 *
 * This base class is intentionally agnostic about the agent runtime. The
 * default `run_agent()` calls the chat ability registered under the slug
 * returned by the `wp_agent_channel_chat_ability` filter (default
 * `agents/chat`); override `run_agent()` to plug in a different runtime.
 *
 * Mirrors a similar pattern used internally at WordPress.com to drive
 * a range of agent surfaces. The host-specific orchestration
 * (multi-tenant user resolution, blog switching, internal agent runtime) is
 * intentionally not part of this contract — implementations that need those
 * concerns should add their own subclass layer between this base class and
 * the concrete channel.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Channels;

use WP_Error;

defined( 'ABSPATH' ) || exit;

abstract class WP_Agent_Channel {

	/**
	 * Sentinel error code that {@see validate()} can return to drop a
	 * message silently — no send_error(), no agent invocation. Use for
	 * loop-prevention (own-bot messages bouncing back), allowlist misses,
	 * and well-formed-but-uninteresting webhook events. Anything else
	 * returning a WP_Error from validate() will be reported via send_error.
	 */
	public const SILENT_SKIP_CODE = 'silent_skip';

	/**
	 * The agent slug this channel forwards messages to. Resolved against
	 * the agent registry (`wp_register_agent`) by the chat ability.
	 *
	 * @var string
	 */
	protected string $agent_slug;

	/**
	 * Session ID for conversation continuity, resolved during handle().
	 *
	 * @var string|null
	 */
	protected ?string $session_id = null;

	/**
	 * Delivery outcome: null = no response attempted, 'sent' = delivered,
	 * 'failed' = at least one send_response()/send_error() call failed.
	 * Set by deliver_result(); checked in on_complete() for stats.
	 *
	 * @var string|null
	 */
	protected ?string $response_status = null;

	/**
	 * @param string $agent_slug Slug of the registered agent. Empty string is allowed
	 *                           when the chat ability resolves the target agent itself.
	 */
	public function __construct( string $agent_slug = '' ) {
		$this->agent_slug = $agent_slug;
	}

	// ─── Channel identity (abstract) ───────────────────────────────────

	/**
	 * Stable identifier for the channel type and instance. Used together with
	 * the per-conversation `external_id` to scope sessions across redeploys.
	 *
	 * Convention: `<channel-type>` for single-instance channels;
	 * `<channel-type>_<instance>` when one site runs multiple instances
	 * of the same channel (e.g. `<channel-type>_<bot-or-account>`).
	 *
	 * @return string
	 */
	abstract public function get_external_id_provider(): string;

	/**
	 * The channel-side ID of the conversation — whatever the channel uses
	 * to address a single thread (a conversation id, channel id, or
	 * address). Returning null disables per-conversation isolation.
	 *
	 * @return string|null
	 */
	abstract public function get_external_id(): ?string;

	/**
	 * Human-readable client name used for tracing, attribution, and any
	 * agent-side behavior that varies per channel. Lowercase, hyphenated.
	 *
	 * @return string
	 */
	abstract public function get_client_name(): string;

	// ─── I/O (abstract) ────────────────────────────────────────────────

	/**
	 * Convert the channel-specific webhook payload into the user message
	 * string that gets handed to the agent. Override to extract text from
	 * non-text messages (download images, transcribe voice, follow links).
	 *
	 * @param array<mixed> $data The same map passed to receive() / handle().
	 * @return string The user message to send to the agent.
	 */
	abstract protected function extract_message( array $data ): string;

	/**
	 * Send one assistant response back through the channel. Called once per
	 * assistant message in the agent result (most chat abilities return a
	 * single `reply`, but the contract supports multiple).
	 *
	 * Implementations should throw or log on send failure; the channel will
	 * mark `response_status = 'failed'` if you set it explicitly.
	 *
	 * @param string $text The assistant's response text.
	 */
	abstract protected function send_response( string $text ): void;

	/**
	 * Send an error message back through the channel. Called by the pipeline
	 * when validation, agent execution, or response generation fails.
	 *
	 * @param string $text Error message text.
	 */
	abstract protected function send_error( string $text ): void;

	/**
	 * Send a one-shot notification through the channel. Used for completion
	 * pings from long-running tasks (deep research, scheduled jobs) that
	 * already finished outside this channel's normal request/response loop
	 * and need to deliver a result back to the user.
	 *
	 * Distinct from {@see send_response()} / {@see send_error()} because
	 * those are protected lifecycle hooks bound to handle(); this is a
	 * public, standalone entry point that does not require an active
	 * agent run.
	 *
	 * Default body is a no-op so existing concrete subclasses don't fatal
	 * on "contains 1 abstract method" during a deploy. Subclasses SHOULD
	 * override.
	 *
	 * @param string $title Short title (one line).
	 * @param string $body  Body text. May be multi-line plain text.
	 * @param string $url   Optional URL to include / linkify. Empty for none.
	 */
	public function send_notification( string $title, string $body, string $url = '' ): void {}

	// ─── Async dispatch (abstract) ─────────────────────────────────────

	/**
	 * The action hook fired by `receive()` to schedule the job side. The
	 * subclass must register a handler for this hook that calls `handle()`
	 * with the same payload, e.g.:
	 *
	 *     add_action( 'wp_agent_channel_my_channel', [ $this, 'handle' ] );
	 *
	 * @return string
	 */
	abstract protected function get_job_action(): string;

	// ─── Lifecycle hooks (overridable, default no-ops) ─────────────────

	/**
	 * Validate the channel-specific webhook payload before processing.
	 * Return a WP_Error to short-circuit; null to continue.
	 *
	 * @param array<mixed> $data Per-message data from receive() / handle().
	 * @return WP_Error|null
	 */
	protected function validate( array $data ): ?WP_Error {
		unset( $data );
		return null;
	}

	/** Called before agent execution. Use for typing indicators, reactions. */
	protected function on_processing_start(): void {}

	/** Called after agent execution (success or failure). Use to clean up indicators. */
	protected function on_processing_end(): void {}

	/** Called after responses are delivered. Use for stats, lock release, post-run side effects. */
	protected function on_complete(): void {}

	// ─── Webhook side ──────────────────────────────────────────────────

	/**
	 * Receive an incoming message from the external platform. Default
	 * schedules a single async job via `wp_schedule_single_event`; override
	 * for concurrency control (per-conversation lock + pending queue,
	 * debounced drain, durable persistence).
	 *
	 * @param array<mixed> $data Channel-specific message data passed to the job.
	 */
	public function receive( array $data ): void {
		$action = $this->get_job_action();
		if ( '' === $action ) {
			// No async dispatch configured — run synchronously.
			$this->handle( $data );
			return;
		}
		wp_schedule_single_event( time(), $action, array( $data ) );
	}

	// ─── Job side: full pipeline ───────────────────────────────────────

	/**
	 * Process an incoming message through the full pipeline.
	 *
	 *   validate
	 *     → extract user message
	 *     → look up session_id (if any) for this external_id
	 *     → on_processing_start
	 *     → run_agent (calls the chat ability)
	 *     → on_processing_end
	 *     → persist any new session_id
	 *     → deliver_result (send_response / send_error)
	 *     → on_complete
	 *
	 * @param array<mixed> $data Channel-specific per-message data.
	 * @return array<string,mixed>|WP_Error Agent result or error.
	 */
	public function handle( array $data ): array|WP_Error {
		$error = $this->validate( $data );
		if ( null !== $error ) {
			// 'silent_skip' code = the message is one we deliberately don't
			// react to (loop-prevention drops, allowlist misses, malformed
			// non-chat events). Skip send_error so the user isn't pinged
			// with diagnostic noise.
			if ( self::SILENT_SKIP_CODE !== $error->get_error_code() ) {
				$this->send_error( $error->get_error_message() );
			}
			return $error;
		}

		$message_text = trim( $this->extract_message( $data ) );
		if ( '' === $message_text ) {
			return new WP_Error( 'empty_message', 'No message text to process.' );
		}

		$this->session_id = $this->lookup_session_id();

		$this->on_processing_start();

		try {
			$result = $this->run_agent( $message_text, $data );
		} finally {
			$this->on_processing_end();
		}

		try {
			$this->deliver_result( $result );
		} finally {
			$this->on_complete();
		}

		return $result;
	}

	// ─── Pluggable: agent runner ───────────────────────────────────────

	/**
	 * Run the agent for one user message and return its result.
	 *
	 * Default implementation calls the chat ability registered under the slug
	 * returned by the `wp_agent_channel_chat_ability` filter (default
	 * `agents/chat`). Override to plug in a different runtime — a
	 * direct `wp_ai_client_prompt()` call, an external HTTP service, or a
	 * host-specific agent factory.
	 *
	 * The dispatched payload follows the canonical chat-ability contract
	 * tracked in https://github.com/Automattic/agents-api/issues/100 :
	 *
	 *     {
	 *       agent: string,
	 *       message: string,
	 *       session_id: string|null,
	 *       attachments: array,
	 *       client_context: {
	 *         source, connector_id, client_name, external_provider,
	 *         external_conversation_id, external_message_id, room_kind
	 *       }
	 *     }
	 *
	 * Runtimes that don't read the richer fields ignore them; runtimes that do
	 * get the full transport context without
	 * having to know about WP_Agent_Channel.
	 *
	 * @param string $message_text The user-message string from extract_message().
	 * @param array<mixed>  $data         The original webhook payload, in case the runner
	 *                             needs metadata beyond the text (sender, timestamp).
	 * @return array<string,mixed>|WP_Error `{ session_id, reply, completed?, … }` or WP_Error.
	 */
	protected function run_agent( string $message_text, array $data ): array|WP_Error {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new WP_Error( 'abilities_api_missing', 'Abilities API is not loaded.' );
		}

		/**
		 * Filter the chat ability slug used by channels by default.
		 *
		 * Default is `agents/chat` (the canonical dispatcher registered by
		 * agents-api itself). Override per-channel only when you need to
		 * pin a specific runtime ability.
		 *
		 * @param string $slug       Ability slug. Default 'agents/chat'.
		 * @param string $agent_slug The agent slug being targeted.
		 * @param string $channel    Concrete channel class name.
		 */
		$slug = (string) apply_filters(
			'wp_agent_channel_chat_ability',
			AGENTS_CHAT_ABILITY,
			$this->agent_slug,
			static::class
		);

		$ability = wp_get_ability( $slug );
		if ( ! $ability ) {
			return new WP_Error(
				'chat_ability_unavailable',
				sprintf( 'Chat ability "%s" is not registered.', $slug )
			);
		}

		$result = $ability->execute( $this->build_chat_payload( $message_text, $data ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( is_array( $result ) ) {
			return $this->string_keyed_array( $result );
		}

		return new WP_Error(
			'chat_ability_invalid_result',
			'Chat ability returned an unexpected result type. Abilities must return an array or WP_Error.'
		);
	}

	/**
	 * Build the canonical chat-ability input payload. Public so subclasses,
	 * tests, and external callers can introspect or extend it.
	 *
	 * Override `extract_attachments()`, `extract_external_message_id()`,
	 * `get_room_kind()`, and `client_context_source()` to fill in the
	 * transport-specific bits without rewriting this method.
	 *
	 * @param string $message_text
	 * @param array<mixed>  $data
	 * @return array<string,mixed>
	 */
	public function build_chat_payload( string $message_text, array $data ): array {
		$external_message              = $this->build_external_message( $message_text, $data );
		$client_context                = $external_message->client_context( $this->client_context_source() );
		$client_context['client_name'] = $this->get_client_name();

		return array(
			'agent'          => $this->agent_slug,
			'message'        => $external_message->text,
			'session_id'     => '' === (string) $this->session_id ? null : $this->session_id,
			'attachments'    => $external_message->attachments,
			'client_context' => $client_context,
		);
	}

	/**
	 * Build the normalized external message value for this inbound payload.
	 *
	 * @param string $message_text
	 * @param array<mixed>  $data
	 * @return WP_Agent_External_Message
	 */
	public function build_external_message( string $message_text, array $data ): WP_Agent_External_Message {
		return new WP_Agent_External_Message(
			$message_text,
			$this->get_connector_id(),
			$this->get_external_id_provider(),
			$this->get_external_id(),
			$this->extract_external_message_id( $data ),
			$this->extract_sender_id( $data ),
			false,
			$this->get_room_kind( $data ),
			array_values( $this->extract_attachments( $data ) ),
			$this->string_keyed_array( $data )
		);
	}

	// ─── Pluggable: client_context fields (overridable, default sensible) ─

	/**
	 * Channel attachments lifted from the inbound payload, ready for the
	 * agent runtime. Default is an empty array. Override to pluck images,
	 * voice notes, files, link previews, etc., from the channel-specific
	 * payload shape.
	 *
	 * @param array<mixed> $data
	 * @return array<mixed>
	 */
	protected function extract_attachments( array $data ): array {
		unset( $data );
		return array();
	}

	/**
	 * Connector or channel instance id used for client context and session maps.
	 * Defaults to `get_client_name()`; override when a channel needs a stable
	 * connector id that differs from the human-readable client label.
	 *
	 * @return string
	 */
	protected function get_connector_id(): string {
		return $this->get_client_name();
	}

	/**
	 * Stable transport-side message id, used by the runtime for reply
	 * threading, dedup, and audit. Default null. Override to expose the
	 * inbound `msg_id` from your payload.
	 *
	 * @param array<mixed> $data
	 * @return string|null
	 */
	protected function extract_external_message_id( array $data ): ?string {
		unset( $data );
		return null;
	}

	/**
	 * Opaque external sender id. In DMs this may equal the conversation id;
	 * in group chats it identifies the human sender inside the room.
	 *
	 * @param array<mixed> $data
	 * @return string|null
	 */
	protected function extract_sender_id( array $data ): ?string {
		unset( $data );
		return null;
	}

	/**
	 * Conversation kind: `dm`, `group`, `channel`, or null when unknown.
	 * Override per transport — derive it from whatever metadata the
	 * channel exposes about the conversation (e.g. an address suffix or a
	 * channel/chat type field).
	 *
	 * @param array<mixed> $data
	 * @return string|null
	 */
	protected function get_room_kind( array $data ): ?string {
		unset( $data );
		return null;
	}

	/**
	 * `client_context.source` value. Defaults to `'channel'` for direct
	 * webhook-style transports. Bridge consumers (e.g. an MCP bridge or a
	 * remote-driven A2A flow) override to `'bridge'` so the runtime can
	 * tell apart inbound traffic styles.
	 *
	 * @return string
	 */
	protected function client_context_source(): string {
		return 'channel';
	}

	// ─── Result delivery ───────────────────────────────────────────────

	/**
	 * Extract assistant text from the agent result and dispatch it through
	 * the channel via send_response() / send_error(). Persists any new
	 * session_id returned in the result so the next inbound message
	 * continues the same conversation.
	 *
	 * @param array<string,mixed>|WP_Error $result Agent run result.
	 */
	protected function deliver_result( $result ): void {
		if ( is_wp_error( $result ) ) {
			$this->response_status = 'failed';
			$message               = $result->get_error_message();
			$this->send_error( '' !== $message ? $message : 'Sorry, I could not process your message right now.' );
			return;
		}

		// Persist session continuity before delivering the reply, so a slow
		// send_response() doesn't lose the new session if it errors.
		$result_session_id = $this->optional_string( $result['session_id'] ?? null );
		if ( null !== $result_session_id && $result_session_id !== $this->session_id ) {
			$this->store_session_id( $result_session_id );
		}

		$replies = $this->extract_replies( $result );
		if ( empty( $replies ) ) {
			$this->response_status = 'failed';
			$this->send_error( 'Sorry, I could not generate a response.' );
			return;
		}

		foreach ( $replies as $text ) {
			$this->send_response( $text );
		}
		$this->response_status = 'sent';
	}

	/**
	 * Pull assistant text out of the agent result. Default supports two
	 * shapes: `{ reply: string }` (canonical single-turn) and
	 * `{ messages: [ { role, content } ] }` (multi-message). Override for
	 * exotic result shapes.
	 *
	 * @param array<mixed> $result
	 * @return string[]
	 */
	protected function extract_replies( array $result ): array {
		$reply = $this->optional_string( $result['reply'] ?? null );
		if ( null !== $reply ) {
			return array( $reply );
		}

		if ( ! empty( $result['messages'] ) && is_array( $result['messages'] ) ) {
			$texts = array();
			foreach ( $result['messages'] as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}
				$role = $message['role'] ?? '';
				if ( 'assistant' !== $role ) {
					continue;
				}
				$content = $this->optional_string( $message['content'] ?? null );
				if ( null !== $content ) {
					$texts[] = $content;
				}
			}
			return $texts;
		}

		return array();
	}

	// ─── Session persistence (shared map-backed default) ────────────────

	/**
	 * Storage key for the external_id ↔ session_id mapping. Kept as an
	 * override point for subclasses that predate the shared session map.
	 *
	 * @return string
	 */
	protected function session_storage_key(): string {
		$external_id = $this->get_external_id() ?? '';
		return 'wp_agent_channel_session_' . md5(
			implode( ':', array( $this->get_connector_id(), $external_id, $this->agent_slug ) )
		);
	}

	/**
	 * Read the persisted session_id for this channel + external_id.
	 *
	 * @return string|null
	 */
	protected function lookup_session_id(): ?string {
		$external_id = $this->get_external_id();
		if ( null === $external_id ) {
			return null;
		}

		if ( ! $this->uses_custom_session_storage_key() ) {
			return WP_Agent_Channel_Session_Map::get( $this->get_connector_id(), $external_id, $this->agent_slug );
		}

		$value = get_option( $this->session_storage_key(), '' );
		return $this->optional_string( $value );
	}

	/**
	 * Persist the session_id for this channel + external_id.
	 *
	 * @param string $session_id
	 */
	protected function store_session_id( string $session_id ): void {
		$external_id = $this->get_external_id();
		if ( null === $external_id ) {
			return;
		}

		if ( ! $this->uses_custom_session_storage_key() ) {
			WP_Agent_Channel_Session_Map::set( $this->get_connector_id(), $external_id, $session_id, $this->agent_slug );
			return;
		}

		update_option( $this->session_storage_key(), $session_id, false );
	}

	private function uses_custom_session_storage_key(): bool {
		$method = new \ReflectionMethod( $this, 'session_storage_key' );
		return self::class !== $method->getDeclaringClass()->getName();
	}

	private function optional_string( mixed $value ): ?string {
		if ( ! is_scalar( $value ) && ! $value instanceof \Stringable ) {
			return null;
		}

		$value = trim( (string) $value );
		return '' === $value ? null : $value;
	}

	/**
	 * @param array<mixed> $data
	 * @return array<string,mixed>
	 */
	private function string_keyed_array( array $data ): array {
		$result = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $value;
			}
		}
		return $result;
	}
}
