<?php
/**
 * Deterministic transaction coverage for Webhook Gate resume ownership.
 *
 * Run with: php tests/webhook-gate-single-winner-smoke.php
 */

namespace DataMachine\Core {
}

namespace DataMachine\Core\Database\RunMetadata {
	class RunMetadata {
		public function replace_for_engine_data( int $job_id, array $data ): bool {
			unset( $job_id, $data );
			return true;
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );

	function wp_json_encode( mixed $value ): string|false {
		return json_encode( $value );
	}

	function wp_cache_delete( int $key, string $group ): bool {
		$GLOBALS['cache_deletes'][] = array( $key, $group );
		return true;
	}

	function current_time( string $type, bool $gmt ): string {
		unset( $type, $gmt );
		return '2026-08-21 12:00:00';
	}

	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		unset( $hook, $args );
		return $value;
	}

	final class WebhookGateFakeWpdb {
		public string $prefix = 'wp_';
		public string $last_error = '';
		public array $row;
		public array $actions = array();
		public bool $fail_update = false;
		public bool $fail_lifecycle_update = false;
		private ?array $snapshot = null;
		private ?array $action_snapshot = null;

		public function __construct( string $token ) {
			$this->row = array(
				'job_id'      => 42,
				'status'      => 'waiting',
				'engine_data' => json_encode(
					array(
						'job_status'  => 'waiting',
						'webhook_gate' => array(
							'token'             => $token,
							'status'            => 'waiting',
							'flow_step_id'      => 'gate-step',
							'next_flow_step_id' => 'next-step',
						),
					)
			),
			);
		}

		public function get_var( string $query ): int|string|null {
			if ( str_contains( $query, '@@autocommit' ) ) {
				return 1;
			}
			if ( str_contains( $query, 'max_allowed_packet' ) ) {
				return 16777216;
			}
			return null;
		}

