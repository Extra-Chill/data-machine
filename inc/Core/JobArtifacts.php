<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Data Machine owns custom operational tables and these paths require fresh runtime state or one-time schema mutation.
/**
 * Job artifact payload builder.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Chat\ConversationStoreFactory;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\FilesRepository\AgentMemory as AgentMemoryFile;
use DataMachine\Core\FilesRepository\DailyMemory;
use DataMachine\Engine\AI\MemoryFileRegistry;

defined( 'ABSPATH' ) || exit;

class JobArtifacts {

	private const TRANSCRIPT_ARTIFACT_SCHEMA_VERSION = 1;
	private const TOOL_TRACE_ARTIFACT_SCHEMA_VERSION = 1;
	private const MAX_TRANSCRIPT_MESSAGES            = 200;
	private const MAX_TRANSCRIPT_CONTENT_CHARS       = 4000;
	private const MAX_TOOL_TRACE_ENTRIES             = 200;
	private const MAX_TOOL_TRACE_FIELD_CHARS         = 4000;

	/**
	 * Build a deterministic artifact payload for a job.
	 *
	 * @param int   $job_id                    Job ID.
	 * @param array $additional_tool_summaries Tool summaries accumulated before engine_data persistence.
	 * @return array{success: bool, artifacts?: array, error?: string}
	 */
	public function get( int $job_id, array $additional_tool_summaries = array() ): array {
		if ( $job_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'job_id must be a positive integer.',
			);
		}

		$job = ( new Jobs() )->get_job( $job_id );
		if ( ! $job ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Job %d not found.', $job_id ),
			);
		}

		$engine_data         = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		$agent               = $this->resolve_agent( $job, $engine_data );
		$tool_calls          = array_merge(
			$this->successful_tool_summaries( $engine_data ),
			$this->successful_tool_summaries_from_list( $additional_tool_summaries )
		);
		$transcript_artifact = $this->transcript_artifact( $job_id, $job, $agent, $engine_data );
		$tool_trace_artifact = $this->tool_trace_artifact( $job_id, $job, $agent, $engine_data, $additional_tool_summaries );
		$payload             = array(
			'job_id'                 => $job_id,
			'status'                 => (string) ( $job['status'] ?? '' ),
			'agent_id'               => $agent['agent_id'],
			'agent_slug'             => $agent['agent_slug'],
			'disposition_diagnostic' => $this->disposition_diagnostic( $engine_data ),
			'required_tool_names'    => $this->tool_names_from_assertions( $engine_data['completion_assertions_required'] ?? array() ),
			'satisfied_tool_names'   => $this->tool_names_from_assertions( $engine_data['completion_assertions_satisfied'] ?? array() ),
			'successful_tool_calls'  => $tool_calls,
			'tool_trace'             => $this->tool_trace( $engine_data, $additional_tool_summaries ),
			'transcript'             => $this->transcript_metadata( $job_id, $engine_data ),
			'artifact_refs'          => $this->hashable_artifact_refs( $transcript_artifact, $tool_trace_artifact ),
			'transcript_artifact'    => $transcript_artifact,
			'tool_trace_artifact'    => $tool_trace_artifact,
			'agent_memory_artifacts' => $this->agent_memory_artifacts( $job, $agent, $tool_calls ),
			'daily_memory_artifacts' => $this->daily_memory_artifacts( $job, $agent, $tool_calls ),
		);

		return array(
			'success'   => true,
			'artifacts' => $payload,
		);
	}

	/**
	 * @return array{agent_id: int|null, agent_slug: string|null}
	 */
	private function resolve_agent( array $job, array $engine_data ): array {
		$agent_id   = (int) ( $job['agent_id'] ?? $engine_data['agent_id'] ?? 0 );
		$agent_slug = sanitize_title( (string) ( $engine_data['agent_slug'] ?? $job['agent_slug'] ?? '' ) );

		if ( $agent_id > 0 && '' === $agent_slug ) {
			$agent = ( new Agents() )->get_agent( $agent_id );
			if ( $agent ) {
				$agent_slug = sanitize_title( (string) ( $agent['agent_slug'] ?? '' ) );
			}
		}

		return array(
			'agent_id'   => $agent_id > 0 ? $agent_id : null,
			'agent_slug' => '' !== $agent_slug ? $agent_slug : null,
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function successful_tool_summaries( array $engine_data ): array {
		$summaries = is_array( $engine_data['tool_execution_summary'] ?? null ) ? $engine_data['tool_execution_summary'] : array();
		return $this->successful_tool_summaries_from_list( $summaries );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function successful_tool_summaries_from_list( array $summaries ): array {
		$successes = array();

		foreach ( $summaries as $summary ) {
			if ( ! is_array( $summary ) || true !== ( $summary['success'] ?? false ) ) {
				continue;
			}

			$successes[] = array_filter(
				array(
					'tool_name'  => sanitize_key( (string) ( $summary['tool_name'] ?? '' ) ),
					'success'    => true,
					'turn_count' => isset( $summary['turn_count'] ) ? (int) $summary['turn_count'] : null,
					'user_id'    => isset( $summary['user_id'] ) ? (int) $summary['user_id'] : null,
					'agent_id'   => isset( $summary['agent_id'] ) ? (int) $summary['agent_id'] : null,
					'action'     => isset( $summary['action'] ) ? sanitize_key( (string) $summary['action'] ) : null,
					'date'       => isset( $summary['date'] ) ? sanitize_text_field( (string) $summary['date'] ) : null,
					'mode'       => isset( $summary['mode'] ) ? sanitize_key( (string) $summary['mode'] ) : null,
					'file'       => isset( $summary['file'] ) ? sanitize_file_name( (string) $summary['file'] ) : null,
					'section'    => isset( $summary['section'] ) ? sanitize_text_field( (string) $summary['section'] ) : null,
					'content'    => isset( $summary['content'] ) ? (string) $summary['content'] : null,
					'summary'    => isset( $summary['summary'] ) ? sanitize_text_field( (string) $summary['summary'] ) : null,
				),
				static fn( $value ) => null !== $value && '' !== $value
			);
		}

		return $successes;
	}

	/**
	 * Return generic redacted tool trace entries for all tool executions.
	 *
	 * @param array $engine_data               Job engine data.
	 * @param array $additional_tool_summaries In-flight summaries supplied by a running loop.
	 * @return array<int,array<string,mixed>>
	 */
	private function tool_trace( array $engine_data, array $additional_tool_summaries ): array {
		$trace     = array();
		$summaries = is_array( $engine_data['tool_execution_summary'] ?? null ) ? $engine_data['tool_execution_summary'] : array();
		foreach ( array_merge( $summaries, $additional_tool_summaries ) as $summary ) {
			if ( is_array( $summary ) && is_array( $summary['trace'] ?? null ) ) {
				$trace[] = $summary['trace'];
			}
		}

		return $trace;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function hashable_artifact_refs( ?array $transcript_artifact, ?array $tool_trace_artifact ): array {
		$refs = array();
		foreach (
			array(
				'transcript' => $transcript_artifact,
				'tool_trace' => $tool_trace_artifact,
			) as $key => $artifact
		) {
			if ( ! is_array( $artifact ) ) {
				continue;
			}

			$refs[ $key ] = array(
				'artifact_ref'   => $artifact['artifact_ref'],
				'artifact_type'  => $artifact['artifact_type'],
				'schema_version' => $artifact['schema_version'],
				'sha256'         => $artifact['sha256'],
				'bounded'        => $artifact['bounded'],
				'redacted'       => $artifact['redacted'],
			);
		}

		return $refs;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function transcript_artifact( int $job_id, array $job, array $agent, array $engine_data ): ?array {
		$session_id = (string) ( $engine_data['transcript_session_id'] ?? '' );
		if ( '' === $session_id ) {
			$session_id = $this->find_transcript_session_id_for_job( $job_id );
		}

		if ( '' === $session_id ) {
			return null;
		}

		$session = ConversationStoreFactory::get()->get_session( $session_id );
		if ( ! $session ) {
			$payload = array(
				'schema_version' => self::TRANSCRIPT_ARTIFACT_SCHEMA_VERSION,
				'artifact_type'  => 'transcript',
				'artifact_ref'   => $this->artifact_ref( $job_id, 'transcript', $session_id ),
				'source'         => $this->artifact_source( $job_id, $job, $agent, array( 'session_id' => $session_id ) ),
				'missing'        => true,
				'bounded'        => true,
				'redacted'       => true,
			);

			return $this->with_payload_hash( $payload );
		}

		$messages = is_array( $session['messages'] ?? null ) ? array_values( $session['messages'] ) : array();
		$metadata = is_array( $session['metadata'] ?? null ) ? $session['metadata'] : array();
		$entries  = array();
		foreach ( array_slice( $messages, 0, self::MAX_TRANSCRIPT_MESSAGES ) as $index => $message ) {
			if ( is_array( $message ) ) {
				$entries[] = $this->normalize_transcript_message( $message, $index );
			}
		}

		$payload = array(
			'schema_version'   => self::TRANSCRIPT_ARTIFACT_SCHEMA_VERSION,
			'artifact_type'    => 'transcript',
			'artifact_ref'     => $this->artifact_ref( $job_id, 'transcript', $session_id ),
			'source'           => $this->artifact_source(
				$job_id,
				$job,
				$agent,
				array(
					'session_id' => $session_id,
					'provider'   => $session['provider'] ?? ( $metadata['provider'] ?? null ),
					'model'      => $session['model'] ?? ( $metadata['model'] ?? null ),
					'mode'       => $session['mode'] ?? null,
				)
			),
			'message_count'    => count( $messages ),
			'messages_emitted' => count( $entries ),
			'messages_omitted' => max( 0, count( $messages ) - count( $entries ) ),
			'entries'          => $entries,
			'usage'            => is_array( $metadata['usage'] ?? null ) ? $this->redact_and_bound_value( $metadata['usage'] ) : null,
			'completed'        => isset( $metadata['completed'] ) ? (bool) $metadata['completed'] : null,
			'bounded'          => true,
			'redacted'         => true,
		);

		return $this->with_payload_hash( $this->filter_empty( $payload ) );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function tool_trace_artifact( int $job_id, array $job, array $agent, array $engine_data, array $additional_tool_summaries ): ?array {
		$trace = $this->tool_trace( $engine_data, $additional_tool_summaries );
		if ( empty( $trace ) ) {
			return null;
		}

		$entries = array();
		foreach ( array_slice( $trace, 0, self::MAX_TOOL_TRACE_ENTRIES ) as $index => $entry ) {
			if ( is_array( $entry ) ) {
				$entries[] = $this->normalize_tool_trace_entry( $entry, $index );
			}
		}

		$payload = array(
			'schema_version'  => self::TOOL_TRACE_ARTIFACT_SCHEMA_VERSION,
			'artifact_type'   => 'tool_trace',
			'artifact_ref'    => $this->artifact_ref( $job_id, 'tool-trace' ),
			'source'          => $this->artifact_source( $job_id, $job, $agent ),
			'trace_count'     => count( $trace ),
			'entries_emitted' => count( $entries ),
			'entries_omitted' => max( 0, count( $trace ) - count( $entries ) ),
			'entries'         => $entries,
			'bounded'         => true,
			'redacted'        => true,
		);

		return $this->with_payload_hash( $payload );
	}

	/** @return array<string,mixed> */
	private function normalize_transcript_message( array $message, int $index ): array {
		$content        = $message['content'] ?? '';
		$content_hash   = $this->hash_payload( $content );
		$content_output = $this->redact_and_bound_value( $content, self::MAX_TRANSCRIPT_CONTENT_CHARS );
		$entry          = array(
			'index'          => $index,
			'role'           => isset( $message['role'] ) ? sanitize_key( (string) $message['role'] ) : null,
			'actor'          => isset( $message['actor'] ) ? sanitize_key( (string) $message['actor'] ) : null,
			'source'         => isset( $message['source'] ) ? sanitize_key( (string) $message['source'] ) : null,
			'tool_call_id'   => isset( $message['tool_call_id'] ) ? sanitize_text_field( (string) $message['tool_call_id'] ) : null,
			'name'           => isset( $message['name'] ) ? sanitize_text_field( (string) $message['name'] ) : null,
			'created_at'     => isset( $message['created_at'] ) ? sanitize_text_field( (string) $message['created_at'] ) : null,
			'content_sha256' => $content_hash,
			'content'        => $content_output,
			'tool_calls'     => $this->normalize_transcript_tool_calls( $message['tool_calls'] ?? array() ),
			'metadata'       => is_array( $message['metadata'] ?? null ) ? $this->redact_and_bound_value( $message['metadata'], self::MAX_TOOL_TRACE_FIELD_CHARS ) : null,
		);

		return $this->filter_empty( $entry );
	}

	/** @return array<int,array<string,mixed>> */
	private function normalize_transcript_tool_calls( mixed $tool_calls ): array {
		if ( ! is_array( $tool_calls ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $tool_calls as $index => $tool_call ) {
			if ( ! is_array( $tool_call ) ) {
				continue;
			}

			$function  = is_array( $tool_call['function'] ?? null ) ? $tool_call['function'] : array();
			$arguments = $function['arguments'] ?? ( $tool_call['arguments'] ?? null );
			if ( is_string( $arguments ) ) {
				$decoded = json_decode( $arguments, true );
				if ( is_array( $decoded ) ) {
					$arguments = $decoded;
				}
			}

			$normalized[] = $this->filter_empty(
				array(
					'index'              => is_int( $index ) ? $index : count( $normalized ),
					'id'                 => isset( $tool_call['id'] ) ? sanitize_text_field( (string) $tool_call['id'] ) : null,
					'type'               => isset( $tool_call['type'] ) ? sanitize_key( (string) $tool_call['type'] ) : null,
					'name'               => isset( $function['name'] ) ? sanitize_text_field( (string) $function['name'] ) : ( isset( $tool_call['name'] ) ? sanitize_text_field( (string) $tool_call['name'] ) : null ),
					'arguments_sha256'   => null !== $arguments ? $this->hash_payload( $arguments ) : null,
					'arguments_redacted' => null !== $arguments ? $this->redact_and_bound_value( $arguments, self::MAX_TOOL_TRACE_FIELD_CHARS ) : null,
				)
			);
		}

		return $normalized;
	}

	/** @return array<string,mixed> */
	private function normalize_tool_trace_entry( array $entry, int $index ): array {
		$normalized = $this->filter_empty(
			array(
				'index'              => $index,
				'schema_version'     => isset( $entry['schema_version'] ) ? (int) $entry['schema_version'] : null,
				'tool_name'          => isset( $entry['tool_name'] ) ? sanitize_key( (string) $entry['tool_name'] ) : null,
				'tool_call_id'       => isset( $entry['tool_call_id'] ) ? sanitize_text_field( (string) $entry['tool_call_id'] ) : null,
				'turn_count'         => isset( $entry['turn_count'] ) ? (int) $entry['turn_count'] : null,
				'actor'              => isset( $entry['actor'] ) ? sanitize_key( (string) $entry['actor'] ) : null,
				'source'             => isset( $entry['source'] ) ? sanitize_key( (string) $entry['source'] ) : null,
				'status'             => isset( $entry['status'] ) ? sanitize_key( (string) $entry['status'] ) : null,
				'started_at'         => isset( $entry['started_at'] ) ? sanitize_text_field( (string) $entry['started_at'] ) : null,
				'ended_at'           => isset( $entry['ended_at'] ) ? sanitize_text_field( (string) $entry['ended_at'] ) : null,
				'duration_ms'        => isset( $entry['duration_ms'] ) ? (int) $entry['duration_ms'] : null,
				'arguments_sha256'   => isset( $entry['arguments_sha256'] ) ? sanitize_text_field( (string) $entry['arguments_sha256'] ) : null,
				'result_sha256'      => isset( $entry['result_sha256'] ) ? sanitize_text_field( (string) $entry['result_sha256'] ) : null,
				'output_summary'     => isset( $entry['output_summary'] ) ? $this->bound_string( sanitize_text_field( (string) $entry['output_summary'] ), 500 ) : null,
				'artifact_refs'      => is_array( $entry['artifact_refs'] ?? null ) ? $this->redact_and_bound_value( $entry['artifact_refs'], self::MAX_TOOL_TRACE_FIELD_CHARS ) : null,
				'arguments_redacted' => isset( $entry['arguments_redacted'] ) ? $this->redact_and_bound_value( $entry['arguments_redacted'], self::MAX_TOOL_TRACE_FIELD_CHARS ) : null,
				'arguments_omitted'  => isset( $entry['arguments_omitted'] ) ? sanitize_key( (string) $entry['arguments_omitted'] ) : null,
				'metadata'           => is_array( $entry['metadata'] ?? null ) ? $this->redact_and_bound_value( $entry['metadata'], self::MAX_TOOL_TRACE_FIELD_CHARS ) : null,
			)
		);

		$normalized['entry_sha256'] = $this->hash_payload( $normalized );
		return $normalized;
	}

	/** @return array<string,mixed> */
	private function artifact_source( int $job_id, array $job, array $agent, array $extra = array() ): array {
		return $this->filter_empty(
			array_merge(
				array(
					'job_id'       => $job_id,
					'job_status'   => isset( $job['status'] ) ? (string) $job['status'] : null,
					'agent_id'     => $agent['agent_id'],
					'agent_slug'   => $agent['agent_slug'],
					'user_id'      => isset( $job['user_id'] ) ? (int) $job['user_id'] : null,
					'pipeline_id'  => isset( $job['pipeline_id'] ) ? (int) $job['pipeline_id'] : null,
					'flow_id'      => isset( $job['flow_id'] ) ? (int) $job['flow_id'] : null,
					'flow_step_id' => isset( $job['flow_step_id'] ) ? (int) $job['flow_step_id'] : null,
					'created_at'   => isset( $job['created_at'] ) ? sanitize_text_field( (string) $job['created_at'] ) : null,
					'completed_at' => isset( $job['completed_at'] ) ? sanitize_text_field( (string) $job['completed_at'] ) : null,
				),
				$extra
			)
		);
	}

	private function artifact_ref( int $job_id, string $artifact_type, string $qualifier = '' ): string {
		$ref = 'datamachine://jobs/' . $job_id . '/artifacts/' . sanitize_key( $artifact_type );
		if ( '' !== $qualifier ) {
			$ref .= '/' . rawurlencode( $qualifier );
		}

		return $ref;
	}

	/** @return array<string,mixed> */
	private function with_payload_hash( array $payload ): array {
		$payload['sha256'] = $this->hash_payload( $payload );
		return $payload;
	}

	private function hash_payload( mixed $payload ): string {
		$json = wp_json_encode( $this->canonicalize( $payload ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return hash( 'sha256', (string) $json );
	}

	private function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value, SORT_STRING );
		}

		foreach ( $value as $key => $child ) {
			$value[ $key ] = $this->canonicalize( $child );
		}

		return $value;
	}

	private function redact_and_bound_value( mixed $value, int $max_string_chars = self::MAX_TOOL_TRACE_FIELD_CHARS ): mixed {
		if ( is_array( $value ) ) {
			$redacted = array();
			foreach ( $value as $key => $child ) {
				$key_string = (string) $key;
				if ( preg_match( '/(api[_-]?key|auth|bearer|cookie|credential|nonce|password|secret|signature|token)/i', $key_string ) ) {
					$redacted[ $key ] = '[redacted]';
					continue;
				}

				$redacted[ $key ] = $this->redact_and_bound_value( $child, $max_string_chars );
			}

			return $redacted;
		}

		if ( is_string( $value ) ) {
			$redacted = preg_replace( '/Bearer\s+[A-Za-z0-9._~+\/\-]+=*/i', 'Bearer [redacted]', $value );
			$redacted = preg_replace( '/\b(api[_-]?key|token|secret|password)\b\s*[:=]\s*\S+/i', '$1: [redacted]', $redacted ?? $value );
			return $this->bound_string( $redacted ?? $value, $max_string_chars );
		}

		return $value;
	}

	private function bound_string( string $value, int $max_chars ): string {
		if ( strlen( $value ) <= $max_chars ) {
			return $value;
		}

		return substr( $value, 0, max( 0, $max_chars - 3 ) ) . '...';
	}

	/** @return array<string,mixed> */
	private function filter_empty( array $value ): array {
		return array_filter(
			$value,
			static fn( $child ) => null !== $child && '' !== $child && array() !== $child
		);
	}

	/**
	 * @return array<int, string>
	 */
	private function tool_names_from_assertions( $assertions ): array {
		if ( ! is_array( $assertions ) ) {
			return array();
		}

		$tool_names = $assertions['tool_names'] ?? array();
		if ( is_string( $tool_names ) ) {
			$tool_names = array( $tool_names );
		}
		if ( ! is_array( $tool_names ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter(
					array_map( static fn( $name ) => sanitize_key( (string) $name ), $tool_names )
				)
			)
		);
	}

	/**
	 * Return bounded source/disposition evidence persisted by pipeline tools.
	 *
	 * @param array<string,mixed> $engine_data Job engine data.
	 * @return array<string,mixed>|null
	 */
	private function disposition_diagnostic( array $engine_data ): ?array {
		$diagnostic = $engine_data['disposition_diagnostic'] ?? null;
		if ( ! is_array( $diagnostic ) ) {
			$source_rejection = $engine_data['source_rejection'] ?? array();
			$item_deferral    = $engine_data['item_deferral'] ?? array();
			if ( is_array( $source_rejection ) && is_array( $source_rejection['diagnostic'] ?? null ) ) {
				$diagnostic = $source_rejection['diagnostic'];
			} elseif ( is_array( $item_deferral ) && is_array( $item_deferral['diagnostic'] ?? null ) ) {
				$diagnostic = $item_deferral['diagnostic'];
			}
		}

		if ( ! is_array( $diagnostic ) ) {
			return null;
		}

		$fields = array(
			'type',
			'disposition',
			'reason',
			'tool_name',
			'item_identifier',
			'source_url',
			'provider',
			'source_type',
			'flow_step_id',
			'packet_count',
			'excerpt',
			'excerpt_chars',
			'excerpt_limit',
			'truncated',
			'diagnostic',
		);

		$out = array();
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $diagnostic ) ) {
				$out[ $field ] = $diagnostic[ $field ];
			}
		}

		return empty( $out ) ? null : $out;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function transcript_metadata( int $job_id, array $engine_data ): ?array {
		$session_id = (string) ( $engine_data['transcript_session_id'] ?? '' );
		if ( '' === $session_id ) {
			$session_id = $this->find_transcript_session_id_for_job( $job_id );
		}

		if ( '' === $session_id ) {
			return null;
		}

		$session = ConversationStoreFactory::get()->get_session( $session_id );
		if ( ! $session ) {
			return array(
				'session_id' => $session_id,
				'missing'    => true,
			);
		}

		$messages = is_array( $session['messages'] ?? null ) ? $session['messages'] : array();
		$metadata = is_array( $session['metadata'] ?? null ) ? $session['metadata'] : array();

		return array_filter(
			array(
				'session_id'    => $session_id,
				'provider'      => $session['provider'] ?? ( $metadata['provider'] ?? null ),
				'model'         => $session['model'] ?? ( $metadata['model'] ?? null ),
				'mode'          => $session['mode'] ?? null,
				'message_count' => count( $messages ),
				'turn_count'    => isset( $metadata['turn_count'] ) ? (int) $metadata['turn_count'] : null,
				'completed'     => isset( $metadata['completed'] ) ? (bool) $metadata['completed'] : null,
				'usage'         => is_array( $metadata['usage'] ?? null ) ? $metadata['usage'] : null,
			),
			static fn( $value ) => null !== $value && array() !== $value
		);
	}

	private function find_transcript_session_id_for_job( int $job_id ): string {
		global $wpdb;

		$table = $wpdb->prefix . 'datamachine_chat_sessions';
		$like  = '%"job_id":' . $job_id . '%';
		$row   = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT session_id FROM %i WHERE mode = %s AND metadata LIKE %s ORDER BY created_at DESC LIMIT 1',
				$table,
				'pipeline',
				$like
			)
		);

		return is_string( $row ) ? $row : '';
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function agent_memory_artifacts( array $job, array $agent, array $tool_calls ): array {
		$default_agent_id = (int) ( $agent['agent_id'] ?? 0 );
		$files            = array();

		foreach ( $tool_calls as $tool_call ) {
			if ( 'agent_memory' !== ( $tool_call['tool_name'] ?? '' ) || 'update' !== ( $tool_call['action'] ?? '' ) ) {
				continue;
			}

			$filename = sanitize_file_name( (string) ( $tool_call['file'] ?? 'MEMORY.md' ) );
			if ( '' === $filename ) {
				continue;
			}

			$agent_id = (int) ( $tool_call['agent_id'] ?? 0 );
			if ( $agent_id <= 0 && $default_agent_id > 0 ) {
				$agent_id = $default_agent_id;
			}
			$user_id = (int) ( $tool_call['user_id'] ?? ( $job['user_id'] ?? 0 ) );
			$layer   = AgentMemoryFile::resolve_layer_for( $filename );
			$key     = $layer . ':' . $agent_id . ':' . $user_id . ':' . $filename;

			$files[ $key ] = array(
				'agent_id' => $agent_id,
				'user_id'  => $user_id,
				'file'     => $filename,
				'layer'    => $layer,
				'section'  => isset( $tool_call['section'] ) ? (string) $tool_call['section'] : '',
				'mode'     => isset( $tool_call['mode'] ) ? (string) $tool_call['mode'] : '',
				'content'  => isset( $tool_call['content'] ) ? (string) $tool_call['content'] : '',
			);
		}

		$artifacts = array();
		foreach ( $files as $memory_scope ) {
			$filename = (string) $memory_scope['file'];
			$memory   = new AgentMemoryFile( (int) $memory_scope['user_id'], (int) $memory_scope['agent_id'], $filename );
			$read     = $memory->get_all();
			$content  = empty( $read['success'] ) ? $this->agent_memory_fallback_content( $memory_scope ) : (string) ( $read['content'] ?? '' );
			if ( '' === $content ) {
				continue;
			}

			$bundle_relative_path = $this->agent_memory_bundle_relative_path( (string) $memory_scope['layer'], $filename );
			if ( '' === $bundle_relative_path ) {
				continue;
			}

			$artifacts[] = array(
				'type'                 => 'agent_memory',
				'agent_id'             => (int) $memory_scope['agent_id'],
				'agent_slug'           => $agent['agent_slug'],
				'file'                 => $filename,
				'layer'                => (string) $memory_scope['layer'],
				'section'              => (string) $memory_scope['section'],
				'source'               => 'agent-memory',
				'bundle_relative_path' => $bundle_relative_path,
				'content'              => $content,
			);
		}

		return $artifacts;
	}

	private function agent_memory_bundle_relative_path( string $layer, string $filename ): string {
		if ( MemoryFileRegistry::LAYER_AGENT === $layer ) {
			return 'memory/agent/' . $filename;
		}

		if ( MemoryFileRegistry::LAYER_USER === $layer && 'USER.md' === $filename ) {
			return 'memory/USER.md';
		}

		return '';
	}

	private function agent_memory_fallback_content( array $memory_scope ): string {
		$content = trim( (string) ( $memory_scope['content'] ?? '' ) );
		$section = trim( (string) ( $memory_scope['section'] ?? '' ) );
		if ( '' === $content ) {
			return '';
		}

		if ( '' === $section ) {
			return $content . "\n";
		}

		return sprintf( "## %s\n%s\n", $section, $content );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function daily_memory_artifacts( array $job, array $agent, array $tool_calls ): array {
		$default_agent_id = (int) ( $agent['agent_id'] ?? 0 );
		if ( $default_agent_id <= 0 ) {
			return array();
		}

		$dates = array();
		foreach ( $tool_calls as $tool_call ) {
			if ( 'agent_daily_memory' !== ( $tool_call['tool_name'] ?? '' ) || 'write' !== ( $tool_call['action'] ?? '' ) ) {
				continue;
			}

			$agent_id = (int) ( $tool_call['agent_id'] ?? $default_agent_id );
			$user_id  = (int) ( $tool_call['user_id'] ?? ( $job['user_id'] ?? 0 ) );
			if ( $agent_id <= 0 ) {
				continue;
			}

			$date = (string) ( $tool_call['date'] ?? '' );
			if ( '' === $date ) {
				$date = gmdate( 'Y-m-d', strtotime( (string) ( $job['completed_at'] ?? $job['created_at'] ?? 'now' ) ) );
			}

			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				$dates[ $agent_id . ':' . $user_id . ':' . $date ] = array(
					'agent_id' => $agent_id,
					'user_id'  => $user_id,
					'date'     => $date,
					'content'  => isset( $tool_call['content'] ) ? (string) $tool_call['content'] : '',
				);
			}
		}

		$artifacts = array();
		foreach ( $dates as $memory_scope ) {
			$date                       = (string) $memory_scope['date'];
			list( $year, $month, $day ) = explode( '-', $date );
			$daily_memory               = new DailyMemory( (int) $memory_scope['user_id'], (int) $memory_scope['agent_id'] );
			$read                       = $daily_memory->read( $year, $month, $day );
			$content                    = empty( $read['success'] ) ? $this->daily_memory_fallback_content( $date, (string) ( $memory_scope['content'] ?? '' ) ) : (string) ( $read['content'] ?? '' );
			if ( '' === $content ) {
				continue;
			}

			$artifacts[] = array(
				'type'                 => 'agent_daily_memory',
				'agent_id'             => (int) $memory_scope['agent_id'],
				'agent_slug'           => $agent['agent_slug'],
				'date'                 => $date,
				'source'               => 'daily-memory',
				'bundle_relative_path' => sprintf( 'memory/agent/daily/%s/%s/%s.md', $year, $month, $day ),
				'content'              => $content,
			);
		}

		return $artifacts;
	}

	private function daily_memory_fallback_content( string $date, string $content ): string {
		$content = trim( $content );
		if ( '' === $content ) {
			return '';
		}

		if ( str_starts_with( $content, '# Daily Memory:' ) ) {
			return $content . "\n";
		}

		return sprintf( "# Daily Memory: %s\n\n%s\n", $date, $content );
	}
}
