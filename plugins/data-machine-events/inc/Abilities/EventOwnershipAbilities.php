<?php
/**
 * Event Ownership Abilities
 *
 * Operator surface for human/import event ownership (see EventOwnership).
 * Reads an event's effective ownership and optionally sets it: `import`
 * hands a human-owned event back to the importer, `human` protects an
 * event, and `auto` clears the explicit state so ownership is derived again.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventOwnership;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the event ownership ability.
 */
class EventOwnershipAbilities {

	public const ABILITY_NAME = 'data-machine-events/event-ownership';

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'wp_abilities_api_init', array( $this, 'registerAbility' ) );
	}

	/** Register the ability. */
	public function registerAbility(): void {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Event Ownership', 'data-machine-events' ),
				'description'         => __( 'Read or set whether an event is human-owned. Imports only fill empty fields on human-owned events; "import" hands an event back to the importer, "auto" clears the explicit state.', 'data-machine-events' ),
				'category'            => 'datamachine-events-events',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'event' ),
					'properties' => array(
						'event'     => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Event post ID',
						),
						'ownership' => array(
							'type'        => 'string',
							'enum'        => array( EventOwnership::OWNER_HUMAN, EventOwnership::OWNER_IMPORT, 'auto' ),
							'description' => 'Optional new ownership state. Omit to read only.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'event_id'                 => array( 'type' => 'integer' ),
						'human_owned'              => array( 'type' => 'boolean' ),
						'explicit_ownership'       => array( 'type' => 'string' ),
						'submission_provenance'    => array( 'type' => 'boolean' ),
						'linked_source_identities' => array( 'type' => 'integer' ),
						'changed'                  => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => AbilityPermissions::canWrite(),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Read or set an event's ownership.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute( array $input ): array|\WP_Error {
		$post_id = absint( $input['event'] ?? 0 );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || Event_Post_Type::POST_TYPE !== $post->post_type ) {
			return new \WP_Error( 'event_not_found', 'Event not found.', array( 'status' => 404 ) );
		}

		$changed = false;
		if ( isset( $input['ownership'] ) && '' !== (string) $input['ownership'] ) {
			if ( ! EventOwnership::setOwnership( $post_id, (string) $input['ownership'], get_current_user_id() ) ) {
				return new \WP_Error( 'invalid_ownership', 'Ownership must be human, import, or auto.', array( 'status' => 400 ) );
			}
			$changed = true;
		}

		return array(
			'event_id'                 => $post_id,
			'human_owned'              => EventOwnership::isHumanOwned( $post_id ),
			'explicit_ownership'       => EventOwnership::explicitOwnership( $post_id ),
			'submission_provenance'    => EventOwnership::hasSubmissionProvenance( $post_id ),
			'linked_source_identities' => count( get_post_meta( $post_id, EventOwnership::LINKED_SOURCE_IDENTITY_META_KEY, false ) ),
			'changed'                  => $changed,
		);
	}
}
