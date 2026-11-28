<?php
/**
 * Event Ownership
 *
 * Decides whether an event belongs to a person ("human-owned") or to the
 * import pipeline. Imports that match a human-owned event run in fill-only
 * mode (see EventUpsert and HumanOwnedEventMerge) so a person's copy, flyer,
 * and editorial choices survive a later scrape of the same show.
 *
 * An event is human-owned when, in order:
 *  1. An operator explicitly released it to the importer
 *     (`_datamachine_event_ownership` = `import`)            → not owned.
 *  2. A person created or edited it at an interactive write point
 *     (`_datamachine_event_ownership` = `human`)             → owned.
 *  3. It carries the upsert's submission provenance
 *     (`_datamachine_submitted_by`, `_datamachine_submitter_email`,
 *     `_datamachine_submitter_name`)                          → owned.
 * The `datamachine_events_is_human_owned_event` filter has the final say.
 *
 * The explicit flag is written only at interactive write points — the block
 * editor / REST update of the event post type, the classic editor and quick
 * edit, and the `update-event` ability — and only when a real logged-in user
 * acts outside the import lifecycle (never in cron, WP-CLI, agent, system,
 * or pre-authenticated server contexts). Pipeline writes are bracketed by
 * enterPipelineWrite()/exitPipelineWrite() so nothing they trigger can flip it.
 *
 * Ownership is deliberately not inferred from post_modified (the importer
 * updates posts too) or from a missing source-identity meta key (most
 * historical imports predate source identities).
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Human/import ownership of event posts.
 */
class EventOwnership {

	/** Explicit ownership state: `human` or `import`. Absent means derived. */
	public const OWNERSHIP_META_KEY = '_datamachine_event_ownership';

	/** User ID that last set the explicit ownership state (0 for operators/CLI). */
	public const OWNERSHIP_BY_META_KEY = '_datamachine_event_ownership_by';

	/**
	 * Secondary source identities recorded by fill-only imports.
	 *
	 * Multi-valued. Source-identity matching reads this alongside the primary
	 * `_datamachine_event_source_identity` claim so re-imports keep resolving
	 * to the same human-owned post, but it never stamps the importer's
	 * primary source fields onto that post.
	 */
	public const LINKED_SOURCE_IDENTITY_META_KEY = '_datamachine_event_linked_source_identity';

	public const OWNER_HUMAN  = 'human';
	public const OWNER_IMPORT = 'import';

	/** Submission provenance stamped by EventUpsert::buildEventMetaInput(). */
	private const SUBMISSION_META_KEYS = array(
		'_datamachine_submitted_by',
		'_datamachine_submitter_email',
		'_datamachine_submitter_name',
	);

	/** @var int Nesting depth of pipeline-owned writes. */
	private static int $pipeline_depth = 0;

	/** @var bool Whether hooks are registered. */
	private static bool $registered = false;

	/**
	 * Register the interactive write-point hooks.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'rest_after_insert_' . Event_Post_Type::POST_TYPE, array( __CLASS__, 'onRestInsert' ), 10, 3 );
		add_action( 'save_post_' . Event_Post_Type::POST_TYPE, array( __CLASS__, 'onAdminSave' ), 10, 3 );
	}

	/**
	 * Whether an event is human-owned.
	 *
	 * @param int $post_id Event post ID.
	 * @return bool
	 */
	public static function isHumanOwned( int $post_id ): bool {
		$owned = false;
		if ( $post_id > 0 ) {
			$explicit = self::explicitOwnership( $post_id );
			if ( self::OWNER_HUMAN === $explicit ) {
				$owned = true;
			} elseif ( self::OWNER_IMPORT !== $explicit ) {
				$owned = self::hasSubmissionProvenance( $post_id );
			}
		}

		/**
		 * Filters whether an event is human-owned.
		 *
		 * Human-owned events are protected from import overwrites: a matching
		 * import only fills empty fields. Consumers can mark events they know
		 * a person owns (or hand events back to the importer).
		 *
		 * @param bool $owned   Whether the event is human-owned.
		 * @param int  $post_id Event post ID.
		 */
		return (bool) apply_filters( 'datamachine_events_is_human_owned_event', $owned, $post_id );
	}

	/**
	 * Explicit ownership state, or '' when ownership is derived.
	 *
	 * @param int $post_id Event post ID.
	 * @return string `human`, `import`, or ''.
	 */
	public static function explicitOwnership( int $post_id ): string {
		$value = (string) get_post_meta( $post_id, self::OWNERSHIP_META_KEY, true );
		return in_array( $value, array( self::OWNER_HUMAN, self::OWNER_IMPORT ), true ) ? $value : '';
	}

