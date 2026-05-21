<?php
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

		$engine_data = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		$agent       = $this->resolve_agent( $job, $engine_data );
		$tool_calls  = array_merge(
			$this->successful_tool_summaries( $engine_data ),
			$this->successful_tool_summaries_from_list( $additional_tool_summaries )
		);
		$payload     = array(
			'job_id'                 => $job_id,
			'status'                 => (string) ( $job['status'] ?? '' ),
			'agent_id'               => $agent['agent_id'],
			'agent_slug'             => $agent['agent_slug'],
			'disposition_diagnostic' => $this->disposition_diagnostic( $engine_data ),
			'required_tool_names'    => $this->tool_names_from_assertions( $engine_data['completion_assertions_required'] ?? array() ),
			'satisfied_tool_names'   => $this->tool_names_from_assertions( $engine_data['completion_assertions_satisfied'] ?? array() ),
			'successful_tool_calls'  => $tool_calls,
			'transcript'             => $this->transcript_metadata( $job_id, $engine_data ),
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
