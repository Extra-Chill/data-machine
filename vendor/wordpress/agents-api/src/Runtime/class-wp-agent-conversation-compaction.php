<?php
/**
 * Agent conversation compaction contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes compaction policy and applies transcript compaction safely.
 *
 * This class defines the runtime contract only. Callers provide the summarizer
 * callable, so provider/model execution stays outside Agents API.
 */
class WP_Agent_Conversation_Compaction {

	public const EVENT_STARTED   = 'compaction_started';
	public const EVENT_COMPLETED = 'compaction_completed';
	public const EVENT_FAILED    = 'compaction_failed';
	public const EVENT_ARCHIVED  = 'compaction_overflow_archived';

	public const STATUS_SKIPPED   = 'skipped';
	public const STATUS_COMPACTED = 'compacted';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_ARCHIVED  = 'archived';

	/**
	 * Default declarative compaction policy.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_policy(): array {
		return array(
			'enabled'                      => false,
			'max_messages'                 => 40,
			'recent_messages'              => 12,
			'summary_role'                 => 'system',
			'summary_prefix'               => 'Earlier conversation summary:',
			'summary_model'                => '',
			'summary_provider'             => '',
			'preserve_tool_boundaries'     => true,
			'conservation_enabled'         => false,
			'minimum_conserved_byte_ratio' => 1.0,
			'fail_on_conservation_failure' => true,
			'overflow_archive_enabled'     => false,
			'overflow_threshold_bytes'     => 0,
			'overflow_retained_messages'   => 0,
			'overflow_retained_bytes'      => 0,
			'overflow_archive_pointer'     => array(),
			'overflow_stub_role'           => 'system',
			'overflow_stub_prefix'         => 'Earlier conversation archived without summarization.',
		);
	}

	/**
	 * Normalize caller-provided compaction policy.
	 *
	 * @param array<string, mixed> $policy Raw policy.
	 * @return array<string, mixed>
	 */
	public static function normalize_policy( array $policy ): array {
		$normalized = array_merge( self::default_policy(), $policy );

		$normalized['enabled']                      = (bool) $normalized['enabled'];
		$normalized['max_messages']                 = max( 1, self::normalize_int( $normalized['max_messages'] ) );
		$normalized['recent_messages']              = max( 1, self::normalize_int( $normalized['recent_messages'] ) );
		$normalized['summary_role']                 = self::normalize_string( $normalized['summary_role'], 'system' );
		$normalized['summary_prefix']               = self::normalize_string( $normalized['summary_prefix'], 'Earlier conversation summary:' );
		$normalized['summary_model']                = self::normalize_string( $normalized['summary_model'], '' );
		$normalized['summary_provider']             = self::normalize_string( $normalized['summary_provider'], '' );
		$normalized['preserve_tool_boundaries']     = (bool) $normalized['preserve_tool_boundaries'];
		$normalized['conservation_enabled']         = (bool) $normalized['conservation_enabled'];
		$normalized['minimum_conserved_byte_ratio'] = max( 0.0, self::normalize_float( $normalized['minimum_conserved_byte_ratio'] ) );
		$normalized['fail_on_conservation_failure'] = (bool) $normalized['fail_on_conservation_failure'];
		$normalized['overflow_archive_enabled']     = (bool) $normalized['overflow_archive_enabled'];
		$normalized['overflow_threshold_bytes']     = max( 0, self::normalize_int( $normalized['overflow_threshold_bytes'] ) );
		$normalized['overflow_retained_messages']   = max( 0, self::normalize_int( $normalized['overflow_retained_messages'] ) );
		$normalized['overflow_retained_bytes']      = max( 0, self::normalize_int( $normalized['overflow_retained_bytes'] ) );
		$normalized['overflow_archive_pointer']     = is_array( $normalized['overflow_archive_pointer'] ) ? $normalized['overflow_archive_pointer'] : array();
		$normalized['overflow_stub_role']           = self::normalize_string( $normalized['overflow_stub_role'], 'system' );
		$normalized['overflow_stub_prefix']         = self::normalize_string( $normalized['overflow_stub_prefix'], 'Earlier conversation archived without summarization.' );

		if ( $normalized['recent_messages'] >= $normalized['max_messages'] ) {
			$normalized['recent_messages'] = max( 1, $normalized['max_messages'] - 1 );
		}

		return $normalized;
	}

