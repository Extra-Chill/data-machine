<?php
/**
 * Conversation Store Factory
 *
 * Resolves the active {@see ConversationStoreInterface} implementation
 * via the `datamachine_conversation_store` filter, falling back to the
 * built-in {@see Chat} MySQL-table store.
 *
 * Single resolution point so every consumer (ChatOrchestrator, the
 * Chat Session abilities trait, ChatCommand, SystemAbilities) gets the
 * same swap mechanism without duplicating the filter call.
 *
 * Instances are resolved once per request and cached, mirroring how a
 * single `new ChatDatabase()` per callsite behaved before — no behavior
 * change for self-hosted users.
 *
 * @package DataMachine\Core\Database\Chat
 * @since   next
 */

namespace DataMachine\Core\Database\Chat;

defined( 'ABSPATH' ) || exit;

class ConversationStoreFactory {

	/**
	 * Per-request cached store instance.
	 *
	 * @var ConversationStoreInterface|null
	 */
	private static ?ConversationStoreInterface $instance = null;

	/**
	 * Resolve the active conversation store.
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
		 * Filter: swap the chat conversation persistence backend.
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
		 * shape. Implementations that only need a slice of the store surface can
		 * target the narrower contracts, but this filter still expects the full
		 * aggregate for behavior compatibility.
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
	 * Resolve the active transcript store.
	 *
	 * This intentionally reuses the aggregate store/filter resolution so the
	 * existing `datamachine_conversation_store` seam and chat UI callers keep
	 * their current behavior while runtime transcript persistence depends only
	 * on the narrow CRUD contract.
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
