<?php
/**
 * Generation-aware Action Scheduler identity helpers.
 *
 * @package DataMachine\Engine\Tasks
 */

namespace DataMachine\Engine\Tasks;

defined( 'ABSPATH' ) || exit;

final class ScheduleActionIdentity {
	private const GENERATION_KEY    = '_datamachine_schedule_generation';
	private const LOGICAL_COUNT_KEY = '_datamachine_signature_argument_count';

	public static function withGeneration( array $args, string $generation, int $argument_index ): array {
		$logical_count = count( $args );
		$target_count  = max( $logical_count, $argument_index );
		for ( $argument_count = $logical_count; $argument_count < $target_count; ++$argument_count ) {
			$args[] = null;
		}
		$args[] = array(
			self::GENERATION_KEY    => $generation,
			self::LOGICAL_COUNT_KEY => $logical_count,
		);

		return $args;
	}

	public static function generationFromArgument( $argument ): ?string {
		if ( ! is_array( $argument ) || empty( $argument[ self::GENERATION_KEY ] ) || ! is_string( $argument[ self::GENERATION_KEY ] ) ) {
			return null;
		}

		return $argument[ self::GENERATION_KEY ];
	}

	public static function generationFromArgs( array $args ): ?string {
		return empty( $args ) ? null : self::generationFromArgument( end( $args ) );
	}

	public static function logicalArgs( array $args ): array {
		if ( empty( $args ) ) {
			return $args;
		}

		$marker = end( $args );
		if ( null === self::generationFromArgument( $marker ) ) {
			return $args;
		}

		$count = isset( $marker[ self::LOGICAL_COUNT_KEY ] ) ? (int) $marker[ self::LOGICAL_COUNT_KEY ] : count( $args ) - 1;
		return array_slice( $args, 0, max( 0, $count ) );
	}

	public static function hasCoverage( string $hook, array $args, string $group, bool $generated_only = false ): bool {
		foreach ( array( 'pending', 'in-progress' ) as $status ) {
			foreach ( self::actions( $hook, $group, $status ) as $action ) {
				if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) ) {
					continue;
				}
				$action_args = $action->get_args();
				if ( self::logicalArgs( $action_args ) === $args
					&& ( ! $generated_only || null !== self::generationFromArgs( $action_args ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @return int|false
	 */
	public static function nextTimestamp( string $hook, array $args, string $group ) {
		foreach ( self::actions( $hook, $group, 'pending', true ) as $action ) {
			if ( ! is_object( $action )
				|| ! method_exists( $action, 'get_args' )
				|| ! method_exists( $action, 'get_schedule' )
				|| self::logicalArgs( $action->get_args() ) !== $args ) {
				continue;
			}
			$date = $action->get_schedule()->get_date();
			return $date ? $date->getTimestamp() : false;
		}

		return false;
	}

	/**
	 * Batch-resolve the earliest pending scheduled date for logical identities.
	 *
	 * Aggregates one hook's pending actions in a single query and matches
	 * stored args through the logical identity, so legacy and generated
	 * argument shapes both resolve. Rows are not filtered by AS group; the
	 * hook is expected to be plugin-namespaced.
	 *
	 * @param string            $hook              AS hook.
	 * @param array<int, array> $logical_args_list Logical signatures keyed by caller keys (e.g. flow IDs).
	 * @return array<int|string, string|null> Earliest pending UTC datetime per input key, null when none pending.
	 */
	public static function nextScheduledDates( string $hook, array $logical_args_list ): array {
		$result = array_fill_keys( array_keys( $logical_args_list ), null );
		if ( empty( $logical_args_list ) ) {
			return $result;
		}

		$wanted = array();
		foreach ( $logical_args_list as $caller_key => $logical_args ) {
			$encoded_request = wp_json_encode( $logical_args );
			if ( is_string( $encoded_request ) ) {
				$wanted[ $encoded_request ] = $caller_key;
			}
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Identity resolution requires fresh AS runtime state across batch callers; the generated SQL pairs one %s/%i set with $values.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT args, MIN(scheduled_date_gmt) AS next_run
				FROM %i
				WHERE hook = %s
				AND status = 'pending'
				GROUP BY args",
				$wpdb->prefix . 'actionscheduler_actions',
				$hook
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! is_array( $rows ) ) {
			return $result;
		}

		foreach ( $rows as $row ) {
			$decoded = json_decode( (string) ( $row['args'] ?? '' ), true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			$encoded = wp_json_encode( self::logicalArgs( $decoded ) );
			if ( is_string( $encoded ) && isset( $wanted[ $encoded ] ) ) {
				$caller_key = $wanted[ $encoded ];
				if ( null === $result[ $caller_key ] ) {
					$result[ $caller_key ] = (string) ( $row['next_run'] ?? '' );
				}
			}
		}

		return $result;
	}

	public static function countPending( string $hook, array $args, string $group ): int {
		$count = 0;
		foreach ( self::actions( $hook, $group, 'pending' ) as $action ) {
			if ( is_object( $action ) && method_exists( $action, 'get_args' ) && self::logicalArgs( $action->get_args() ) === $args ) {
				++$count;
			}
		}

		return $count;
	}

	public static function exactAction( string $hook, array $args, string $group, array $statuses ): ?object {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return null;
		}

		foreach ( $statuses as $status ) {
			$actions = as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'args'     => $args,
					'group'    => $group,
					'status'   => $status,
					'orderby'  => 'date',
					'order'    => 'ASC',
					'per_page' => 1,
				),
				'OBJECT'
			);

			$action = reset( $actions );
			if ( is_object( $action ) ) {
				return $action;
			}
		}

		return null;
	}

	public static function exactActionId( string $hook, array $args, string $group, string $status ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		$action_ids = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => $group,
				'status'   => $status,
				'per_page' => 1,
			),
			'ids'
		);
		$action_id  = reset( $action_ids );

		return is_numeric( $action_id ) && (int) $action_id > 0 ? (int) $action_id : 0;
	}

	public static function countExactPending( string $hook, array $args, string $group ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => $group,
				'status'   => 'pending',
				'per_page' => 100,
			),
			'ids'
		);

		return count( $actions );
	}

	public static function cancelExact( int $action_id ): bool {
		if ( $action_id <= 0 || ! class_exists( '\\ActionScheduler_Store' ) ) {
			return false;
		}

		try {
			$store = \ActionScheduler_Store::instance();
			$store->cancel_action( (string) $action_id );
			return \ActionScheduler_Store::STATUS_CANCELED === $store->get_status( (string) $action_id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	/**
	 * Cancel pending actions matching one logical identity by exact action ID.
	 *
	 * @return int Zero on success, or the exact action ID that failed cleanup.
	 */
	public static function cancelPending( string $hook, array $args, string $group ): int {
		foreach ( self::actions( $hook, $group, 'pending' ) as $action_id => $action ) {
			if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) || self::logicalArgs( $action->get_args() ) !== $args ) {
				continue;
			}
			if ( ! self::cancelExact( (int) $action_id ) ) {
				return (int) $action_id;
			}
		}

		return 0;
	}

	private static function actions( string $hook, string $group, string $status, bool $ordered = false ): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array();
		}

		$query = array(
			'hook'     => $hook,
			'group'    => $group,
			'status'   => $status,
			'per_page' => -1,
		);
		if ( $ordered ) {
			$query['orderby'] = 'date';
			$query['order']   = 'ASC';
		}

		$actions = as_get_scheduled_actions( $query, 'OBJECT' );
		return $actions;
	}
}
