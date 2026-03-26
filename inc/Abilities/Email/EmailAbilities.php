<?php
/**
 * Email Abilities
 *
 * WordPress 6.9 Abilities API primitives for email CRUD operations.
 * Registers abilities for inbox management: reply, delete, move, flag.
 *
 * Send and Fetch abilities are registered separately in their respective files.
 * This class covers the remaining CRUD operations that require an IMAP connection.
 *
 * @package DataMachine\Abilities\Email
 */

namespace DataMachine\Abilities\Email;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class EmailAbilities {

	private static bool $registered = false;
}
