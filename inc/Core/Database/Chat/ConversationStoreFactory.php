<?php
/**
 * Conversation Store Factory
 *
 * Resolves the active Data Machine {@see ConversationStoreInterface}
 * aggregate via the `datamachine_conversation_store` filter, falling back
 * to the built-in {@see Chat} MySQL-table store.
 *
 * Single resolution point so every aggregate consumer (ChatOrchestrator, the
 * Chat Session abilities trait, ChatCommand, SystemAbilities) gets the same
 * swap mechanism without duplicating the filter call. Runtime code that only
 * persists transcripts should use {@see self::get_transcript_store()} to depend
 * on the narrow generic contract instead of the Data Machine chat aggregate.
 *
 * Instances are resolved once per request and cached, mirroring how a
 * single `new ChatDatabase()` per callsite behaved before — no behavior
 * change for self-hosted users.
 *
 * @package DataMachine\Core\Database\Chat
 * @since   next
 */

namespace DataMachine\Core\Database\Chat;

use AgentsAPI\Core\Database\Chat\ConversationTranscriptStoreInterface;

defined( 'ABSPATH' ) || exit;

class ConversationStoreFactory {

	/**
	 * Per-request cached store instance.
	 *
	 * @var ConversationStoreInterface|null
	 */
	private static ?ConversationStoreInterface $instance = null;

	/**
	 * Resolve the active Data Machine aggregate conversation store.
	 *
	 * First call runs the filter and caches the result. Subsequent calls
	 * within the same request return the cached instance. Use
	 * {@see self::reset()} in tests.
	 *
	 * @return ConversationStoreInterface
	 */
	public static function get(): ConversationStoreInterface {
		if ( null !== self::$instance ) {
			return self::$instance;
		}

		$default = new Chat();

		/**
		 * Filter: swap the Data Machine chat conversation persistence backend.
		 *
		 * Return a {@see ConversationStoreInterface} aggregate to short-circuit
		 * the default MySQL-table store. Return the default (or anything not
		 * implementing the aggregate) to use the built-in {@see Chat} store.
		 *
		 * The MySQL default preserves byte-for-byte the behavior Data Machine
		 * had before this seam was introduced — self-hosted users see no
		 * change. Consumers can register an external-backend implementation
		 * conditionally:
		 *
		 *     add_filter( 'datamachine_conversation_store', function ( $store ) {
		 *         if ( $store instanceof My_External_Conversation_Store ) {
		 *             return $store; // already swapped
		 *         }
		 *         if ( ! function_exists( 'my_should_use_external_backend' ) || ! my_should_use_external_backend() ) {
		 *             return $store; // keep MySQL default
		 *         }
		 *         return new My_External_Conversation_Store();
		 *     }, 10, 1 );
		 *
		 * The store MUST normalize messages on read to Data Machine message
		 * shape. Implementations that only need transcript CRUD should target
		 * {@see ConversationTranscriptStoreInterface}; this legacy Data Machine
		 * filter still expects the full aggregate so chat UI, REST, CLI, retention,
		 * and reporting callers keep their existing behavior. A future Agents API
		 * resolver can expose a narrower transcript-store filter without carrying
		 * those Data Machine product responsibilities.
		 *
		 * @since next
		 *
		 * @param ConversationStoreInterface $store Default MySQL-table store.
		 */
		/** @var mixed $resolved */
		$resolved = apply_filters( 'datamachine_conversation_store', $default );

		if ( $resolved instanceof ConversationStoreInterface ) {
			self::$instance = $resolved;
			return self::$instance;
		}

		// Filter returned a non-implementing value. Fall back to default and
		// log the misuse so developers find the bug.
		do_action(
			'datamachine_log',
			'error',
			'datamachine_conversation_store filter returned a non-ConversationStoreInterface value. Falling back to default.',
			array( 'returned_type' => is_object( $resolved ) ? get_class( $resolved ) : gettype( $resolved ) )
		);

		self::$instance = $default;
		return self::$instance;
	}

	/**
	 * Resolve the active generic transcript store.
	 *
	 * This intentionally reuses the aggregate store/filter resolution while the
	 * code lives in Data Machine. The returned type is the extraction boundary:
	 * runtime transcript persistence depends only on CRUD, not Data Machine's
	 * chat session index, read-state, retention, or reporting product surface.
	 *
	 * @return ConversationTranscriptStoreInterface
	 */
	public static function get_transcript_store(): ConversationTranscriptStoreInterface {
		return self::get();
	}

	/**
	 * Reset the cached instance. Test-only.
	 *
	 * Production code MUST NOT call this — it exists so unit tests can
	 * install a fresh filter between test cases.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$instance = null;
	}
}
