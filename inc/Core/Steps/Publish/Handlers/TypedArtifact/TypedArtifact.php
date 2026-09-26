<?php
/**
 * Typed artifact publish handler.
 *
 * @package DataMachine\Core\Steps\Publish\Handlers\TypedArtifact
 */

namespace DataMachine\Core\Steps\Publish\Handlers\TypedArtifact;

use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;

defined( 'ABSPATH' ) || exit;

class TypedArtifact extends PublishHandler {
	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'typed_artifact' );

		self::registerHandler(
			'typed_artifact',
			'publish',
			self::class,
			'Typed Artifact',
			'Emit a typed artifact output and canonical Data Machine packet for downstream workflow handoff.',
			false,
			null,
			null,
			array( self::class, 'registerAITool' )
		);
	}

	/**
	 * Register the model-facing typed artifact emitter tool.
	 *
	 * @param array  $tools          Existing tools.
	 * @param string $handler_slug   Handler slug.
	 * @param array  $handler_config Handler config.
	 * @return array<string,array<string,mixed>>
	 */
	public static function registerAITool( array $tools, string $handler_slug, array $handler_config ): array {
		if ( 'typed_artifact' !== $handler_slug ) {
			return $tools;
		}

		$output_key = self::nonEmptyConfigString( $handler_config, 'output_key' );
		$schema     = self::nonEmptyConfigString( $handler_config, 'schema' );
		$artifact   = self::nonEmptyConfigString( $handler_config, 'artifact' );

		$tools['emit_typed_artifact'] = array(
			'class'                   => self::class,
			'client_context_bindings' => array( 'job_id' ),
			'method'                  => 'handle_tool_call',
			'handler'                 => 'typed_artifact',
			'description'             => sprintf(
				'Emit the required %s typed artifact. Call this exactly once when the payload is complete; the payload must match %s.',
				'' !== $artifact ? $artifact : 'workflow',
				'' !== $schema ? $schema : 'the configured artifact schema'
			),
			'parameters'              => array(
				'type'       => 'object',
				'properties' => array(
					'payload' => array(
						'type'        => 'object',
						'description' => sprintf( 'The %s payload object.', '' !== $artifact ? $artifact : 'typed artifact' ),
					),
				),
				'required'   => array( 'payload' ),
			),
			'handler_config'          => $handler_config,
		);

		if ( '' !== $output_key ) {
			$tools['emit_typed_artifact']['output_key'] = $output_key;
		}
		if ( '' !== $schema ) {
			$tools['emit_typed_artifact']['artifact_schema'] = $schema;
		}
		if ( '' !== $artifact ) {
			$tools['emit_typed_artifact']['artifact'] = $artifact;
		}

		return $tools;
	}

	/**
	 * Emit a typed artifact from model-provided payload.
	 *
	 * @param array $parameters     Tool parameters.
	 * @param array $handler_config Handler config.
	 * @return array<string,mixed>
	 */
	protected function executePublish( array $parameters, array $handler_config ): array {
		$payload = $parameters['payload'] ?? null;
		if ( ! is_array( $payload ) ) {
			return $this->errorResponse( 'payload must be an object for typed artifact output.' );
		}

		$output_key = self::nonEmptyConfigString( $handler_config, 'output_key' );
		$schema     = self::nonEmptyConfigString( $handler_config, 'schema' );
		$artifact   = self::nonEmptyConfigString( $handler_config, 'artifact' );
		if ( '' === $output_key || '' === $schema || '' === $artifact ) {
			return $this->errorResponse( 'typed_artifact handler requires output_key, schema, and artifact configuration.' );
		}

		$typed_artifact = array(
			'output_key' => $output_key,
			'schema'     => $schema,
			'artifact'   => $artifact,
			'payload'    => $payload,
		);

		$job_id = (int) ( $parameters['job_id'] ?? 0 );
		$engine = $parameters['engine'] ?? null;
		if ( $job_id > 0 ) {
			$outputs                                   = $engine instanceof EngineData ? $engine->get( 'outputs', array() ) : array();
			$outputs                                   = is_array( $outputs ) ? $outputs : array();
			$outputs['typed_artifacts']                = is_array( $outputs['typed_artifacts'] ?? null ) ? $outputs['typed_artifacts'] : array();
			$outputs['typed_artifacts'][ $output_key ] = $typed_artifact;

			if ( $engine instanceof EngineData ) {
				$engine->set( 'outputs', $outputs );
			} else {
				EngineData::merge( $job_id, array( 'outputs' => $outputs ) );
			}
		}

		return $this->successResponse(
			array(
				'output_key'      => $output_key,
				'schema'          => $schema,
				'artifact'        => $artifact,
				'typed_artifacts' => array(
					$output_key => $typed_artifact,
				),
				'packet'          => array(
					'type'     => 'typed_artifact',
					'data'     => array(
						'output_key' => $output_key,
						'schema'     => $schema,
						'artifact'   => $artifact,
						'payload'    => $payload,
					),
					'metadata' => array(
						'source_type'     => 'typed_artifact_handler',
						'handler_tool'    => 'typed_artifact',
						'tool_success'    => true,
						'output_key'      => $output_key,
						'artifact_schema' => $schema,
						'artifact'        => $artifact,
					),
				),
			)
		);
	}

	private static function nonEmptyConfigString( array $config, string $key ): string {
		$value = $config[ $key ] ?? '';
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
