<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Data Machine owns custom operational tables and these paths require fresh runtime state or one-time schema mutation.
/**
 * Job artifact payload builder.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Chat\Chat;
use DataMachine\Core\Database\Chat\ConversationStoreFactory;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\FilesRepository\AgentMemory as AgentMemoryFile;
use DataMachine\Core\FilesRepository\DailyMemory;
use DataMachine\Engine\AI\MemoryFileRegistry;

defined( 'ABSPATH' ) || exit;

class JobArtifacts {

	private const TRANSCRIPT_ARTIFACT_SCHEMA_VERSION = 1;
	private const TOOL_TRACE_ARTIFACT_SCHEMA_VERSION = 1;
	private const ARTIFACT_REF_SCHEMA_VERSION        = 1;
	private const MAX_TRANSCRIPT_MESSAGES            = 200;
	private const MAX_TRANSCRIPT_CONTENT_CHARS       = 4000;
	private const MAX_TOOL_TRACE_ENTRIES             = 200;
	private const MAX_TOOL_TRACE_FIELD_CHARS         = 4000;

	/**
	 * Resolve a portable job artifact ref into the stored artifact metadata.
	 *
	 * External runners should treat artifact_ref as the stable identifier and use
	 * this resolver rather than reading local path or URL metadata directly.
	 *
	 * @param string $artifact_ref Portable artifact ref.
	 * @param array  $engine_data  Optional engine_data snapshot containing artifact_files.
	 * @return array{success: bool, artifact?: array<string,mixed>, error?: string}
	 */
	public function resolve_artifact_ref( string $artifact_ref, array $engine_data = array() ): array {
		$artifact_ref = sanitize_text_field( $artifact_ref );
		if ( '' === $artifact_ref ) {
			return array(
				'success' => false,
				'error'   => 'artifact_ref must be a non-empty string.',
			);
		}

		if ( empty( $engine_data ) ) {
			$job_id = $this->job_id_from_artifact_ref( $artifact_ref );
			if ( $job_id <= 0 ) {
				return array(
					'success' => false,
					'error'   => 'artifact_ref is not a Data Machine job artifact ref.',
				);
			}

			$job = ( new Jobs() )->get_job( $job_id );
			if ( ! $job || ! is_array( $job['engine_data'] ?? null ) ) {
				return array(
					'success' => false,
					'error'   => sprintf( 'Artifact metadata for job %d was not found.', $job_id ),
				);
			}

			$engine_data = $job['engine_data'];
		}

		foreach ( $this->artifact_files_metadata( $engine_data ) as $artifact ) {
			if ( (string) ( $artifact['artifact_ref'] ?? '' ) === $artifact_ref ) {
				return array(
					'success'  => true,
					'artifact' => $artifact,
				);
			}
		}

		return array(
			'success' => false,
			'error'   => sprintf( 'Artifact ref %s was not found.', $artifact_ref ),
		);
	}

	/**
	 * Hydrate a portable job artifact ref into verified artifact content.
	 *
	 * Public consumers should use artifact_ref plus this resolver instead of
	 * relying on local_debug paths, absolute filesystem paths, or URLs.
	 *
	 * @param string $artifact_ref Portable artifact ref.
	 * @param array  $engine_data  Optional engine_data snapshot containing artifact_files.
	 * @return array{success: bool, artifact?: array<string,mixed>, content?: string, bytes?: int, sha256?: string, verified?: bool, error?: string}
	 */
	public function hydrate_artifact_ref( string $artifact_ref, array $engine_data = array() ): array {
		$resolved = $this->resolve_artifact_ref( $artifact_ref, $engine_data );
		if ( empty( $resolved['success'] ) || ! is_array( $resolved['artifact'] ?? null ) ) {
			return array(
				'success' => false,
				'error'   => (string) ( $resolved['error'] ?? 'Artifact metadata was not found.' ),
			);
		}

		$artifact      = $resolved['artifact'];
		$content       = null;
		$resolver_args = array(
			'artifact_ref' => $artifact_ref,
			'artifact'     => $artifact,
			'engine_data'  => $engine_data,
		);

		$filtered = apply_filters( 'datamachine_job_artifact_ref_content', null, $resolver_args );
		if ( is_string( $filtered ) ) {
			$content = $filtered;
		} elseif ( is_array( $filtered ) && isset( $filtered['content'] ) && is_string( $filtered['content'] ) ) {
			$content = $filtered['content'];
		}

		if ( null === $content ) {
			$path = $this->artifact_storage_path( $artifact );
			if ( '' !== $path ) {
				$read = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( false !== $read ) {
					$content = $read;
				}
			}
		}

		if ( null === $content ) {
			return array(
				'success' => false,
				'error'   => 'Artifact content is unavailable from configured artifact storage.',
			);
		}

		$bytes  = strlen( $content );
		$sha256 = hash( 'sha256', $content );
		$verify = $this->verify_artifact_content( $artifact, $bytes, $sha256 );
		if ( empty( $verify['success'] ) ) {
			return array(
				'success'  => false,
				'artifact' => $this->public_artifact_metadata( $artifact ),
				'bytes'    => $bytes,
				'sha256'   => $sha256,
				'error'    => (string) ( $verify['error'] ?? 'Artifact content failed integrity verification.' ),
			);
		}

		return array(
			'success'  => true,
			'artifact' => $this->public_artifact_metadata( $artifact ),
			'content'  => $content,
			'bytes'    => $bytes,
			'sha256'   => $sha256,
			'verified' => true,
		);
	}

	/**
	 * Stream verified artifact content to a caller-provided writer.
	 *
	 * @param string   $artifact_ref Portable artifact ref.
	 * @param callable $writer       Receives the content string once verified.
	 * @param array    $engine_data  Optional engine_data snapshot containing artifact_files.
	 * @return array{success: bool, artifact?: array<string,mixed>, bytes?: int, sha256?: string, verified?: bool, error?: string}
	 */
	public function stream_artifact_ref( string $artifact_ref, callable $writer, array $engine_data = array() ): array {
		$hydrated = $this->hydrate_artifact_ref( $artifact_ref, $engine_data );
		if ( empty( $hydrated['success'] ) || ! is_string( $hydrated['content'] ?? null ) ) {
			return $hydrated;
		}

		$writer( $hydrated['content'] );
		unset( $hydrated['content'] );
		return $hydrated;
	}

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
			'job_id'                   => $job_id,
			'status'                   => (string) ( $job['status'] ?? '' ),
			'agent_id'                 => $agent['agent_id'],
			'agent_slug'               => $agent['agent_slug'],
			'job_summary'              => JobArtifactSurfaces::summary( $job, $engine_data ),
			'scoped_artifacts'         => JobArtifactSurfaces::artifactRefs( $engine_data ),
			'disposition_diagnostic'   => $this->disposition_diagnostic( $engine_data ),
			'required_tool_names'      => $this->tool_names_from_assertions( $engine_data['completion_assertions_required'] ?? array() ),
			'satisfied_tool_names'     => $this->tool_names_from_assertions( $engine_data['completion_assertions_satisfied'] ?? array() ),
			'tool_resolution_evidence' => is_array( $engine_data['tool_resolution_evidence'] ?? null ) ? $engine_data['tool_resolution_evidence'] : array(),
			'successful_tool_calls'    => $tool_calls,
			'tool_trace'               => $this->tool_trace( $engine_data, $additional_tool_summaries ),
			'transcript'               => $this->transcript_metadata( $job_id, $engine_data ),
			'artifact_refs'            => $this->hashable_artifact_refs( $transcript_artifact, $tool_trace_artifact ),
			'artifact_files'           => $this->artifact_files_metadata( $engine_data ),
			'transcript_artifact'      => $transcript_artifact,
			'tool_trace_artifact'      => $tool_trace_artifact,
			'agent_memory_artifacts'   => $this->agent_memory_artifacts( $job, $agent, $tool_calls ),
			'daily_memory_artifacts'   => $this->daily_memory_artifacts( $job, $agent, $tool_calls ),
		);

		return array(
			'success'   => true,
			'artifacts' => $payload,
		);
	}

	/**
	 * Write first-class transcript and tool trace artifact files for a job.
	 *
	 * @param int   $job_id                    Job ID.
	 * @param array $additional_tool_summaries Tool summaries accumulated before engine_data persistence.
	 * @return array{success: bool, artifact_files?: array<string,array<string,mixed>>, error?: string}
	 */
	public function write_artifact_files( int $job_id, array $additional_tool_summaries = array() ): array {
		$result = $this->get( $job_id, $additional_tool_summaries );
		if ( empty( $result['success'] ) || ! is_array( $result['artifacts'] ?? null ) ) {
			return array(
				'success' => false,
				'error'   => (string) ( $result['error'] ?? 'Failed to build job artifacts.' ),
			);
		}

		$payload        = $result['artifacts'];
		$artifact_files = array();
		foreach (
			array(
				'transcript' => $payload['transcript_artifact'] ?? null,
				'tool_trace' => $payload['tool_trace_artifact'] ?? null,
			) as $artifact_key => $artifact_payload
		) {
			if ( ! is_array( $artifact_payload ) ) {
				continue;
			}

			$write_result = $this->write_artifact_file( $job_id, $artifact_key, $artifact_payload );
			if ( empty( $write_result['success'] ) || ! is_array( $write_result['file'] ?? null ) ) {
				return array(
					'success' => false,
					'error'   => (string) ( $write_result['error'] ?? 'Failed to write artifact file.' ),
				);
			}

			$artifact_files[ $artifact_key ] = $write_result['file'];
		}

		return array(
			'success'        => true,
			'artifact_files' => $artifact_files,
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
	 * @return array<string,array<string,mixed>>
	 */
	private function artifact_files_metadata( array $engine_data ): array {
		$artifact_files = is_array( $engine_data['artifact_files'] ?? null ) ? $engine_data['artifact_files'] : array();
		$out            = array();

		foreach ( $artifact_files as $key => $artifact_file ) {
			if ( ! is_string( $key ) || ! is_array( $artifact_file ) ) {
				continue;
			}

			$out[ $key ] = $this->portable_artifact_file_ref( $artifact_file );
		}

		return $out;
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
			$entries[] = $this->normalize_tool_trace_entry( $entry, $index );
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

	private function job_id_from_artifact_ref( string $artifact_ref ): int {
		if ( ! preg_match( '#^datamachine://jobs/(\d+)/artifacts/#', $artifact_ref, $matches ) ) {
			return 0;
		}

		return (int) $matches[1];
	}

	private function artifact_storage_path( array $artifact ): string {
		$relative_path = trim( (string) ( $artifact['relative_path'] ?? '' ) );
		if ( '' === $relative_path || str_contains( $relative_path, "\0" ) || str_starts_with( $relative_path, '/' ) || preg_match( '#^[A-Za-z]:[\\/]#', $relative_path ) ) {
			return '';
		}

		$upload_dir = wp_upload_dir();
		$base_root  = (string) ( $upload_dir['basedir'] ?? '' );
		if ( '' === trim( $base_root ) ) {
			return '';
		}

		$base_dir = realpath( $base_root );
		if ( false === $base_dir ) {
			return '';
		}

		$candidate = trailingslashit( $base_dir ) . ltrim( str_replace( '\\', '/', $relative_path ), '/' );
		$real_path = realpath( $candidate );
		if ( false === $real_path || ! is_file( $real_path ) ) {
			return '';
		}

		$base_prefix = trailingslashit( $base_dir );
		return str_starts_with( $real_path, $base_prefix ) ? $real_path : '';
	}

	/** @return array{success: bool, error?: string} */
	private function verify_artifact_content( array $artifact, int $bytes, string $sha256 ): array {
		return ArtifactManifest::verify( $artifact, $bytes, $sha256 );
	}

	/** @return array<string,mixed> */
	private function public_artifact_metadata( array $artifact ): array {
		return ArtifactManifest::public_metadata( $artifact );
	}

	/** @return array<string,mixed> */
	private function portable_artifact_file_ref( array $artifact_file ): array {
		$stored_local_debug = is_array( $artifact_file['local_debug'] ?? null ) ? $artifact_file['local_debug'] : array();
		$local_debug        = $this->filter_empty(
			array(
				'path' => isset( $artifact_file['path'] ) ? (string) $artifact_file['path'] : ( isset( $stored_local_debug['path'] ) ? (string) $stored_local_debug['path'] : null ),
				'url'  => isset( $artifact_file['url'] ) ? esc_url_raw( (string) $artifact_file['url'] ) : ( isset( $stored_local_debug['url'] ) ? esc_url_raw( (string) $stored_local_debug['url'] ) : null ),
			)
		);

		return $this->filter_empty(
			array(
				'artifact_ref'    => isset( $artifact_file['artifact_ref'] ) ? sanitize_text_field( (string) $artifact_file['artifact_ref'] ) : null,
				'type'            => isset( $artifact_file['type'] ) ? sanitize_key( (string) $artifact_file['type'] ) : ( isset( $artifact_file['artifact_type'] ) ? sanitize_key( (string) $artifact_file['artifact_type'] ) : null ),
				'schema_version'  => isset( $artifact_file['schema_version'] ) ? (int) $artifact_file['schema_version'] : self::ARTIFACT_REF_SCHEMA_VERSION,
				'sha256'          => isset( $artifact_file['sha256'] ) ? sanitize_text_field( (string) $artifact_file['sha256'] ) : null,
				'bytes'           => isset( $artifact_file['bytes'] ) ? (int) $artifact_file['bytes'] : null,
				'relative_path'   => isset( $artifact_file['relative_path'] ) ? sanitize_text_field( (string) $artifact_file['relative_path'] ) : null,
				'export_url'      => isset( $artifact_file['export_url'] ) ? esc_url_raw( (string) $artifact_file['export_url'] ) : null,
				'signed_url'      => isset( $artifact_file['signed_url'] ) ? esc_url_raw( (string) $artifact_file['signed_url'] ) : null,
				'retention_scope' => isset( $artifact_file['retention_scope'] ) ? sanitize_key( (string) $artifact_file['retention_scope'] ) : null,
				'payload_sha256'  => isset( $artifact_file['payload_sha256'] ) ? sanitize_text_field( (string) $artifact_file['payload_sha256'] ) : null,
				'written_at'      => isset( $artifact_file['written_at'] ) ? sanitize_text_field( (string) $artifact_file['written_at'] ) : null,
				'local_debug'     => ! empty( $local_debug ) ? $local_debug : null,
			)
		);
	}

	/**
	 * @return array{success: bool, file?: array<string,mixed>, error?: string}
	 */
	private function write_artifact_file( int $job_id, string $artifact_key, array $artifact_payload ): array {
		$upload_dir = wp_upload_dir();
		$base_root  = (string) $upload_dir['basedir'];
		$base_url   = (string) $upload_dir['baseurl'];
		if ( '' === trim( $base_root ) ) {
			return array(
				'success' => false,
				'error'   => 'Upload directory is unavailable.',
			);
		}
		$base_dir = trailingslashit( $base_root ) . 'datamachine-artifacts/jobs/' . $job_id;
		$base_url = '' !== $base_url ? trailingslashit( $base_url ) . 'datamachine-artifacts/jobs/' . $job_id : '';

		if ( ! wp_mkdir_p( $base_dir ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Failed to create artifact directory for job %d.', $job_id ),
			);
		}

		$file_name = sanitize_file_name( str_replace( '_', '-', $artifact_key ) . '.json' );
		$file_path = trailingslashit( $base_dir ) . $file_name;
		$json      = wp_json_encode( $this->canonicalize( $artifact_payload ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Failed to encode %s artifact for job %d.', $artifact_key, $job_id ),
			);
		}

		$json .= "\n";
		if ( ! $this->write_atomic_file( $file_path, $json ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Failed to write %s artifact for job %d.', $artifact_key, $job_id ),
			);
		}

		$relative_path = 'datamachine-artifacts/jobs/' . $job_id . '/' . $file_name;
		$artifact_ref  = (string) ( $artifact_payload['artifact_ref'] ?? $this->artifact_ref( $job_id, $artifact_key ) );
		$type          = (string) ( $artifact_payload['artifact_type'] ?? $artifact_key );
		$export_url    = apply_filters(
			'datamachine_job_artifact_ref_export_url',
			null,
			array(
				'artifact_ref'  => $artifact_ref,
				'type'          => $type,
				'job_id'        => $job_id,
				'relative_path' => $relative_path,
				'path'          => $file_path,
			)
		);
		$signed_url    = apply_filters(
			'datamachine_job_artifact_ref_signed_url',
			null,
			array(
				'artifact_ref'  => $artifact_ref,
				'type'          => $type,
				'job_id'        => $job_id,
				'relative_path' => $relative_path,
				'path'          => $file_path,
			)
		);

		$manifest = ArtifactManifest::create(
			array(
				'artifact_ref'   => $artifact_ref,
				'artifact_type'  => $type,
				'content'        => $json,
				'relative_path'  => $relative_path,
				'export_url'     => is_string( $export_url ) ? $export_url : '',
				'signed_url'     => is_string( $signed_url ) ? $signed_url : '',
				'payload_sha256' => (string) ( $artifact_payload['sha256'] ?? '' ),
				'written_at'     => gmdate( 'c' ),
				'local_debug'    => array(
					'path' => $file_path,
					'url'  => '' !== $base_url ? trailingslashit( $base_url ) . $file_name : null,
				),
			)
		);

		return array(
			'success' => true,
			'file'    => $manifest,
		);
	}

	private function write_atomic_file( string $file_path, string $contents ): bool {
		$directory = dirname( $file_path );
		if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			return false;
		}

		$temp_path = tempnam( $directory, '.tmp-artifact-' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_tempnam
		if ( false === $temp_path ) {
			return false;
		}

		$written = file_put_contents( $temp_path, $contents, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( strlen( $contents ) !== $written ) {
			unlink( $temp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			return false;
		}

		if ( ! rename( $temp_path, $file_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			unlink( $temp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			return false;
		}

		return true;
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
		return DataPath::filterPresent( $value );
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

		$table = Chat::get_prefixed_table_name();
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
				$timestamp = strtotime( (string) ( $job['completed_at'] ?? $job['created_at'] ?? 'now' ) );
				$date      = gmdate( 'Y-m-d', false === $timestamp ? null : $timestamp );
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
			$content                    = empty( $read['success'] ) ? $this->daily_memory_fallback_content( $date, (string) $memory_scope['content'] ) : (string) ( $read['content'] ?? '' );
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
