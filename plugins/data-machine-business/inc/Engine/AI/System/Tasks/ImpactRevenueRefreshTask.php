<?php
/**
 * Impact Revenue Refresh System Task.
 *
 * Keeps Impact (impact.com) affiliate commissions current in the revenue
 * ledger. Each daily tick re-reads the trailing 45-day UTC window (one Impact
 * Actions chunk, one request) and stores it through
 * ImpactReportsAbilities::fetch( store: true ). Ingest replaces the window, so
 * the refresh is idempotent and catches PENDING actions that later flip to
 * APPROVED or REVERSED.
 *
 *   wp datamachine system run impact_revenue_refresh
 *   wp datamachine jobs list --task=impact_revenue_refresh
 *
 * The cadence is a `datamachine_recurring_schedules` binding on DM's built-in
 * `daily` interval. It is enabled only when `datamachine_impact_config` holds
 * both `account_sid` and `auth_token`, and is owned by the network main site.
 *
 * @package DataMachineBusiness\Engine\AI\System\Tasks
 * @see https://github.com/Extra-Chill/data-machine/issues/4056
 */

namespace DataMachineBusiness\Engine\AI\System\Tasks;

use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachineBusiness\Abilities\Analytics\ImpactReportsAbilities;

defined( 'ABSPATH' ) || exit;

class ImpactRevenueRefreshTask extends SystemTask {

	public const TASK_TYPE = 'impact_revenue_refresh';

	private static bool $registered = false;

	/**
	 * Register the task handler and its daily schedule binding.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		add_filter( 'datamachine_tasks', array( self::class, 'registerTask' ) );
		add_filter( 'datamachine_recurring_schedules', array( self::class, 'registerSchedule' ) );
		self::$registered = true;
	}

	/**
	 * @param array<string, string> $tasks Registered task handlers.
	 * @return array<string, string>
	 */
	public static function registerTask( array $tasks ): array {
		$tasks[ self::TASK_TYPE ] = self::class;

		return $tasks;
	}

	/**
	 * @param array<string, array<string, mixed>> $schedules Registered schedule definitions.
	 * @return array<string, array<string, mixed>>
	 */
	public static function registerSchedule( array $schedules ): array {
		$schedules[ self::TASK_TYPE ] = self::scheduleDefinition();

		return $schedules;
	}

	/**
	 * Daily schedule definition. `default_enabled` follows the credentials, so
	 * an unconfigured install never schedules the routine; `network_only`
	 * keeps one recurrence on the network main site.
	 *
	 * @return array<string, mixed>
	 */
	public static function scheduleDefinition(): array {
		return array(
			'task_type'            => self::TASK_TYPE,
			'interval'             => 'daily',
			'default_enabled'      => self::isConfigured(),
			'label'                => 'Daily — refreshes the trailing 45-day Impact commission window in the revenue ledger',
			'network_only'         => true,
			'first_run_callback'   => 'strtotime',
			'first_run_arg'        => '+1 day',
			'task_params_callback' => static function (): array {
				return array();
			},
		);
	}

	/**
	 * Whether Impact credentials are present.
	 */
	public static function isConfigured(): bool {
		$config = ImpactReportsAbilities::get_config();

		return '' !== trim( (string) ( $config['account_sid'] ?? '' ) )
			&& '' !== trim( (string) ( $config['auth_token'] ?? '' ) );
	}

	/**
	 * Trailing refresh window: the last MAX_WINDOW_DAYS UTC days, inclusive of today.
	 *
	 * @param int|null $now Unix timestamp; defaults to time().
	 * @return array{start_date: string, end_date: string}
	 */
	public static function refreshWindow( ?int $now = null ): array {
		$now = $now ?? time();

		return array(
			'start_date' => gmdate( 'Y-m-d', $now - ( ImpactReportsAbilities::MAX_WINDOW_DAYS - 1 ) * DAY_IN_SECONDS ),
			'end_date'   => gmdate( 'Y-m-d', $now ),
		);
	}

	public function getTaskType(): string {
		return self::TASK_TYPE;
	}

	/**
	 * Agent-less maintenance: reads Impact and writes the revenue ledger with
	 * server-side credentials; it never acts as an agent.
	 */
	public function requiresAgentContext(): bool {
		return false;
	}

	/**
	 * @return array
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Impact Revenue Refresh',
			'description'     => 'Daily: re-reads the trailing 45-day UTC window of Impact affiliate actions and replaces it in the revenue ledger (source=impact). Runs only when datamachine_impact_config has account_sid and auth_token.',
			'setting_key'     => '',
			'default_enabled' => self::isConfigured(),
			'supports_run'    => true,
			'mutates'         => true,
		);
	}

	/**
	 * Refresh the trailing window into the revenue ledger.
	 *
	 * @param int   $jobId  DM Job ID.
	 * @param array $params Optional `dry_run` (bool).
	 */
	public function executeTask( int $jobId, array $params ): void {
		if ( is_multisite() && ! is_main_site() ) {
			$this->completeJob( $jobId, array(
				'skipped' => true,
				'reason'  => 'Impact revenue refresh runs on the network main site only.',
			) );
			return;
		}

		if ( ! self::isConfigured() ) {
			$this->completeJob( $jobId, array(
				'skipped' => true,
				'reason'  => 'Impact is not configured (' . ImpactReportsAbilities::CONFIG_OPTION . ' needs account_sid and auth_token).',
			) );
			return;
		}

		$window = self::refreshWindow();
		$result = ImpactReportsAbilities::fetch( array(
			'start_date' => $window['start_date'],
			'end_date'   => $window['end_date'],
			'store'      => true,
			'dry_run'    => ! empty( $params['dry_run'] ),
		) );

		// fetch() redacts the token from every error it returns.
		if ( is_wp_error( $result ) ) {
			$this->failJob( $jobId, 'Impact revenue refresh failed [' . $result->get_error_code() . ']: ' . $result->get_error_message() );
			return;
		}

		$ledger = is_array( $result['ledger'] ?? null ) ? $result['ledger'] : array();
		$this->completeJob( $jobId, array(
			'window'        => $window,
			'dry_run'       => ! empty( $params['dry_run'] ),
			'results_count' => (int) ( $result['results_count'] ?? 0 ),
			'totals'        => $result['totals'] ?? array(),
			'counts'        => $result['counts'] ?? array(),
			'ledger'        => array(
				'replaced' => $ledger['replaced'] ?? null,
				'inserted' => $ledger['inserted'] ?? null,
			),
		) );
	}
}
