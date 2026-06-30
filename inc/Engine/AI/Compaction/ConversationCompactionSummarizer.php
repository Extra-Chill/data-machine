<?php
/**
 * Conversation Compaction Summarizer.
 *
 * Supplies the summarizer callable consumed by the Agents API conversation
 * compaction contract (WP_Agent_Conversation_Compaction). The substrate keeps
 * provider/model execution OUT of itself by design — the compaction class is
 * contract-only and asks the runtime for a summarizer. Data Machine provides
 * that summarizer here.
 *
 * The summarizer drives a single, tool-free model turn through the same
 * `datamachine_run_conversation()` entry point the rest of Data Machine uses,
 * mirroring DailyMemoryTask's established "drive a summarization model call
 * through Data Machine's own conversation primitive" pattern rather than
 * inventing a parallel dispatch path. The summarization call passes NO
 * compaction policy of its own, so it cannot recurse into compaction.
 *
 * Model selection: resolves the acting agent's `system`-mode model (the cheap
 * maintenance model Data Machine already uses for memory compaction), with a
 * `datamachine_conversation_compaction_summary_model` filter override.
 *
 * @package DataMachine\Engine\AI\Compaction
 */

namespace DataMachine\Engine\AI\Compaction;

use AgentsAPI\AI\WP_Agent_Message;
use DataMachine\Core\PluginSettings;
use function DataMachine\Engine\AI\datamachine_run_conversation;

defined( 'ABSPATH' ) || exit;

class ConversationCompactionSummarizer {

	/**
	 * The agent mode used to resolve the maintenance summarization model.
	 *
	 * Compaction summaries are non-interactive maintenance work, so they reuse
	 * the same model class Data Machine resolves for system tasks (e.g. daily
	 * memory compaction).
	 */
	private const SUMMARY_MODE = 'system';

	/**
	 * Resolve the provider/model pair used for compaction summaries.
	 *
	 * @param array<string,mixed> $loop_payload Cleaned loop payload (carries agent_id).
	 * @param array<string,mixed> $policy       Resolved compaction policy (may pin model/provider).
	 * @return array{provider:string,model:string}
	 */
	public static function resolveSummaryModel( array $loop_payload, array $policy ): array {
		$agent_id = (int) ( $loop_payload['agent_id'] ?? 0 );

		// An explicit policy provider/model pin wins when both are present.
		$policy_provider = trim( (string) ( $policy['summary_provider'] ?? '' ) );
		$policy_model    = trim( (string) ( $policy['summary_model'] ?? '' ) );
		if ( '' !== $policy_provider && '' !== $policy_model ) {
			$resolved = array(
				'provider' => $policy_provider,
				'model'    => $policy_model,
			);
		} else {
			$resolved = PluginSettings::resolveModelForAgentMode( $agent_id, self::SUMMARY_MODE );
		}

		/**
		 * Filter the provider/model used for conversation compaction summaries.
		 *
		 * @param array{provider:string,model:string} $resolved Resolved provider/model.
		 * @param array<string,mixed>                 $loop_payload Cleaned loop payload.
		 * @param array<string,mixed>                 $policy   Resolved compaction policy.
		 */
		$resolved = apply_filters(
			'datamachine_conversation_compaction_summary_model',
			$resolved,
			$loop_payload,
			$policy
		);

		return array(
			'provider' => (string) ( $resolved['provider'] ?? '' ),
			'model'    => (string) ( $resolved['model'] ?? '' ),
		);
	}

	/**
	 * Build the summarizer callable for the conversation loop.
	 *
	 * Returns a callable matching the Agents API contract:
	 * `(array $messages_to_summarize, array $context): string`. The callable
	 * returns a non-empty summary string on success. On any failure it throws,
	 * which the substrate catches and converts into a `compaction_failed`
	 * lifecycle event while retaining the original transcript unchanged.
	 *
	 * @param array<string,mixed> $loop_payload Cleaned loop payload.
	 * @param array<string,mixed> $policy       Resolved compaction policy.
	 * @return callable Summarizer callable.
	 */
	public static function build( array $loop_payload, array $policy ): callable {
		return static function ( array $messages_to_summarize, array $context ) use ( $loop_payload, $policy ): string {
			$model = self::resolveSummaryModel( $loop_payload, $policy );
			if ( '' === $model['provider'] || '' === $model['model'] ) {
				throw new \RuntimeException( 'No model resolvable for conversation compaction summarization.' );
			}

			$prompt   = self::buildPrompt( $messages_to_summarize, $context );
			$messages = array(
				WP_Agent_Message::text( 'user', $prompt ),
			);

			// Drive a single tool-free turn. The summarization call deliberately
			// passes NO compaction policy/summarizer of its own, so it cannot
			// recurse into compaction. System mode keeps it non-interactive.
			$summary_payload = array(
				'calling_user_id'      => 0,
				'task_type'            => 'conversation_compaction_summary',
				'persist_transcript'   => false,
				'agent_id'             => (int) ( $loop_payload['agent_id'] ?? 0 ),
				'compaction_summarize' => true,
			);

			$response = datamachine_run_conversation(
				$messages,
				array(),
				$model['provider'],
				$model['model'],
				array( self::SUMMARY_MODE ),
				$summary_payload,
				1,
				true
			);

			$summary = trim( (string) ( $response['final_content'] ?? '' ) );
			if ( '' === $summary ) {
				$error = is_string( $response['error'] ?? null ) ? trim( (string) $response['error'] ) : '';
				throw new \RuntimeException(
					'' !== $error
						? 'Conversation compaction summarizer produced no summary: ' . $error
						: 'Conversation compaction summarizer produced an empty summary.'
				);
			}

			return $summary;
		};
	}

	/**
	 * Render the messages-to-summarize into a summarization prompt.
	 *
	 * @param array<int,array<string,mixed>> $messages_to_summarize Earlier transcript slice.
	 * @param array<string,mixed>            $context               Compaction context.
	 * @return string Prompt text.
	 */
	private static function buildPrompt( array $messages_to_summarize, array $context ): string {
		$lines = array();
		foreach ( WP_Agent_Message::to_provider_messages( $messages_to_summarize ) as $message ) {
			$role    = (string) ( $message['role'] ?? 'unknown' );
			$content = $message['content'] ?? '';
			if ( is_array( $content ) ) {
				$content = (string) wp_json_encode( $content );
			}
			$content = trim( (string) $content );
			if ( '' === $content ) {
				continue;
			}
			$lines[] = strtoupper( $role ) . ': ' . $content;
		}

		$transcript = implode( "\n\n", $lines );

		$total    = (int) ( $context['total_messages'] ?? count( $messages_to_summarize ) );
		$compact  = (int) ( $context['compact_count'] ?? count( $messages_to_summarize ) );
		$retained = (int) ( $context['retained_count'] ?? 0 );

		$instructions = sprintf(
			"You are compacting the earlier part of an ongoing assistant conversation to keep it within its context budget. "
				. "Summarize the following %d earlier message(s) (of %d total; the most recent %d remain verbatim and are NOT shown here).\n\n"
				. "Preserve every durable fact, decision, constraint, identifier, file path, open question, and any state the assistant must remember to continue correctly. "
				. "Do not invent information. Do not include pleasantries. Write a dense, faithful summary in plain prose or compact bullet points. "
				. "Return ONLY the summary text with no preamble.",
			$compact,
			$total,
			$retained
		);

		return $instructions . "\n\n--- EARLIER CONVERSATION ---\n\n" . $transcript;
	}
}
