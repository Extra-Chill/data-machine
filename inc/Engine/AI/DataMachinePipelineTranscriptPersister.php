<?php
/**
 * Data Machine pipeline transcript persister.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

use AgentsAPI\AI\AgentConversationRequest;
use AgentsAPI\AI\AgentConversationTranscriptPersisterInterface;
use DataMachine\Core\Database\Chat\ConversationStoreFactory;

defined( 'ABSPATH' ) || exit;

/**
 * Persists opted-in pipeline transcripts through Data Machine's transcript store.
 */
class DataMachinePipelineTranscriptPersister implements AgentConversationTranscriptPersisterInterface {

	/**
	 * @inheritDoc
	 */
	public function persist( array $messages, AgentConversationRequest $request, array $result ): string {
		$runtime_context = $request->runtimeContext();
		$metadata        = $request->metadata();
		$provider        = (string) ( $metadata['provider'] ?? '' );
		$model           = (string) ( $metadata['model'] ?? '' );

		if ( empty( $runtime_context['persist_transcript'] ) ) {
			return '';
		}

		// Without messages there's nothing useful to persist. This guards
		// against the early-failure path where the first AI request errored
		// before any message was assembled.
		if ( empty( $messages ) ) {
			return '';
		}

		$store = ConversationStoreFactory::get_transcript_store();

		$user_id  = (int) ( $runtime_context['user_id'] ?? 0 );
		$agent_id = (int) ( $runtime_context['agent_id'] ?? 0 );

		$store_metadata = array(
			'source'       => 'pipeline_transcript',
			'job_id'       => $runtime_context['job_id'] ?? null,
			'flow_step_id' => $runtime_context['flow_step_id'] ?? null,
			'pipeline_id'  => $runtime_context['pipeline_id'] ?? null,
			'flow_id'      => $runtime_context['flow_id'] ?? null,
			'agent_id'     => $agent_id > 0 ? $agent_id : null,
			'owner_id'     => $user_id > 0 ? $user_id : null,
			'provider'     => $provider,
			'model'        => $model,
			'turn_count'   => $result['turn_count'] ?? 0,
			'completed'    => (bool) ( $result['completed'] ?? false ),
			'error'        => $result['error'] ?? null,
			'usage'        => $result['usage'] ?? array(),
		);

		if ( ! empty( $result['request_metadata'] ) && is_array( $result['request_metadata'] ) ) {
			$store_metadata['request_metadata'] = $result['request_metadata'];
		}

		$session_id = $store->create_session( $user_id, $agent_id, $store_metadata, 'pipeline' );

		if ( '' === $session_id ) {
			do_action(
				'datamachine_log',
				'debug',
				'AIConversationLoop: Failed to create transcript session',
				array(
					'job_id'       => $runtime_context['job_id'] ?? null,
					'flow_step_id' => $runtime_context['flow_step_id'] ?? null,
				)
			);
			return '';
		}

		$updated = $store->update_session( $session_id, $messages, $store_metadata, $provider, $model );
		if ( ! $updated ) {
			do_action(
				'datamachine_log',
				'debug',
				'AIConversationLoop: Failed to write transcript messages',
				array(
					'session_id'   => $session_id,
					'job_id'       => $runtime_context['job_id'] ?? null,
					'flow_step_id' => $runtime_context['flow_step_id'] ?? null,
				)
			);
			// Best-effort cleanup so we don't leave an empty pipeline-mode row behind.
			$store->delete_session( $session_id );
			return '';
		}

		do_action(
			'datamachine_log',
			'debug',
			'AIConversationLoop: Transcript persisted',
			array(
				'session_id'   => $session_id,
				'job_id'       => $runtime_context['job_id'] ?? null,
				'flow_step_id' => $runtime_context['flow_step_id'] ?? null,
				'turn_count'   => $result['turn_count'] ?? 0,
			)
		);

		return $session_id;
	}
}
