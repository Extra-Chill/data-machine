<?php
/**
 * Generic resumable run-state vocabulary.
 *
 * This is transport- and storage-neutral. It names the lifecycle states that
 * future resumable agent/workflow runs can persist without coupling core to a
 * specific runtime, protocol, or job-table schema.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

class RunState {

	public const PENDING              = 'pending';
	public const RUNNING              = 'running';
	public const WAITING_FOR_TOOL     = 'waiting_for_tool';
	public const WAITING_FOR_INPUT    = 'waiting_for_input';
	public const WAITING_FOR_APPROVAL = 'waiting_for_approval';
	public const WAITING_FOR_CALLBACK = 'waiting_for_callback';
	public const COMPLETED            = 'completed';
	public const FAILED               = 'failed';
	public const CANCELLED            = 'cancelled';

	public const ALL_STATES = array(
		self::PENDING,
		self::RUNNING,
		self::WAITING_FOR_TOOL,
		self::WAITING_FOR_INPUT,
		self::WAITING_FOR_APPROVAL,
		self::WAITING_FOR_CALLBACK,
		self::COMPLETED,
		self::FAILED,
		self::CANCELLED,
	);

	public const WAITING_STATES = array(
		self::WAITING_FOR_TOOL,
		self::WAITING_FOR_INPUT,
		self::WAITING_FOR_APPROVAL,
		self::WAITING_FOR_CALLBACK,
	);

	public const TERMINAL_STATES = array(
		self::COMPLETED,
		self::FAILED,
		self::CANCELLED,
	);

	public static function is_valid( string $state ): bool {
		return in_array( $state, self::ALL_STATES, true );
	}

	public static function is_waiting( string $state ): bool {
		return in_array( $state, self::WAITING_STATES, true );
	}

	public static function is_terminal( string $state ): bool {
		return in_array( $state, self::TERMINAL_STATES, true );
	}
}
