<?php
/**
 * Pending action observer registry.
 *
 * @package DataMachine\Engine\AI\Actions
 */

namespace DataMachine\Engine\AI\Actions;

use AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Observer;

defined( 'ABSPATH' ) || exit;

final class PendingActionObservers {

	/**
	 * Registered observers.
	 *
	 * @var array<int,WP_Agent_Pending_Action_Observer>
	 */
	private static array $observers = array();

	public static function register( WP_Agent_Pending_Action_Observer $observer ): void {
		foreach ( self::$observers as $registered ) {
			if ( $registered === $observer || get_class( $registered ) === get_class( $observer ) ) {
				return;
			}
		}

		self::$observers[] = $observer;
	}

	public static function unregister( WP_Agent_Pending_Action_Observer $observer ): void {
		self::$observers = array_values(
			array_filter(
				self::$observers,
				static fn( WP_Agent_Pending_Action_Observer $registered ): bool => $registered !== $observer && get_class( $registered ) !== get_class( $observer )
			)
		);
	}

	/**
	 * @return array<int,WP_Agent_Pending_Action_Observer>
	 */
	public static function list(): array {
		return self::$observers;
	}

	public static function dispatch_stored( WP_Agent_Pending_Action $action ): void {
		foreach ( self::$observers as $observer ) {
			self::call_observer(
				$observer,
				'on_stored',
				static function () use ( $observer, $action ): void {
					$observer->on_stored( $action );
				}
			);
		}
	}

	public static function dispatch_resolved( WP_Agent_Pending_Action $action, WP_Agent_Approval_Decision $decision, string $resolver ): void {
		foreach ( self::$observers as $observer ) {
			self::call_observer(
				$observer,
				'on_resolved',
				static function () use ( $observer, $action, $decision, $resolver ): void {
					$observer->on_resolved( $action, $decision, $resolver );
				}
			);
		}
	}

	public static function dispatch_expired( WP_Agent_Pending_Action $action ): void {
		foreach ( self::$observers as $observer ) {
			self::call_observer(
				$observer,
				'on_expired',
				static function () use ( $observer, $action ): void {
					$observer->on_expired( $action );
				}
			);
		}
	}

	public static function reset(): void {
		self::$observers = array();
	}

	private static function call_observer( WP_Agent_Pending_Action_Observer $observer, string $method, callable $callback ): void {
		try {
			$callback();
		} catch ( \Throwable $error ) {
			do_action(
				'datamachine_log',
				'error',
				'Pending action observer failed: ' . $error->getMessage(),
				array(
					'observer' => get_class( $observer ),
					'method'   => $method,
				)
			);
		}
	}
}