	/**
	 * Whether the event carries the upsert's submission provenance.
	 *
	 * @param int $post_id Event post ID.
	 * @return bool
	 */
	public static function hasSubmissionProvenance( int $post_id ): bool {
		foreach ( self::SUBMISSION_META_KEYS as $meta_key ) {
			if ( '' !== (string) get_post_meta( $post_id, $meta_key, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Record a person's ownership of an event.
	 *
	 * @param int $post_id Event post ID.
	 * @param int $user_id Acting user ID.
	 */
	public static function markHumanOwned( int $post_id, int $user_id ): void {
		self::setOwnership( $post_id, self::OWNER_HUMAN, $user_id );
	}

	/**
	 * Set the explicit ownership state.
	 *
	 * @param int    $post_id   Event post ID.
	 * @param string $ownership `human`, `import`, or `auto` (clear; derive again).
	 * @param int    $user_id   Acting user ID (0 for operators/CLI).
	 * @return bool False when the ownership value is unknown.
	 */
	public static function setOwnership( int $post_id, string $ownership, int $user_id = 0 ): bool {
		if ( 'auto' === $ownership ) {
			delete_post_meta( $post_id, self::OWNERSHIP_META_KEY );
			delete_post_meta( $post_id, self::OWNERSHIP_BY_META_KEY );
			return true;
		}
		if ( ! in_array( $ownership, array( self::OWNER_HUMAN, self::OWNER_IMPORT ), true ) ) {
			return false;
		}
		update_post_meta( $post_id, self::OWNERSHIP_META_KEY, $ownership );
		update_post_meta( $post_id, self::OWNERSHIP_BY_META_KEY, max( 0, $user_id ) );
		return true;
	}

	/**
	 * Record a source identity as a secondary link on a human-owned event.
	 *
	 * @param int    $post_id         Event post ID.
	 * @param string $source_identity Hashed source identity.
	 */
	public static function linkSourceIdentity( int $post_id, string $source_identity ): void {
		if ( '' === $source_identity ) {
			return;
		}
		$linked = array_map( 'strval', get_post_meta( $post_id, self::LINKED_SOURCE_IDENTITY_META_KEY, false ) );
		if ( in_array( $source_identity, $linked, true ) ) {
			return;
		}
		add_post_meta( $post_id, self::LINKED_SOURCE_IDENTITY_META_KEY, $source_identity );
	}

	/**
	 * Whether an event links (secondarily) to a source identity.
	 *
	 * @param int    $post_id         Event post ID.
	 * @param string $source_identity Hashed source identity.
	 * @return bool
	 */
	public static function linksSourceIdentity( int $post_id, string $source_identity ): bool {
		if ( '' === $source_identity ) {
			return false;
		}
		return in_array( $source_identity, array_map( 'strval', get_post_meta( $post_id, self::LINKED_SOURCE_IDENTITY_META_KEY, false ) ), true );
	}

	/** Enter a pipeline-owned write; nested writes are counted. */
	public static function enterPipelineWrite(): void {
		++self::$pipeline_depth;
	}

	/** Leave a pipeline-owned write. */
	public static function exitPipelineWrite(): void {
		self::$pipeline_depth = max( 0, self::$pipeline_depth - 1 );
	}

	/**
	 * Whether a pipeline-owned write is in progress.
	 *
	 * @return bool
	 */
	public static function inPipelineWrite(): bool {
		return self::$pipeline_depth > 0;
	}

	/**
	 * Whether the current write is made by a real person.
	 *
	 * WP-CLI is excluded: on most installs it is the maintenance and
	 * automation surface (bulk repairs, agents), so CLI writes never take
	 * ownership implicitly. Operators protect an event explicitly with the
	 * `event-ownership` command instead.
	 *
	 * @return bool
	 */
	public static function isActingHuman(): bool {
		if ( self::inPipelineWrite() || get_current_user_id() <= 0 || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}

		$permissions = '\\DataMachine\\Abilities\\PermissionHelper';
		if ( class_exists( $permissions ) ) {
			if ( $permissions::in_agent_context() || $permissions::is_system_context() || $permissions::is_authenticated_context() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Block editor and REST API saves of the event post type.
	 *
	 * @param mixed            $post     Saved post (\WP_Post).
	 * @param \WP_REST_Request $request  Request.
	 * @param bool             $creating Whether the post was created.
	 */
	public static function onRestInsert( $post, $request, $creating ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || ! self::isActingHuman() ) {
			return;
		}
		self::markHumanOwned( (int) $post->ID, get_current_user_id() );
	}

	/**
	 * Classic editor (post.php `editpost`) and quick edit (`inline-save`) saves.
	 *
	 * WordPress core verifies the request nonce and edit capability before
	 * these saves run; this hook only reads which post the request targets.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 */
	public static function onAdminSave( $post_id, $post, $update ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( ! is_admin() || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( (int) $post_id ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Core verified the editpost/inline-save nonce before saving.
		$action    = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		$target_id = isset( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $action, array( 'editpost', 'inline-save' ), true ) || (int) $post_id !== $target_id ) {
			return;
		}
		if ( ! self::isActingHuman() ) {
			return;
		}
		self::markHumanOwned( (int) $post_id, get_current_user_id() );
	}
}