		public function query( string $query ): int|false {
			if ( 'START TRANSACTION' === $query ) {
				$this->snapshot = $this->row;
				$this->action_snapshot = $this->actions;
				return 1;
			}
			if ( 'ROLLBACK' === $query ) {
				if ( null !== $this->snapshot ) {
					$this->row = $this->snapshot;
				}
				if ( null !== $this->action_snapshot ) {
					$this->actions = $this->action_snapshot;
				}
				$this->snapshot = null;
				$this->action_snapshot = null;
				return 1;
			}
			if ( 'COMMIT' === $query ) {
				$this->snapshot = null;
				$this->action_snapshot = null;
				return 1;
			}
			return 1;
		}

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[ids]/', (string) $arg, $query, 1 );
			}
			return $query;
		}

		public function get_row( string $query, string $format ): ?array {
			unset( $query, $format );
			return $this->row;
		}

		public function update( string $table, array $data, array $where, array $formats, array $where_formats ): int|false {
			unset( $table, $formats, $where_formats );
			if ( $this->fail_update ) {
				$this->last_error = 'forced update failure';
				return false;
			}
			if ( $this->fail_lifecycle_update && isset( $data['engine_data'] ) && str_contains( (string) $data['engine_data'], 'run_lifecycle' ) ) {
				$this->last_error = 'forced lifecycle update failure';
				return false;
			}
			if ( (int) $where['job_id'] !== (int) $this->row['job_id'] || ( isset( $where['status'] ) && (string) $where['status'] !== (string) $this->row['status'] ) ) {
				return 0;
			}
			$this->row = array_replace( $this->row, $data );
			return 1;
		}

		public function insertAction(): int {
			$this->actions[] = count( $this->actions ) + 1;
			return count( $this->actions );
		}
	}

	require_once __DIR__ . '/../inc/Core/Database/BaseRepository.php';
	require_once __DIR__ . '/../inc/Core/Database/TransactionScope.php';
	require_once __DIR__ . '/../inc/Core/DataPacketStore.php';
	require_once __DIR__ . '/../inc/Core/JobStatus.php';
	require_once __DIR__ . '/../inc/Core/RunLifecycleStore.php';
	require_once __DIR__ . '/../inc/Core/Database/Jobs/Jobs.php';

	$assertions = 0;
	$assert = static function ( bool $condition, string $message ) use ( &$assertions ): void {
		++$assertions;
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	};
	$token = str_repeat( 'a', 64 );
	$packet = array( array( 'type' => 'webhook_payload', 'data' => array( 'body' => array( 'event' => 'accepted' ) ) ) );
	$schedule = static fn(): int => $GLOBALS['wpdb']->insertAction();

	$GLOBALS['wpdb'] = new WebhookGateFakeWpdb( $token );
	$jobs            = new \DataMachine\Core\Database\Jobs\Jobs();
	$winner          = $jobs->claim_webhook_gate_resume( 42, $token, '2026-08-21T12:00:00Z', $packet, $schedule );
	$loser           = ( new \DataMachine\Core\Database\Jobs\Jobs() )->claim_webhook_gate_resume( 42, $token, '2026-08-21T12:00:01Z', $packet, $schedule );
	$engine          = json_decode( $GLOBALS['wpdb']->row['engine_data'], true );
	$assert( $winner['owned'], 'first same-token delivery owns resume' );
	$assert( ! $loser['owned'] && $loser['already_resumed'], 'second same-token delivery is an idempotent loser' );
	$assert( 'processing' === $GLOBALS['wpdb']->row['status'], 'winner transitions job to processing' );
	$assert( 'received' === $engine['webhook_gate']['status'], 'winner persists one received receipt' );
	$assert( '2026-08-21T12:00:00Z' === $engine['webhook_gate']['received_at'], 'loser cannot overwrite winner receipt' );
	$assert( 1 === $engine['webhook_gate']['action_id'], 'winner persists the scheduler receipt' );
	$assert( array( 'event' => 'accepted' ) === $engine['step_input_packets']['next-step'][0]['data']['body'], 'winner persists exact step input' );
	$assert( 'gate-step' === $engine['step_input_packets']['next-step'][0]['metadata']['flow_step_id'], 'winner binds payload to the gate step' );
	$assert( array( 1 ) === $GLOBALS['wpdb']->actions, 'exactly one scheduler action commits' );
	$assert( ! array_key_exists( 'job_status', $engine ), 'winner clears waiting override' );
	$assert( 'processing' === $engine[ \DataMachine\Core\RunLifecycleStore::META_KEY ]['status'], 'winner commits the processing lifecycle projection' );
	$assert(
		array(
			array( 42, 'datamachine_engine_data' ),
			array( 42, 'datamachine_engine_data' ),
		) === $GLOBALS['cache_deletes'],
		'winner invalidates engine cache before and after commit'
	);

	$GLOBALS['wpdb'] = new WebhookGateFakeWpdb( $token );
	$wrong_token     = ( new \DataMachine\Core\Database\Jobs\Jobs() )->claim_webhook_gate_resume( 42, str_repeat( 'b', 64 ), '2026-08-21T12:00:00Z', $packet, $schedule );
	$assert( ! $wrong_token['success'] && 'token_mismatch' === $wrong_token['reason'], 'wrong token fails closed' );
	$assert( 'waiting' === $GLOBALS['wpdb']->row['status'], 'wrong token does not mutate job' );

	$GLOBALS['wpdb'] = new WebhookGateFakeWpdb( $token );
	$rolled_back     = ( new \DataMachine\Core\Database\Jobs\Jobs() )->claim_webhook_gate_resume( 42, $token, '2026-08-21T12:00:00Z', $packet, static function (): int {
		$GLOBALS['wpdb']->insertAction();
		$GLOBALS['wpdb']->fail_update = true;
		return 1;
	} );
	$rollback_engine              = json_decode( $GLOBALS['wpdb']->row['engine_data'], true );
	$assert( ! $rolled_back['success'] && 'lifecycle_persistence_failed' === $rolled_back['reason'], 'failed lifecycle write reports persistence failure' );
	$assert( 'waiting' === $GLOBALS['wpdb']->row['status'], 'failed transaction preserves waiting status' );
	$assert( 'waiting' === $rollback_engine['webhook_gate']['status'], 'failed transaction preserves waiting gate' );
	$assert( array() === $GLOBALS['wpdb']->actions, 'failed transaction rolls back scheduler action' );

	$GLOBALS['wpdb']                        = new WebhookGateFakeWpdb( $token );
	$GLOBALS['wpdb']->fail_lifecycle_update = true;
	$lifecycle_failure                     = ( new \DataMachine\Core\Database\Jobs\Jobs() )->claim_webhook_gate_resume( 42, $token, '2026-08-21T12:00:00Z', $packet, $schedule );
	$lifecycle_engine                      = json_decode( $GLOBALS['wpdb']->row['engine_data'], true );
	$assert( ! $lifecycle_failure['success'] && 'lifecycle_persistence_failed' === $lifecycle_failure['reason'], 'lifecycle projection failure fails the claim' );
	$assert( 'waiting' === $GLOBALS['wpdb']->row['status'] && 'waiting' === $lifecycle_engine['webhook_gate']['status'], 'lifecycle failure rolls back payload, receipt, and status' );
	$assert( array() === $GLOBALS['wpdb']->actions, 'lifecycle failure rolls back scheduler action' );

	$GLOBALS['wpdb']                     = new WebhookGateFakeWpdb( $token );
	$GLOBALS['wpdb']->row['engine_data'] = '{invalid';
	$malformed                           = ( new \DataMachine\Core\Database\Jobs\Jobs() )->claim_webhook_gate_resume( 42, $token, '2026-08-21T12:00:00Z', $packet, $schedule );
	$assert( ! $malformed['success'] && 'malformed_engine_data' === $malformed['reason'], 'malformed engine data fails closed' );

	echo "Webhook Gate single-winner smoke passed ({$assertions} assertions).\n";
}
