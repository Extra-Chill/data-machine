<?php
/**
 * Pipeline AI concurrency limiter.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

use DataMachine\Core\OptionLeaseStore;
use DataMachine\Core\PluginSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates provider transport pressure across concurrent pipeline AI jobs.
 */
class PipelineAIConcurrencyLimiter {

	private const DEFAULT_THROTTLE_DELAY = 10;
	private const DEFAULT_TTL            = 600;
	private const OPTION_PREFIX          = 'datamachine_pipeline_ai_lease_';
	private const EXECUTE_STEP_HOOK      = 'datamachine_execute_step';
	private const ACTION_SCHEDULER_GROUP = 'data-machine';

	/**
	 * Attempt to acquire pipeline AI capacity.
	 *
	 * @param string $provider Provider slug.
	 * @param array  $context  Execution context for filters/logging.
	 * @return array{acquired:bool,lease?:PipelineAIConcurrencyLease,reason?:string,limit:int,active:int,delay:int,provider:string}
	 */
	public static function acquire( string $provider, array $context = array() ): array {
		$provider = sanitize_key( $provider );
		$token    = self::token();
		$scopes   = self::resolveScopes( $provider, $context );
		$acquired = array();

		foreach ( $scopes as $scope ) {
			$result = self::acquireScope( $scope['name'], $scope['limit'], $token, $provider, $context );
			if ( ! $result['acquired'] ) {
				( new PipelineAIConcurrencyLease( $acquired, $token ) )->release();

				return array(
					'acquired' => false,
					'reason'   => 'ai_concurrency_limit',
					'limit'    => $result['limit'],
					'active'   => $result['active'],
					'delay'    => self::throttleDelay( $provider, $context ),
					'provider' => $provider,
				);
			}

			$acquired[] = $result['option_name'];
		}

		return array(
			'acquired' => true,
			'lease'    => new PipelineAIConcurrencyLease( $acquired, $token ),
			'limit'    => $scopes[0]['limit'],
			'active'   => count( $acquired ),
			'delay'    => self::throttleDelay( $provider, $context ),
			'provider' => $provider,
		);
	}

	/**
	 * Resolve site and optional provider scopes.
	 *
	 * @return array<int,array{name:string,limit:int}>
	 */
	private static function resolveScopes( string $provider, array $context ): array {
		$site_limit = max( 1, (int) PluginSettings::resolve( 'pipeline_ai_concurrency_limit', PluginSettings::DEFAULT_PIPELINE_AI_CONCURRENCY_LIMIT ) );

		/**
		 * Filter site-wide pipeline AI concurrency.
		 *
		 * @param int    $site_limit Site-wide limit.
		 * @param string $provider   Provider slug.
		 * @param array  $context    Execution context.
		 */
		$site_limit = max( 1, (int) apply_filters( 'datamachine_pipeline_ai_concurrency_limit', $site_limit, $provider, $context ) );

		$scopes = array(
			array(
				'name'  => 'site',
				'limit' => $site_limit,
			),
		);

		$provider_limits = PluginSettings::resolve( 'pipeline_ai_provider_concurrency_limits', array() );
		$provider_limit  = is_array( $provider_limits ) ? (int) ( $provider_limits[ $provider ] ?? 0 ) : 0;

		/**
		 * Filter provider-specific pipeline AI concurrency. Return 0 to disable.
		 *
		 * @param int    $provider_limit Provider limit, or 0 when disabled.
		 * @param string $provider       Provider slug.
		 * @param array  $context        Execution context.
		 */
		$provider_limit = (int) apply_filters( 'datamachine_pipeline_ai_provider_concurrency_limit', $provider_limit, $provider, $context );

		if ( $provider_limit > 0 ) {
			$scopes[] = array(
				'name'  => 'provider_' . $provider,
				'limit' => $provider_limit,
			);
		}

		return $scopes;
	}

	/**
	 * Acquire one slot in a scope.
	 *
	 * @return array{acquired:bool,limit:int,active:int,option_name?:string}
	 */
	private static function acquireScope( string $scope, int $limit, string $token, string $provider, array $context ): array {
		$now   = time();
		$ttl   = self::ttl( $provider, $context );
		$lease = array(
			'token'        => $token,
			'provider'     => $provider,
			'created_at'   => $now,
			'expires_at'   => $now + $ttl,
			'job_id'       => (int) ( $context['job_id'] ?? 0 ),
			'flow_step_id' => (string) ( $context['flow_step_id'] ?? '' ),
		);

		return OptionLeaseStore::acquireSlot(
			self::OPTION_PREFIX,
			$scope,
			$limit,
			$lease,
			$ttl,
			$now,
			static fn( array $existing ): bool => self::isAdvancedOwnerLease( $existing )
		);
	}

	/**
	 * Resolve throttle delay.
	 */
	private static function throttleDelay( string $provider, array $context ): int {
		$delay = max( 1, (int) PluginSettings::resolve( 'pipeline_ai_throttle_delay', self::DEFAULT_THROTTLE_DELAY ) );

		/**
		 * Filter delay for jobs deferred by pipeline AI concurrency.
		 *
		 * @param int    $delay    Delay in seconds.
		 * @param string $provider Provider slug.
		 * @param array  $context  Execution context.
		 */
		return max( 1, (int) apply_filters( 'datamachine_pipeline_ai_throttle_delay', $delay, $provider, $context ) );
	}

	/**
	 * Resolve stale lease TTL.
	 */
	private static function ttl( string $provider, array $context ): int {
		/**
		 * Filter pipeline AI lease TTL.
		 *
		 * @param int    $ttl      TTL in seconds.
		 * @param string $provider Provider slug.
		 * @param array  $context  Execution context.
		 */
		return max( 30, (int) apply_filters( 'datamachine_pipeline_ai_concurrency_lease_ttl', self::DEFAULT_TTL, $provider, $context ) );
	}

	/**
	 * Check whether a lease owner has already moved to another scheduled step.
	 *
	 * @param array $lease Existing lease option value.
	 */
	private static function isAdvancedOwnerLease( array $lease ): bool {
		$job_id       = (int) ( $lease['job_id'] ?? 0 );
		$flow_step_id = (string) ( $lease['flow_step_id'] ?? '' );

		if ( $job_id <= 0 || '' === $flow_step_id || ! function_exists( 'as_get_scheduled_actions' ) || ! self::isActionSchedulerReady() ) {
			return false;
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'     => self::EXECUTE_STEP_HOOK,
				'group'    => self::ACTION_SCHEDULER_GROUP,
				'status'   => 'pending',
				'orderby'  => 'date',
				'order'    => 'ASC',
				'per_page' => 250,
			),
			'OBJECT'
		);

		foreach ( $actions as $action ) {
			$args = is_object( $action ) && method_exists( $action, 'get_args' ) ? $action->get_args() : array();
			if ( (int) ( $args['job_id'] ?? 0 ) === $job_id && (string) ( $args['flow_step_id'] ?? '' ) !== $flow_step_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether Action Scheduler can safely answer scheduled-action queries.
	 */
	private static function isActionSchedulerReady(): bool {
		if ( function_exists( 'did_action' ) && 0 === did_action( 'action_scheduler_init' ) ) {
			return false;
		}

		if ( ! class_exists( '\ActionScheduler' ) || ! method_exists( '\ActionScheduler', 'is_initialized' ) ) {
			return true;
		}

		return \ActionScheduler::is_initialized();
	}

	/**
	 * Build a per-request lease token.
	 */
	private static function token(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable ) {
			return md5( uniqid( 'datamachine_ai_lease_', true ) );
		}
	}
}
