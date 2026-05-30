<?php
/**
 * SSI import diagnostics repair-plan system task.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

class SSIImportRepairPlanTask extends SystemTask {

	private const TASK_TYPE = 'ssi_import_repair_plan';

	public function getTaskType(): string {
		return self::TASK_TYPE;
	}

	public function requiresAgentContext(): bool {
		return false;
	}

	public static function getTaskMeta(): array {
		return array(
			'label'            => 'SSI Import Repair Plan',
			'description'      => 'Parse Static Site Importer diagnostics for a sandbox source tree and return a bounded repair plan without mutating the host site.',
			'setting_key'      => null,
			'default_enabled'  => true,
			'trigger'          => 'On-demand via workflow system_task step',
			'trigger_type'     => 'manual',
			'supports_run'     => true,
			'mutates'          => false,
			'supports_dry_run' => true,
			'requires_scope'   => true,
			'params_schema'    => array(
				'type'       => 'object',
				'required'   => array( 'source_tree_path', 'import_report' ),
				'scope'      => array( 'source_tree_path' ),
				'properties' => array(
					'source_tree_path' => array(
						'type'        => 'string',
						'description' => 'Sandbox-local source tree root. All planned file targets must resolve inside this directory.',
					),
					'import_report'     => array(
						'type'        => 'object',
						'description' => 'Static Site Importer import report or a JSON string containing the report.',
					),
					'context'           => array(
						'type'        => 'object',
						'description' => 'Optional caller context, such as sandbox id, source label, or artifact id.',
					),
					'max_actions'       => array(
						'type'        => 'integer',
						'description' => 'Maximum diagnostics to convert into repair actions. Defaults to 50.',
					),
				),
			),
		);
	}

	public function executeTask( int $jobId, array $params ): void {
		$result = self::buildRepairPlan( $params );
		if ( empty( $result['success'] ) ) {
			$this->failJob( $jobId, (string) ( $result['error'] ?? 'SSI import repair plan failed.' ) );
			return;
		}

		$this->completeJob(
			$jobId,
			array(
				'output_data_packets'      => array(
					array(
						'type'     => 'ssi_import_repair_plan',
						'data'     => array(
							'title'  => 'SSI Import Repair Plan',
							'body'   => wp_json_encode( $result ),
							'result' => $result,
						),
						'metadata' => array(
							'source_type'     => 'ssi_import_report',
							'source_tree_path' => (string) ( $params['source_tree_path'] ?? '' ),
							'success'          => true,
						),
					),
				),
				'replace_data_packets'     => false,
				'ssi_import_repair_plan'   => $result['repair_plan'],
				'ssi_import_repair_summary' => $result['summary'],
				'completed_at'             => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Build a bounded repair plan from an SSI import report.
	 *
	 * @param array<string,mixed> $params Task params.
	 * @return array<string,mixed>
	 */
	public static function buildRepairPlan( array $params ): array {
		if ( isset( $params['input'] ) && is_array( $params['input'] ) ) {
			$params = array_merge( $params['input'], $params );
			unset( $params['input'] );
		}

		$source_tree_path = isset( $params['source_tree_path'] ) ? (string) $params['source_tree_path'] : '';
		$root             = self::normalizeRootPath( $source_tree_path );
		if ( '' === $root ) {
			return array(
				'success' => false,
				'error'   => 'ssi_import_repair_plan requires source_tree_path to be an existing directory.',
			);
		}

		$report = self::normalizeReport( $params['import_report'] ?? null );
		if ( ! is_array( $report ) ) {
			return array(
				'success' => false,
				'error'   => 'ssi_import_repair_plan requires import_report as an object or JSON object string.',
			);
		}

		$max_actions = max( 1, min( 200, (int) ( $params['max_actions'] ?? 50 ) ) );
		$diagnostics = self::collectDiagnostics( $report );
		$actions     = array();
		$refusals    = array();

		foreach ( $diagnostics as $diagnostic ) {
			if ( count( $actions ) >= $max_actions ) {
				break;
			}

			$action = self::actionForDiagnostic( $diagnostic, $root );
			if ( null === $action ) {
				continue;
			}

			$actions[] = $action;
			if ( 'refused' === $action['status'] ) {
				$refusals[] = $action;
			}
		}

		foreach ( self::collectRequestedEdits( $params, $report ) as $requested_edit ) {
			if ( count( $actions ) >= $max_actions ) {
				break;
			}

			$action    = self::actionForRequestedEdit( $requested_edit, $root );
			$actions[] = $action;
			if ( 'refused' === $action['status'] ) {
				$refusals[] = $action;
			}
		}

		$summary = self::summarizeActions( $actions, count( $diagnostics ) );

		return array(
			'success'     => true,
			'repair_plan' => array(
				'version'          => 1,
				'mode'             => 'plan_only',
				'source_tree_path' => $root,
				'context'          => is_array( $params['context'] ?? null ) ? $params['context'] : array(),
				'actions'          => $actions,
				'changed_files'    => array(),
				'refusals'         => $refusals,
				'notes'            => array(
					'This task is intentionally plan-only. It does not write source files or mutate the host WordPress site.',
					'Callers may apply accepted actions in their sandbox after validating paths and stop conditions.',
				),
			),
			'summary'     => $summary,
		);
	}

	private static function normalizeRootPath( string $source_tree_path ): string {
		if ( '' === trim( $source_tree_path ) ) {
			return '';
		}

		$root = realpath( $source_tree_path );
		if ( false === $root || ! is_dir( $root ) ) {
			return '';
		}

		return rtrim( str_replace( '\\', '/', $root ), '/' );
	}

	/** @return array<string,mixed>|null */
	private static function normalizeReport( mixed $report ): ?array {
		if ( is_string( $report ) ) {
			$decoded = json_decode( $report, true );
			return is_array( $decoded ) && ! array_is_list( $decoded ) ? $decoded : null;
		}

		return is_array( $report ) && ! array_is_list( $report ) ? $report : null;
	}

	/** @return array<int,array<string,mixed>> */
	private static function collectDiagnostics( array $report ): array {
		$diagnostics = array();
		foreach ( array( $report['diagnostics'] ?? array(), $report['conversion_report']['diagnostics'] ?? array() ) as $entries ) {
			if ( ! is_array( $entries ) ) {
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( is_array( $entry ) ) {
					$diagnostics[] = $entry;
				}
			}
		}

		$unresolved_links = $report['source_documents']['unresolved_links'] ?? array();
		if ( is_array( $unresolved_links ) ) {
			foreach ( $unresolved_links as $entry ) {
				if ( is_array( $entry ) ) {
					$diagnostics[] = array_merge( array( 'type' => 'unresolved_internal_link' ), $entry );
				}
			}
		}

		return $diagnostics;
	}

	/** @return array<string,mixed>|null */
	private static function actionForDiagnostic( array $diagnostic, string $root ): ?array {
		$type = (string) ( $diagnostic['type'] ?? $diagnostic['code'] ?? '' );
		if ( '' === $type ) {
			return null;
		}

		$source_path = self::diagnosticSourcePath( $diagnostic );
		$scope       = self::resolveScopedPath( $root, $source_path );
		$base        = array(
			'diagnostic_type' => $type,
			'source'          => $source_path,
			'file'            => $scope['relative_path'],
			'absolute_file'   => $scope['absolute_path'],
			'status'          => $scope['allowed'] ? 'planned' : 'refused',
			'safety'          => $scope['safety'],
			'details'         => $diagnostic,
		);

		if ( ! $scope['allowed'] ) {
			return array_merge(
				$base,
				array(
					'category' => 'safety',
					'action'   => 'refuse_out_of_root_file_edit',
					'summary'  => 'Refused diagnostic repair because the source path does not resolve inside source_tree_path.',
				)
			);
		}

		if ( in_array( $type, array( 'unresolved_internal_link', 'broken_link' ), true ) ) {
			return array_merge(
				$base,
				array(
					'category' => 'broken_link',
					'action'   => 'repair_local_link_reference',
					'target'   => (string) ( $diagnostic['href'] ?? $diagnostic['url'] ?? '' ),
					'summary'  => 'Inspect the source link and rewrite it to an imported source document or remove the stale local reference.',
				)
			);
		}

		if ( in_array( $type, array( 'local_asset_not_materialized', 'unresolved_asset', 'missing_asset' ), true ) ) {
			return array_merge(
				$base,
				array(
					'category' => 'unresolved_asset',
					'action'   => 'materialize_or_repoint_asset',
					'target'   => (string) ( $diagnostic['href'] ?? $diagnostic['src'] ?? $diagnostic['asset'] ?? '' ),
					'summary'  => 'Restore the missing local asset under the source tree or update the source reference to an available asset.',
				)
			);
		}

		if ( in_array( $type, array( 'unsupported_html_fallback', 'core_html_block', 'core_html_fallback' ), true ) ) {
			return array_merge(
				$base,
				array(
					'category' => 'fallback_block',
					'action'   => 'replace_unsupported_markup_with_convertible_source',
					'selector' => (string) ( $diagnostic['selector'] ?? '' ),
					'summary'  => 'Adjust the source markup so the converter can emit native blocks instead of core/html fallback.',
				)
			);
		}

		if ( in_array( $type, array( 'conversion_failed', 'possible_text_loss', 'invalid_block_document', 'source_region_unassigned' ), true ) ) {
			return array_merge(
				$base,
				array(
					'category' => 'conversion_issue',
					'action'   => 'inspect_conversion_source_region',
					'summary'  => 'Inspect source region selection and conversion output before attempting a source-level repair.',
				)
			);
		}

		if ( 'unsupported_source_document' === $type ) {
			return array_merge(
				$base,
				array(
					'category' => 'unsupported_source',
					'action'   => 'convert_source_document_to_supported_format',
					'summary'  => 'Convert the unsupported source document to static HTML, Markdown, or another SSI-supported input before import.',
				)
			);
		}

		return null;
	}

	private static function diagnosticSourcePath( array $diagnostic ): string {
		foreach ( array( 'source_path', 'source', 'file', 'path' ) as $key ) {
			if ( ! empty( $diagnostic[ $key ] ) && is_string( $diagnostic[ $key ] ) ) {
				return self::normalizeSourceLabel( $diagnostic[ $key ] );
			}
		}

		return '';
	}

	private static function normalizeSourceLabel( string $source ): string {
		$source = trim( str_replace( '\\', '/', $source ) );
		if ( preg_match( '/^[a-z][a-z0-9+.-]*:\/\//i', $source ) ) {
			return $source;
		}

		if ( str_contains( $source, ':' ) ) {
			$parts  = explode( ':', $source );
			$source = (string) end( $parts );
		}

		return ltrim( $source, '/' );
	}

	/** @return array{allowed:bool,relative_path:string,absolute_path:string,safety:array<string,string>} */
	private static function resolveScopedPath( string $root, string $path ): array {
		$path = trim( str_replace( '\\', '/', $path ) );
		if ( '' === $path ) {
			return array(
				'allowed'       => true,
				'relative_path' => '',
				'absolute_path' => '',
				'safety'        => array( 'status' => 'no_file_target' ),
			);
		}

		if ( str_contains( $path, "\0" ) || preg_match( '/^[a-z][a-z0-9+.-]*:\/\//i', $path ) ) {
			return self::refusedScope( $path, 'unsupported_or_unsafe_path' );
		}

		$absolute = str_starts_with( $path, '/' ) ? $path : $root . '/' . ltrim( $path, '/' );
		$parent   = realpath( dirname( $absolute ) );
		if ( false === $parent ) {
			$parent = self::normalizePathWithoutFilesystem( dirname( $absolute ) );
		}

		$normalized = rtrim( str_replace( '\\', '/', $parent ), '/' ) . '/' . basename( $absolute );
		if ( ! self::pathIsUnderRoot( $normalized, $root ) ) {
			return self::refusedScope( $path, 'out_of_root' );
		}

		return array(
			'allowed'       => true,
			'relative_path' => ltrim( substr( $normalized, strlen( $root ) ), '/' ),
			'absolute_path' => $normalized,
			'safety'        => array( 'status' => 'inside_source_tree' ),
		);
	}

	/** @return array{allowed:bool,relative_path:string,absolute_path:string,safety:array<string,string>} */
	private static function refusedScope( string $path, string $reason ): array {
		return array(
			'allowed'       => false,
			'relative_path' => $path,
			'absolute_path' => '',
			'safety'        => array(
				'status' => 'refused',
				'reason' => $reason,
			),
		);
	}

	private static function normalizePathWithoutFilesystem( string $path ): string {
		$segments = array();
		foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}

		return '/' . implode( '/', $segments );
	}

	private static function pathIsUnderRoot( string $path, string $root ): bool {
		$path = rtrim( str_replace( '\\', '/', $path ), '/' );
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );

		return $path === $root || str_starts_with( $path, $root . '/' );
	}

	/** @return array<int,array<string,mixed>> */
	private static function collectRequestedEdits( array $params, array $report ): array {
		$edits = array();
		foreach ( array( $params['requested_edits'] ?? null, $params['proposed_edits'] ?? null, $report['requested_edits'] ?? null, $report['proposed_edits'] ?? null ) as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			foreach ( $candidate as $edit ) {
				if ( is_array( $edit ) ) {
					$edits[] = $edit;
				}
			}
		}

		return $edits;
	}

	/** @return array<string,mixed> */
	private static function actionForRequestedEdit( array $edit, string $root ): array {
		$operation      = strtolower( (string) ( $edit['operation'] ?? $edit['op'] ?? 'update' ) );
		$destructive    = in_array( $operation, array( 'delete', 'remove', 'rm', 'rmdir', 'unlink', 'move' ), true );
		$scope          = self::resolveScopedPath( $root, (string) ( $edit['path'] ?? $edit['file'] ?? '' ) );
		$allowed        = ! $destructive && $scope['allowed'];
		$refusal_reason = $destructive ? 'destructive_operation' : ( $scope['safety']['reason'] ?? '' );

		return array(
			'diagnostic_type' => 'requested_edit',
			'category'        => 'safety',
			'action'          => $allowed ? 'validate_sandbox_file_update' : 'refuse_unsafe_file_edit',
			'operation'       => $operation,
			'file'            => $scope['relative_path'],
			'absolute_file'   => $scope['absolute_path'],
			'source'          => (string) ( $edit['path'] ?? $edit['file'] ?? '' ),
			'status'          => $allowed ? 'planned' : 'refused',
			'safety'          => $allowed ? $scope['safety'] : array_merge( $scope['safety'], array( 'reason' => $refusal_reason ) ),
			'summary'         => $allowed ? 'Requested source-tree file update is within sandbox scope.' : 'Refused requested edit because it is destructive or outside source_tree_path.',
			'details'         => $edit,
		);
	}

	/** @return array<string,mixed> */
	private static function summarizeActions( array $actions, int $diagnostic_count ): array {
		$by_category = array();
		$status      = array(
			'planned' => 0,
			'refused' => 0,
		);

		foreach ( $actions as $action ) {
			$category                 = (string) ( $action['category'] ?? 'unknown' );
			$by_category[ $category ] = ( $by_category[ $category ] ?? 0 ) + 1;
			$action_status            = (string) ( $action['status'] ?? 'planned' );
			$status[ $action_status ] = ( $status[ $action_status ] ?? 0 ) + 1;
		}

		ksort( $by_category, SORT_STRING );
		ksort( $status, SORT_STRING );

		return array(
			'diagnostic_count' => $diagnostic_count,
			'action_count'     => count( $actions ),
			'by_category'      => $by_category,
			'by_status'        => $status,
			'changed_files'    => array(),
			'mutated_host'     => false,
		);
	}
}