	/**
	 * Compact a transcript before model dispatch.
	 *
	 * The summarizer receives `(array $messages_to_summarize, array $context)` and
	 * must return a summary string or an array with `summary` and optional
	 * `archived_items`. On failure the original transcript is returned unchanged
	 * with a `compaction_failed` lifecycle event.
	 *
	 * @param array<mixed>    $messages   Complete transcript messages.
	 * @param array<string, mixed> $policy     Compaction policy.
	 * @param callable $summarizer Summary producer supplied by the runtime.
	 * @return array{messages: array<int, array<string, mixed>>, metadata: array<string, mixed>, events: array<int, array<string, mixed>>, archive_items?: array<int, array<string, mixed>>}
	 */
	public static function compact( array $messages, array $policy, callable $summarizer ): array {
		$policy              = self::normalize_policy( $policy );
		$normalized_messages = WP_Agent_Message::normalize_many( $messages );
		$total_messages      = count( $normalized_messages );
		$source_messages     = self::normalize_array_items( $messages );

		if ( self::should_archive_overflow( $source_messages, $policy ) ) {
			return self::archive_overflow( $source_messages, $normalized_messages, $policy );
		}

		$original_stats = self::item_stats( $normalized_messages );

		if ( ! $policy['enabled'] || $total_messages <= $policy['max_messages'] ) {
			return self::result( $normalized_messages, self::STATUS_SKIPPED, self::metadata( $policy, $original_stats, array(), $original_stats ), array() );
		}

		$cutoff = self::select_boundary( $normalized_messages, $policy );
		if ( $cutoff <= 0 ) {
			return self::result( $normalized_messages, self::STATUS_SKIPPED, self::metadata( $policy, $original_stats, array(), $original_stats ), array() );
		}

		$messages_to_summarize = array_slice( $normalized_messages, 0, $cutoff );
		$retained_messages     = array_slice( $normalized_messages, $cutoff );
		$retained_stats        = self::item_stats( $retained_messages );

		$summary_context = array(
			'policy'         => $policy,
			'total_messages' => $total_messages,
			'compact_count'  => $cutoff,
			'retained_count' => count( $retained_messages ),
			'boundary'       => array(
				'compact_until' => $cutoff - 1,
				'retain_from'   => $cutoff,
			),
		);

		$started_event = self::event( self::EVENT_STARTED, $summary_context );

		try {
			$summary_result = call_user_func( $summarizer, $messages_to_summarize, $summary_context );
			$summary        = self::summary_text( $summary_result );
			$archived_items = self::archived_items( $summary_result );

			if ( ! is_string( $summary ) || '' === trim( $summary ) ) {
				throw new \RuntimeException( 'Summary must be a non-empty string.' );
			}
		} catch ( \Throwable $error ) {
			$failure_context          = self::metadata( $policy, $original_stats, array(), $retained_stats, array(), $summary_context );
			$failure_context['error'] = $error->getMessage();

			return self::result(
				$normalized_messages,
				self::STATUS_FAILED,
				$failure_context,
				array( $started_event, self::event( self::EVENT_FAILED, $failure_context ) )
			);
		}

		$summary_message = WP_Agent_Message::text(
			self::normalize_string( $policy['summary_role'] ?? 'system', 'system' ),
			self::normalize_string( $policy['summary_prefix'] ?? 'Earlier conversation summary:', 'Earlier conversation summary:' ) . "\n\n" . trim( $summary ),
			array(
				'agents_api_compaction' => array(
					'compacted_message_count' => $cutoff,
					'retained_message_count'  => $total_messages - $cutoff,
					'summary_provider'        => $policy['summary_provider'],
					'summary_model'           => $policy['summary_model'],
				),
			)
		);

		$compacted_messages                  = array_merge( array( $summary_message ), $retained_messages );
		$compacted_stats                     = self::item_stats( array( $summary_message ) );
		$archived_stats                      = self::item_stats( $archived_items );
		$complete_context                    = self::metadata( $policy, $original_stats, $compacted_stats, $retained_stats, $archived_stats, $summary_context );
		$complete_context['summary_message'] = $summary_message;

		if ( self::conservation_failed( $complete_context ) ) {
			$complete_context['error'] = 'Compaction conservation check failed.';

			return self::result(
				$normalized_messages,
				self::STATUS_FAILED,
				$complete_context,
				array( $started_event, self::event( self::EVENT_FAILED, $complete_context ) )
			);
		}

		return self::result(
			$compacted_messages,
			self::STATUS_COMPACTED,
			$complete_context,
			array( $started_event, self::event( self::EVENT_COMPLETED, $complete_context ) )
		);
	}

