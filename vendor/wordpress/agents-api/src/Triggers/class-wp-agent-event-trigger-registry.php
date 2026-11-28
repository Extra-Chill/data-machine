<?php
/**
 * In-memory registry of code-defined event triggers.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Triggers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Event_Trigger_Registry {

	/** @var array<string, WP_Agent_Event_Trigger> */
	private static array $triggers = array();

	/**
	 * @param array<string, mixed> $args See {@see WP_Agent_Event_Trigger::__construct()}.
	 * @return WP_Agent_Event_Trigger|WP_Error
	 */
	public static function register( string $id, array $args ) {
		try {
			$trigger = new WP_Agent_Event_Trigger( $id, $args );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'invalid_event_trigger', $e->getMessage() );
		}

		self::$triggers[ $trigger->get_id() ] = $trigger;

		/**
		 * Fires after an event trigger is added to the in-memory registry.
		 *
		 * @param WP_Agent_Event_Trigger $trigger Registered trigger.
		 */
		do_action( 'wp_agent_event_trigger_registered', $trigger );

		return $trigger;
	}

	/** @return true|WP_Error */
	public static function unregister( string $trigger_id ) {
		if ( ! isset( self::$triggers[ $trigger_id ] ) ) {
			return new WP_Error(
				'not_registered',
				sprintf( 'no event trigger registered with id `%s`', $trigger_id )
			);
		}

		$trigger = self::$triggers[ $trigger_id ];
		unset( self::$triggers[ $trigger_id ] );

		/**
		 * Fires after an event trigger is removed from the in-memory registry.
		 *
		 * @param WP_Agent_Event_Trigger $trigger Removed trigger.
		 */
		do_action( 'wp_agent_event_trigger_unregistered', $trigger );

		return true;
	}

	public static function find( string $trigger_id ): ?WP_Agent_Event_Trigger {
		return self::$triggers[ $trigger_id ] ?? null;
	}

	/** @return WP_Agent_Event_Trigger[] */
	public static function all(): array {
		return array_values( self::$triggers );
	}

	public static function reset(): void {
		self::$triggers = array();
	}
}
