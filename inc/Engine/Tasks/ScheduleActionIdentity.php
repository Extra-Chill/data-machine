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
			if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) || self::logicalArgs( $action->get_args() ) !== $args ) {
				continue;
			}
			$date = $action->get_schedule()->get_date();
			return $date ? $date->getTimestamp() : false;
		}

		return false;
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
		$action_id  = is_array( $action_ids ) ? reset( $action_ids ) : 0;

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

		return is_array( $actions ) ? count( $actions ) : 0;
	}

	public static function cancelExact( int $action_id ): bool {
		if ( $action_id <= 0 || ! class_exists( '\\ActionScheduler_Store' ) ) {
			return false;
		}

		try {
			$store = \ActionScheduler_Store::instance();
			$store->cancel_action( $action_id );
			return \ActionScheduler_Store::STATUS_CANCELED === $store->get_status( $action_id );
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
		return is_array( $actions ) ? $actions : array();
	}
}
