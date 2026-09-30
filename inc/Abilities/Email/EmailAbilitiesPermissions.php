<?php
/**
 * Per-operation permission callbacks for the EmailAbilities CRUD abilities.
 *
 * Split out of EmailAbilities.php so the ability registration + execute
 * methods stay in one file and the permission wiring stays in another —
 * EmailAbilities.php was already near the codebase's file-size threshold
 * before these callbacks existed.
 *
 * @package DataMachine\Abilities\Email
 */

namespace DataMachine\Abilities\Email;

defined( 'ABSPATH' ) || exit;

trait EmailAbilitiesPermissions {

	use EmailMailboxPermission;

	/**
	 * Permission callback: reply to an email (requires reply authorization on the ref).
	 *
	 * @param mixed $input Normalized ability input.
	 * @return bool
	 */
	public function checkReplyPermission( $input = null ): bool {
		return $this->authorizeMailboxRef( $input, 'reply' );
	}

	/**
	 * Permission callback: delete an email.
	 *
	 * @param mixed $input Normalized ability input.
	 * @return bool
	 */
	public function checkDeletePermission( $input = null ): bool {
		return $this->authorizeMailboxRef( $input, 'delete' );
	}

	/**
	 * Permission callback: move an email (requires organize + delete on the ref).
	 *
	 * @param mixed $input Normalized ability input.
	 * @return bool
	 */
	public function checkMovePermission( $input = null ): bool {
		return $this->authorizeMailboxRef( $input, array( 'organize', 'delete' ) );
	}

	/**
	 * Permission callback: set/clear an email flag.
	 *
	 * The `Deleted` flag maps to the `delete` operation; every other flag maps
	 * to `organize`, matching the operation `executeFlag()` requires.
	 *
	 * @param mixed $input Normalized ability input.
	 * @return bool
	 */
	public function checkFlagPermission( $input = null ): bool {
		$normalized = is_array( $input ) ? $input : array();
		$operation  = 'deleted' === strtolower( (string) ( $normalized['flag'] ?? '' ) ) ? 'delete' : 'organize';

		return $this->authorizeMailboxRef( $input, $operation );
	}

	/**
	 * Permission callback: batch move emails.
	 *
	 * @param mixed $input Normalized ability input.
	 * @return bool
	 */
	public function checkBatchMovePermission( $input = null ): bool {
		return $this->authorizeMailboxRef( $input, array( 'organize', 'delete', 'search' ) );
	}

	/**
	 * Permission callback: batch set/clear an email flag.
	 *
	 * @param mixed $input Normalized ability input.
	 * @return bool
	 */
	public function checkBatchFlagPermission( $input = null ): bool {
		$normalized = is_array( $input ) ? $input : array();
		$operation  = 'deleted' === strtolower( (string) ( $normalized['flag'] ?? '' ) ) ? 'delete' : 'organize';

		return $this->authorizeMailboxRef( $input, array( $operation, 'search' ) );
	}

	/**
	 * Permission callback: batch delete emails.
	 *
	 * @param mixed $input Normalized ability input.
	 * @return bool
	 */
	public function checkBatchDeletePermission( $input = null ): bool {
		return $this->authorizeMailboxRef( $input, array( 'delete', 'search' ) );
	}

	/**
	 * Permission callback: unsubscribe from a single email.
	 *
	 * @param mixed $input Normalized ability input.
	 * @return bool
	 */
	public function checkUnsubscribePermission( $input = null ): bool {
		return $this->authorizeMailboxRef( $input, array( 'unsubscribe', 'read' ) );
	}

	/**
	 * Permission callback: batch unsubscribe.
	 *
	 * @param mixed $input Normalized ability input.
	 * @return bool
	 */
	public function checkBatchUnsubscribePermission( $input = null ): bool {
		return $this->authorizeMailboxRef( $input, array( 'unsubscribe', 'read', 'search' ) );
	}

	/**
	 * Permission callback: test the IMAP connection.
	 *
	 * @param mixed $input Normalized ability input.
	 * @return bool
	 */
	public function checkTestConnectionPermission( $input = null ): bool {
		return $this->authorizeMailboxRef( $input, 'read' );
	}
}