	/**
	 * Select the first retained message index without cutting tool boundaries.
	 *
	 * @param array<int, array<string, mixed>> $messages Normalized messages.
	 * @param array<string, mixed>             $policy   Normalized policy.
	 * @return int Boundary index.
	 */
	public static function select_boundary( array $messages, array $policy ): int {
		$policy          = self::normalize_policy( $policy );
		$recent_messages = self::normalize_int( $policy['recent_messages'] ?? 1 );
		$cutoff          = max( 0, count( $messages ) - $recent_messages );

		return $policy['preserve_tool_boundaries'] ? self::move_boundary_to_safe_index( $messages, $cutoff ) : $cutoff;
	}

	/**
	 * Build a normalized result array.
	 *
	 * @param array<int, array<string, mixed>> $messages Messages.
	 * @param string                           $status   Compaction status.
	 * @param array<string, mixed>             $metadata Compaction metadata.
	 * @param array<int, array<string, mixed>> $events   Lifecycle events.
	 * @param array<string, mixed>             $extra    Extra result fields.
	 * @return array{messages: array<int, array<string, mixed>>, metadata: array<string, mixed>, events: array<int, array<string, mixed>>, archive_items?: array<int, array<string, mixed>>}
	 */
	private static function result( array $messages, string $status, array $metadata, array $events, array $extra = array() ): array {
		$metadata['status'] = $status;

		$result = array(
			'messages' => $messages,
			'metadata' => array( 'compaction' => $metadata ),
			'events'   => $events,
		);

		if ( isset( $extra['archive_items'] ) ) {
			$result['archive_items'] = self::normalize_array_items( $extra['archive_items'] );
		}

		return $result;
	}

	/**
	 * Determine whether deterministic overflow archiving should run.
	 *
	 * @param array<int, array<string, mixed>> $messages Source messages.
	 * @param array<string, mixed>             $policy   Normalized policy.
	 * @return bool
	 */
	private static function should_archive_overflow( array $messages, array $policy ): bool {
		return $policy['overflow_archive_enabled']
			&& $policy['overflow_threshold_bytes'] > 0
			&& self::encoded_size( $messages ) > $policy['overflow_threshold_bytes'];
	}

