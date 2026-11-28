<?php
/**
 * ProcessedItemsCommand source-rejected query tests.
 *
 * Executes the source-rejected / clear-source-rejected query predicate against
 * real job and processed-item rows. The CLI entry points call WP_CLI output
 * helpers, so these tests exercise the shared query builder directly.
 *
 * @package DataMachine\Tests\Unit\Cli\Commands
 * @see https://github.com/Extra-Chill/data-machine/issues/3958
 */

namespace DataMachine\Tests\Unit\Cli\Commands;

use DataMachine\Cli\Commands\ProcessedItemsCommand;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use ReflectionMethod;
use WP_UnitTestCase;

class ProcessedItemsSourceRejectedTest extends WP_UnitTestCase {

	private const FLOW_ID     = '93958';
	private const PIPELINE_ID = '3958';

	private ProcessedItems $processed;

	/** @var array<string,int> Job IDs keyed by fixture name. */
	private array $jobs = array();

	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( '\WP_CLI_Command' ) ) {
			require_once dirname( __DIR__, 3 ) . '/Doubles/AccessActionsCliCommand.php';
		}

		$this->processed = new ProcessedItems();

		$this->jobs = array(
			'legacy_string'      => $this->insert_job( 'agent_skipped - source-rejected', null, array() ),
			'legacy_structured'  => $this->insert_job( 'agent_skipped', 'source-rejected', array( 'job_status_reason' => 'source-rejected' ) ),
			'modern_rejected'    => $this->insert_job(
				'completed_no_items',
				null,
				array(
					'run_metrics'  => array( 'counts' => array( 'source_rejected' => 1 ) ),
					'step_results' => array(
						'146_bundle_step_0_' . self::FLOW_ID => array(
							'step_type'               => 'fetch',
							'result'                  => 'source_rejected',
							'packet_count'            => 0,
							'source_rejection_reason' => 'irrelevant_location',
						),
					),
				)
			),
			'modern_other'       => $this->insert_job(
				'completed_no_items',
				null,
				array(
					'step_results' => array(
						'146_bundle_step_0_' . self::FLOW_ID => array(
							'step_type'               => 'fetch',
							'result'                  => 'source_rejected',
							'source_rejection_reason' => 'non_music_event',
						),
					),
				)
			),
			'empty_no_items'     => $this->insert_job(
				'completed_no_items',
				null,
				array(
					'step_results' => array(
						'146_bundle_step_0_' . self::FLOW_ID => array(
							'step_type'    => 'fetch',
							'result'       => 'completed_no_items',
							'packet_count' => 0,
						),
					),
				)
			),
			'empty_reason_key'   => $this->insert_job(
				'completed_no_items',
				null,
				array(
					'step_results' => array(
						'146_bundle_step_0_' . self::FLOW_ID => array(
							'result'                  => 'completed_no_items',
							'source_rejection_reason' => '',
						),
					),
				)
			),
			'completed'          => $this->insert_job( 'completed', null, array() ),
			'other_skip_reason'  => $this->insert_job( 'agent_skipped', 'defer-exhausted', array( 'job_status_reason' => 'defer-exhausted' ) ),
		);

		foreach ( $this->jobs as $name => $job_id ) {
			$this->processed->add_processed_item( '146_bundle_step_0_' . self::FLOW_ID, 'universal_web_scraper', 'item-' . $name, $job_id );
		}
	}

	public function test_default_matches_legacy_and_modern_rejection_evidence_only(): void {
		$this->assertSame(
			array( 'legacy_string', 'legacy_structured', 'modern_other', 'modern_rejected' ),
			$this->matching_job_names( array( 'flow' => self::FLOW_ID ) )
		);
	}

	public function test_default_excludes_completed_no_items_without_rejection_evidence(): void {
		$matched = $this->matching_job_names( array( 'flow' => self::FLOW_ID ) );

		$this->assertNotContains( 'empty_no_items', $matched );
		$this->assertNotContains( 'empty_reason_key', $matched );
		$this->assertNotContains( 'other_skip_reason', $matched );
	}

	public function test_explicit_job_status_overrides_evidence_matching(): void {
		$this->assertSame(
			array( 'empty_no_items', 'empty_reason_key', 'modern_other', 'modern_rejected' ),
			$this->matching_job_names( array( 'flow' => self::FLOW_ID, 'job-status' => 'completed_no_items' ) )
		);

		$this->assertSame(
			array( 'legacy_string', 'legacy_structured' ),
			$this->matching_job_names( array( 'flow' => self::FLOW_ID, 'job-status' => 'agent_skipped - source-rejected' ) )
		);
	}

	public function test_reason_filter_matches_rejection_reason_substring(): void {
		$this->assertSame(
			array( 'modern_rejected' ),
			$this->matching_job_names( array( 'flow' => self::FLOW_ID, 'reason' => 'location' ) )
		);

		$this->assertSame(
			array(),
			$this->matching_job_names( array( 'flow' => self::FLOW_ID, 'reason' => 'no_such_reason' ) )
		);
	}

	public function test_non_processed_rows_are_not_matched(): void {
		global $wpdb;
		$job_id = $this->jobs['modern_rejected'];

		$wpdb->update(
			$this->processed->get_table_name(),
			array( 'status' => 'deferred' ),
			array( 'job_id' => $job_id )
		);

		$this->assertNotContains( 'modern_rejected', $this->matching_job_names( array( 'flow' => self::FLOW_ID ) ) );
	}

	/**
	 * Run the command's source-rejected predicate and return matched fixture names.
	 *
	 * @param array $assoc_args CLI associative args.
	 * @return string[] Sorted fixture names.
	 */
	private function matching_job_names( array $assoc_args ): array {
		global $wpdb;

		$method = new ReflectionMethod( ProcessedItemsCommand::class, 'build_source_rejected_query_parts' );
		$method->setAccessible( true );
		$parts = $method->invoke( new ProcessedItemsCommand(), $assoc_args, $this->processed->get_table_name(), $wpdb->prefix . 'datamachine_jobs' );

		$sql = "SELECT DISTINCT pi.job_id FROM %i pi INNER JOIN %i j ON pi.job_id = j.job_id {$parts['where_sql']}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test executes the command's prepared predicate.
		$job_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, ...$parts['values'] ) ) );

		$names = array_keys( array_intersect( $this->jobs, $job_ids ) );
		sort( $names );

		return $names;
	}

	private function insert_job( string $status, ?string $status_reason, array $engine_data ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'datamachine_jobs',
			array(
				'pipeline_id'   => self::PIPELINE_ID,
				'flow_id'       => self::FLOW_ID,
				'source'        => 'pipeline',
				'status'        => $status,
				'status_reason' => $status_reason,
				'engine_data'   => wp_json_encode( $engine_data ),
				'completed_at'  => current_time( 'mysql', true ),
			)
		);

		$this->assertGreaterThan( 0, (int) $wpdb->insert_id );

		return (int) $wpdb->insert_id;
	}
}
