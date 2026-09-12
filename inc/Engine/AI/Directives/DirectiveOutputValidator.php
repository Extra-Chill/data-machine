<?php
/**
 * Directive Output Validator
 *
 * Validates standardized directive outputs before rendering.
 *
 * @package DataMachine\Engine\AI\Directives
 */

namespace DataMachine\Engine\AI\Directives;

defined( 'ABSPATH' ) || exit;

class DirectiveOutputValidator {

	/**
	 * Validate directive outputs before rendering.
	 *
	 * @param array $outputs Raw directive outputs to validate
	 * @param array $context Optional execution context (job_id, flow_step_id, etc.)
	 * @return array Validated outputs
	 */
	public static function validateOutputs( array $outputs, array $context = array() ): array {
		$validated = array();

		foreach ( $outputs as $output ) {
			if ( ! is_array( $output ) ) {
				do_action( 'datamachine_log', 'warning', 'Directive output skipped (not an array)', $context );
				continue;
			}

			$type = $output['type'] ?? null;
			if ( ! is_string( $type ) || '' === $type ) {
				do_action( 'datamachine_log', 'warning', 'Directive output skipped (missing type)', $context );
				continue;
			}

			if ( 'system_text' === $type ) {
				$content = $output['content'] ?? null;
				if ( ! is_string( $content ) ) {
					do_action( 'datamachine_log', 'warning', 'Directive output skipped (system_text missing content)', $context );
					continue;
				}

				$content = trim( $content );
				if ( '' === $content ) {
					continue;
				}

				$validated[] = array(
					'type'    => 'system_text',
					'content' => $content,
				);

				continue;
			}

			if ( 'system_json' === $type ) {
				$label = $output['label'] ?? null;
				$data  = $output['data'] ?? null;

				if ( ! is_string( $label ) || trim( $label ) === '' ) {
					do_action( 'datamachine_log', 'warning', 'Directive output skipped (system_json missing label)', $context );
					continue;
				}

				if ( ! is_array( $data ) ) {
					do_action( 'datamachine_log', 'warning', 'Directive output skipped (system_json missing data)', $context );
					continue;
				}

				$validated[] = array(
					'type'  => 'system_json',
					'label' => trim( $label ),
					'data'  => $data,
				);

				continue;
			}

			if ( 'system_file' === $type ) {
				$file_path = $output['file_path'] ?? null;
				$mime_type = $output['mime_type'] ?? null;

				if ( ! is_string( $file_path ) || trim( $file_path ) === '' || ! is_string( $mime_type ) || trim( $mime_type ) === '' ) {
					do_action( 'datamachine_log', 'warning', 'Directive output skipped (system_file missing file_path or mime_type)', $context );
					continue;
				}

				$validated[] = array(
					'type'      => 'system_file',
					'file_path' => $file_path,
					'mime_type' => $mime_type,
				);

				continue;
			}

			do_action(
				'datamachine_log',
				'warning',
				'Directive output skipped (unknown type)',
				array_merge(
					$context,
					array(
						'type' => $type,
					)
				)
			);
		}

		return $validated;
	}
}