	/**
	 * Deterministically split oversized input into retained messages and archive items.
	 *
	 * @param array<int, array<string, mixed>> $source_messages     Original source messages.
	 * @param array<int, array<string, mixed>> $normalized_messages Normalized messages.
	 * @param array<string, mixed>             $policy              Normalized policy.
	 * @return array{messages: array<int, array<string, mixed>>, metadata: array<string, mixed>, events: array<int, array<string, mixed>>, archive_items?: array<int, array<string, mixed>>}
	 */
	private static function archive_overflow( array $source_messages, array $normalized_messages, array $policy ): array {
		$total_messages = count( $normalized_messages );
		$retain_count   = self::normalize_int( $policy['overflow_retained_messages'] ?? 0 ) > 0 ? self::normalize_int( $policy['overflow_retained_messages'] ?? 0 ) : self::normalize_int( $policy['recent_messages'] ?? 1 );
		$cutoff         = (int) max( 0, $total_messages - $retain_count );

		while ( $policy['overflow_retained_bytes'] > 0 && $cutoff < $total_messages - 1 && self::encoded_size( array_slice( $normalized_messages, $cutoff ) ) > $policy['overflow_retained_bytes'] ) {
			++$cutoff;
		}

		if ( $policy['preserve_tool_boundaries'] ) {
			$cutoff = self::move_boundary_to_safe_index( $normalized_messages, $cutoff );
		}

		if ( $cutoff <= 0 ) {
			return self::result(
				$normalized_messages,
				self::STATUS_SKIPPED,
				array(
					'policy'         => $policy,
					'reason'         => 'overflow_input_unsplittable',
					'total_messages' => $total_messages,
					'total_bytes'    => self::encoded_size( $source_messages ),
				),
				array()
			);
		}

		$archive_items = array_slice( $source_messages, 0, $cutoff );
		$retained      = array_slice( $normalized_messages, $cutoff );
		$archive_id    = 'agents-api-overflow-' . substr( hash( 'sha256', self::encoded_json( $archive_items ) ), 0, 16 );
		$archive_meta  = array(
			'strategy'        => 'deterministic_overflow_archive',
			'archive_id'      => $archive_id,
			'archive_pointer' => $policy['overflow_archive_pointer'],
			'archive_count'   => count( $archive_items ),
			'retained_count'  => count( $retained ),
			'total_messages'  => $total_messages,
			'total_bytes'     => self::encoded_size( $source_messages ),
			'boundary'        => array(
				'archive_until' => $cutoff - 1,
				'retain_from'   => $cutoff,
			),
			'policy'          => $policy,
		);

		$stub_message = WP_Agent_Message::text(
			self::normalize_string( $policy['overflow_stub_role'] ?? 'system', 'system' ),
			self::normalize_string( $policy['overflow_stub_prefix'] ?? 'Earlier conversation archived without summarization.', 'Earlier conversation archived without summarization.' ) . "\n\nArchive ID: " . $archive_id . "\nArchived messages: " . count( $archive_items ),
			array(
				'agents_api_compaction_archive' => $archive_meta,
			)
		);

		return self::result(
			array_merge( array( $stub_message ), $retained ),
			self::STATUS_ARCHIVED,
			$archive_meta,
			array( self::event( self::EVENT_ARCHIVED, $archive_meta ) ),
			array( 'archive_items' => $archive_items )
		);
	}

	/**
	 * Move a split boundary so tool-call/tool-result pairs stay together.
	 *
	 * @param array<int, array<string, mixed>> $messages Normalized messages.
	 * @param int                              $cutoff   Candidate boundary.
	 * @return int Safe boundary.
	 */
	private static function move_boundary_to_safe_index( array $messages, int $cutoff ): int {
		while ( $cutoff > 0 && isset( $messages[ $cutoff ] ) && WP_Agent_Message::TYPE_TOOL_RESULT === WP_Agent_Message::type( $messages[ $cutoff ] ) ) {
			--$cutoff;
		}

		while ( $cutoff > 0 && isset( $messages[ $cutoff - 1 ] ) && WP_Agent_Message::TYPE_TOOL_CALL === WP_Agent_Message::type( $messages[ $cutoff - 1 ] ) ) {
			--$cutoff;
		}

		return $cutoff;
	}

	/**
	 * Return the byte length of the deterministic JSON encoding for data.
	 *
	 * @param mixed $data Data to measure.
	 * @return int Encoded byte count.
	 */
	private static function encoded_size( $data ): int {
		return strlen( self::encoded_json( $data ) );
	}

