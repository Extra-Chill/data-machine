<?php
/**
 * Pipeline Transcript Policy
 *
 * Resolves whether the AI conversation `$messages` array should be persisted
 * to a chat session for a given pipeline AI step invocation.
 *
 * Resolution order (first non-null wins):
 *   flow.flow_config['persist_transcripts']
 *     ?? pipeline.pipeline_config['persist_transcripts']
 *     ?? get_option( 'datamachine_persist_pipeline_transcripts', false )
 *
 * Default-off everywhere. The site option is the bottom of the stack and
 * itself defaults to `false`, so out of the box no transcripts are written
 * and no measurable performance regression vs. current behavior occurs.
 *
 * Both flow and pipeline overrides accept three values:
 *   - bool true  → force persistence on for this scope
 *   - bool false → force persistence off for this scope (overrides site option)
 *   - null / absent → defer to next layer
 *
 * @package DataMachine\Engine\AI
 * @since   next
 */

namespace DataMachine\Engine\AI;

use DataMachine\Core\EngineData;

defined( 'ABSPATH' ) || exit;

/**
 * Pipeline transcript policy resolver.
 */
class PipelineTranscriptPolicy {

	/**
	 * Site-wide option name for the global default.
	 */
	public const OPTION_NAME = 'datamachine_persist_pipeline_transcripts';

	/**
	 * Resolve whether transcripts should be persisted for this AI step invocation.
	 *
	 * Reads the engine snapshot's flow_config + pipeline_config JSON blobs
	 * (already in memory — no extra DB calls) and falls back to the site
	 * option. Returns a single boolean for callers to thread through the
	 * AI loop payload.
	 *
	 * @param EngineData $engine Engine data snapshot for the running job.
	 * @return bool True when the AI loop should persist its $messages array.
	 */
	public static function shouldPersist( EngineData $engine ): bool {
		$flow_config     = $engine->getFlowConfig();
		$pipeline_config = $engine->getPipelineConfig();

		$flow_override = self::extract( $flow_config );
		if ( null !== $flow_override ) {
			return $flow_override;
		}

		$pipeline_override = self::extract( $pipeline_config );
		if ( null !== $pipeline_override ) {
			return $pipeline_override;
		}

		return (bool) get_option( self::OPTION_NAME, false );
	}

	/**
	 * Extract a tri-state `persist_transcripts` value from a config array.
	 *
	 * Accepts only true booleans for true/false. Any other value (string,
	 * int, missing key, null) returns null so the next layer can decide.
	 * This avoids accidentally turning persistence on because a JSON blob
	 * happens to contain an empty string or stale value.
	 *
	 * @param array $config Decoded flow_config or pipeline_config blob.
	 * @return bool|null Resolved boolean override, or null to defer.
	 */
	private static function extract( array $config ): ?bool {
		if ( ! array_key_exists( 'persist_transcripts', $config ) ) {
			return null;
		}

		$value = $config['persist_transcripts'];

		if ( true === $value ) {
			return true;
		}

		if ( false === $value ) {
			return false;
		}

		return null;
	}
}