	/**
	 * Encode data consistently for sizing and deterministic archive IDs.
	 *
	 * @param mixed $data Data to encode.
	 * @return string Encoded JSON.
	 */
	private static function encoded_json( $data ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			$encoded = wp_json_encode( $data );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure-PHP smoke tests run without WordPress loaded.
			$encoded = json_encode( $data );
		}

		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Build generic provenance and conservation metadata.
	 *
	 * @param array<string, mixed> $policy          Normalized policy.
	 * @param array<string, int>   $original_stats  Original item stats.
	 * @param array<string, int>   $compacted_stats Compacted item stats.
	 * @param array<string, int>   $retained_stats  Retained item stats.
	 * @param array<string, int>   $archived_stats  Archived item stats.
	 * @param array<string, mixed> $extra           Extra metadata.
	 * @return array<string, mixed>
	 */
	private static function metadata( array $policy, array $original_stats, array $compacted_stats = array(), array $retained_stats = array(), array $archived_stats = array(), array $extra = array() ): array {
		return WP_Agent_Compaction_Conservation::metadata( $policy, array(), array(), array(), array(), $extra, $original_stats, $compacted_stats, $retained_stats, $archived_stats );
	}

	/**
	 * Determine whether compaction should fail because conservation did not pass.
	 *
	 * @param array<string, mixed> $metadata Compaction metadata.
	 * @return bool
	 */
	private static function conservation_failed( array $metadata ): bool {
		return WP_Agent_Compaction_Conservation::failed_closed( $metadata );
	}

	/**
	 * Extract summary text from the summarizer result.
	 *
	 * @param mixed $summary_result Summarizer result.
	 * @return mixed
	 */
	private static function summary_text( $summary_result ) {
		if ( is_array( $summary_result ) && array_key_exists( 'summary', $summary_result ) ) {
			return $summary_result['summary'];
		}

		return $summary_result;
	}

	/**
	 * Extract optional archived items from the summarizer result.
	 *
	 * @param mixed $summary_result Summarizer result.
	 * @return array<int, mixed>
	 */
	private static function archived_items( $summary_result ): array {
		if ( ! is_array( $summary_result ) || ! is_array( $summary_result['archived_items'] ?? null ) ) {
			return array();
		}

		return array_values( $summary_result['archived_items'] );
	}

	/**
	 * Normalize mixed archive item input to item arrays.
	 *
	 * @param mixed $items Raw items.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_array_items( $items ): array {
		if ( ! is_array( $items ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				$normalized[] = self::normalize_array_item( $item );
			}
		}

		return $normalized;
	}

	/**
	 * Normalize an item array to string keys.
	 *
	 * @param array<mixed> $item Raw item.
	 * @return array<string, mixed>
	 */
	private static function normalize_array_item( array $item ): array {
		$normalized = array();
		foreach ( $item as $key => $value ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $value;
			}
		}

		return $normalized;
	}

	/**
	 * Normalize an integer policy field.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function normalize_int( $value ): int {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) {
			return intval( $value );
		}

		return 0;
	}

	/**
	 * Normalize a floating-point policy field.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	private static function normalize_float( $value ): float {
		if ( is_float( $value ) || is_int( $value ) ) {
			return floatval( $value );
		}

		if ( is_string( $value ) && is_numeric( $value ) ) {
			return floatval( $value );
		}

		return 0.0;
	}

	/**
	 * Count items and content bytes for generic provenance metadata.
	 *
	 * @param array<int, mixed> $items Items.
	 * @return array{item_count: int, byte_count: int}
	 */
	private static function item_stats( array $items ): array {
		return WP_Agent_Compaction_Conservation::item_stats( $items );
	}

	/**
	 * Build a lifecycle event payload for streaming clients.
	 *
	 * @param string               $type Event type.
	 * @param array<string, mixed> $data Event data.
	 * @return array<string, mixed>
	 */
	private static function event( string $type, array $data ): array {
		return array(
			'type'     => $type,
			'metadata' => $data,
		);
	}

	/**
	 * Normalize string policy fields.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Fallback value.
	 * @return string
	 */
	private static function normalize_string( $value, string $fallback ): string {
		if ( ! is_string( $value ) ) {
			return $fallback;
		}

		$value = trim( $value );
		return '' === $value ? $fallback : $value;
	}
}
