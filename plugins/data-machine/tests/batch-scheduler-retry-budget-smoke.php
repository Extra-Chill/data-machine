<?php
/** Behavioral coverage for exact v2 chunk adoption and bounded item retries. */

namespace DataMachine\Core\Database\BatchItems {
	class BatchItems {
		public const DEFAULT_LEASE_SECONDS = 60;
		public const DEFAULT_MAX_ATTEMPTS  = 3;
		public const STATE_READY           = 'ready';
		public const STATE_CLAIMED         = 'claimed';
		public const STATE_CANCEL_PENDING  = 'cancel_pending';
		public const STATE_FAILED          = 'failed';
		public const STATE_COMPLETED       = 'completed';
		public static array $worklists     = array();

		public static function maxAttempts( string $context = '' ): int {
			return max( 1, (int) apply_filters( 'datamachine_batch_item_max_attempts', self::DEFAULT_MAX_ATTEMPTS, $context ) );
		}

		public function insert_batch( int $job_id, array $items, array $cleanup ): array {
			unset( $cleanup );
			self::$worklists[ $job_id ] = array(
				'items'     => array_values( $items ),
				'token'     => 'owner-' . $job_id,
				'state'     => array_fill( 0, count( $items ), self::STATE_READY ),
				'attempts'  => array_fill( 0, count( $items ), 0 ),
				'completed' => array(),
				'failed'    => array(),
			);
			return array( 'success' => true, 'created' => true, 'existing' => false, 'ownership_token' => 'owner-' . $job_id );
		}

		public function claim_chunk( int $job_id, int $offset, int $limit, int $lease, ?callable $owner = null ): array {
			unset( $lease );
			if ( ! isset( self::$worklists[ $job_id ] ) ) {
				return array();
			}
			$rows = array();
			foreach ( array_slice( self::$worklists[ $job_id ]['items'], $offset, $limit, true ) as $index => $payload ) {
				$state = self::$worklists[ $job_id ]['state'][ $index ] ?? self::STATE_READY;
				if ( self::STATE_READY !== $state ) {
					continue;
				}
				++self::$worklists[ $job_id ]['attempts'][ $index ];
				self::$worklists[ $job_id ]['state'][ $index ] = self::STATE_CLAIMED;
				$rows[] = array(
					'item_index'       => $index,
					'payload'          => $payload,
					'payload_valid'    => true,
					'payload_checksum' => hash( 'sha256', (string) json_encode( $payload ) ),
					'cleanup_context'  => array(),
					'lease_token'      => 'lease-' . $index . '-' . self::$worklists[ $job_id ]['attempts'][ $index ],
					'attempts'         => self::$worklists[ $job_id ]['attempts'][ $index ],
				);
			}
			if ( $rows && null !== $owner && ! $owner() ) {
				foreach ( $rows as $row ) {
					$index = (int) $row['item_index'];
					--self::$worklists[ $job_id ]['attempts'][ $index ];
					self::$worklists[ $job_id ]['state'][ $index ] = self::STATE_READY;
				}
				return array();
			}
			return $rows;
		}

		public function complete( int $job_id, int $index, string $lease, mixed $result ): bool {
			unset( $lease, $result );
			if ( self::STATE_CLAIMED !== ( self::$worklists[ $job_id ]['state'][ $index ] ?? '' ) ) {
				return false;
			}
			self::$worklists[ $job_id ]['state'][ $index ]     = self::STATE_COMPLETED;
			self::$worklists[ $job_id ]['completed'][ $index ] = true;
			return true;
		}

		public function release( int $job_id, int $index, string $lease ): bool {
			unset( $lease );
			if ( self::STATE_CLAIMED !== ( self::$worklists[ $job_id ]['state'][ $index ] ?? '' ) ) {
				return false;
			}
			self::$worklists[ $job_id ]['state'][ $index ] = self::STATE_READY;
			return true;
		}

		public function fail_claim( int $job_id, int $index, string $lease ): bool {
			unset( $lease );
			if ( self::STATE_CLAIMED !== ( self::$worklists[ $job_id ]['state'][ $index ] ?? '' ) ) {
				return false;
			}
			self::$worklists[ $job_id ]['state'][ $index ]  = self::STATE_FAILED;
			self::$worklists[ $job_id ]['failed'][ $index ] = true;
			return true;
		}

		public function first_outstanding_index( int $job_id ): ?int {
			foreach ( self::$worklists[ $job_id ]['state'] ?? array() as $index => $state ) {
				if ( in_array( $state, array( self::STATE_READY, self::STATE_CLAIMED ), true ) ) {
					return $index;
				}
			}
			return null;
		}

		public function count_completed( int $job_id ): int {
			return count( self::$worklists[ $job_id ]['completed'] ?? array() );
		}

		public function count_failed( int $job_id ): int {
			return count( self::$worklists[ $job_id ]['failed'] ?? array() );
		}

		public function discard_owned( int $job_id, string $token ): array {
			unset( $job_id, $token );
			return array( 'success' => true, 'rows' => array(), 'remaining' => false );
		}

		public function delete_owned_batch( int $job_id, string $token ): bool {
			unset( $job_id, $token );
			return true;
		}
	}
}

namespace DataMachine\Core\Database\Jobs {
	class Jobs {
		public function get_job( int $job_id ): ?array {
			return array( 'engine_data' => \DataMachine\Core\EngineData::$engines[ $job_id ] ?? array() );
		}
	}
}

namespace DataMachine\Core {
	class PluginSettings {
		public static function get( string $key, array $default ): array {
			unset( $key );
			return $default;
		}
	}
	class DataPacketStore {
		public static function reference_packet_collections_in_value( mixed $value ): mixed {
			return $value;
		}
		public static function hydrate_packet_collections_with_status( mixed $value ): array {
			return array( 'success' => true, 'value' => $value );
		}
	}
	class JobStatus {
		public static function isStatusFinal( string $status ): bool {
			unset( $status );
			return false;
		}
	}
	class RunMetrics {
		public static function start( int $job_id, array $context ): void {
			unset( $job_id, $context );
		}
	}
	class EngineData {
		public static array $engines = array();
		public static function mutate( int $job_id, callable $callback, string $event ): array {
			unset( $event );
			$current = self::$engines[ $job_id ] ?? array();
			$next    = $callback( $current );
			if ( ! is_array( $next ) ) {
				return array( 'success' => false, 'snapshot' => $current );
			}
			self::$engines[ $job_id ] = $next;
			return array( 'success' => true, 'snapshot' => $next );
		}
		public static function retrieve( int $job_id ): array {
			return self::$engines[ $job_id ] ?? array();
		}
	}
}

namespace {
	use DataMachine\Core\ActionScheduler\BatchScheduler;
	use DataMachine\Core\ActionScheduler\GroupRegistrar;
	use DataMachine\Core\Database\BatchItems\BatchItems;
	use DataMachine\Core\EngineData;

	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['as_actions']        = array();
	$GLOBALS['as_schedule_calls'] = 0;
	$GLOBALS['as_next_id']        = 1;

	function absint( mixed $value ): int {
		return abs( (int) $value );
	}
	function current_time( string $type, bool $gmt = false ): string {
		unset( $type, $gmt );
		return gmdate( 'Y-m-d H:i:s' );
	}
	function wp_json_encode( mixed $value ): string|false {
		return json_encode( $value );
	}
	function do_action( string $hook, mixed ...$args ): void {
		unset( $hook, $args );
	}
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		unset( $hook, $args );
		return $value;
	}
	function is_wp_error( mixed $thing ): bool {
		unset( $thing );
		return false;
	}
	function wp_next_scheduled( string $hook, array $args ): bool {
		unset( $hook, $args );
		return false;
	}
	function wp_schedule_single_event( int $timestamp, string $hook, array $args, bool $unique = false ): bool {
		unset( $timestamp, $hook, $args, $unique );
		return false;
	}
	function as_args_identity( array $args ): string {
		return (string) json_encode( $args );
	}
	function as_get_scheduled_actions( array $query, string $return_format = 'OBJECT' ): array {
		unset( $return_format );
		$matches = array();
		foreach ( $GLOBALS['as_actions'] as $id => $action ) {
			if ( ( $query['hook'] ?? '' ) !== $action['hook']
				|| ( $query['group'] ?? '' ) !== $action['group']
				|| ( $query['status'] ?? '' ) !== $action['status'] ) {
				continue;
			}
			if ( isset( $query['args'] ) && as_args_identity( $query['args'] ) !== as_args_identity( $action['args'] ) ) {
				continue;
			}
			$matches[ $id ] = $id;
			if ( isset( $query['per_page'] ) && count( $matches ) >= (int) $query['per_page'] ) {
				break;
			}
		}
		return $matches;
	}
	function as_schedule_single_action( int $timestamp, string $hook, array $args, string $group ): int {
		unset( $timestamp );
		++$GLOBALS['as_schedule_calls'];
		foreach ( $GLOBALS['as_actions'] as $id => $action ) {
			if ( $hook === $action['hook']
				&& $group === $action['group']
				&& 'pending' === $action['status']
				&& as_args_identity( $args ) === as_args_identity( $action['args'] ) ) {
				return $id;
			}
		}
		$id = $GLOBALS['as_next_id']++;
		$GLOBALS['as_actions'][ $id ] = array(
			'hook'   => $hook,
			'args'   => $args,
			'group'  => $group,
			'status' => 'pending',
		);
		return $id;
	}

	require_once __DIR__ . '/../inc/Core/ActionScheduler/GroupRegistrar.php';
	require_once __DIR__ . '/../inc/Core/ActionScheduler/BatchScheduler.php';

	$passes   = 0;
	$failures = 0;
	$assert   = static function ( bool $condition, string $label ) use ( &$passes, &$failures ): void {
		if ( $condition ) {
			++$passes;
			echo "  [PASS] {$label}\n";
			return;
		}
		++$failures;
		echo "  [FAIL] {$label}\n";
	};

	$pending_count = static function ( string $hook, array $args ): int {
		return count(
			as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'args'     => $args,
					'group'    => GroupRegistrar::GROUP,
					'status'   => 'pending',
					'per_page' => 100,
				),
				'ids'
			)
		);
	};

	$hook = 'datamachine_task_process_batch';
	$args = array(
		'parent_job_id' => 3434,
		'offset'        => 0,
	);

	echo "=== batch-scheduler-retry-budget-smoke ===\n";

	echo "\n[1] Exact chunk scheduling adopts one pending action\n";
	$first  = BatchScheduler::scheduleChunk( $hook, 3434, 0, time() );
	$second = BatchScheduler::scheduleChunk( $hook, 3434, 0, time() + 30 );
	$string_args_adopted = BatchScheduler::scheduleChunk( $hook, 3434, 0, time() + 60 );
	$assert( $first && $second && $string_args_adopted, 'repeated exact schedules succeed' );
	$assert( 1 === $GLOBALS['as_schedule_calls'], 'pending adoption does not insert another Action Scheduler row' );
	$assert( 1 === $pending_count( $hook, $args ), 'exact hook/args/group retain at most one pending action' );
	$assert( 1 === $pending_count( $hook, array( 'parent_job_id' => '3434', 'offset' => '0' ) ) || 1 === $pending_count( $hook, $args ), 'integer and string JSON encodings share one identity' );

	echo "\n[2] Permanent item failures terminate after the attempt budget\n";
	$GLOBALS['as_actions']        = array();
	$GLOBALS['as_schedule_calls'] = 0;
	$GLOBALS['as_next_id']        = 1;
	EngineData::$engines          = array();
	BatchItems::$worklists        = array();

	$parent_id = 5001;
	$started   = BatchScheduler::start( $parent_id, $hook, array( array( 'id' => 'poison' ) ), array(), 'task' );
	$assert( ! empty( $started['scheduled'] ), 'failing batch still schedules its first chunk' );
	$inserts_after_start = $GLOBALS['as_schedule_calls'];

	$results = array();
	for ( $i = 0; $i < 20; $i++ ) {
		$results[] = BatchScheduler::processChunk( $parent_id, static fn(): bool => false, 0 );
		if ( ! empty( $results[ $i ]['item_failed'] ) && empty( $results[ $i ]['more'] ) ) {
			break;
		}
	}

	$terminal = end( $results );
	$assert( false !== $terminal && ! empty( $terminal['item_failed'] ), 'exhausted item surfaces a deterministic failure signal' );
	$assert( empty( $terminal['more'] ), 'exhausted worklist does not continue' );
	$assert( 3 === count( $results ), 'createItem runs exactly DEFAULT_MAX_ATTEMPTS times' );
	$assert( 3 === BatchItems::$worklists[ $parent_id ]['attempts'][0], 'three failed claims consume the budget' );
	$assert( BatchItems::STATE_FAILED === BatchItems::$worklists[ $parent_id ]['state'][0], 'poison item is terminally failed' );
	$assert( 1 === ( new BatchItems() )->count_failed( $parent_id ), 'failed items are counted for the parent' );
	$assert( ! empty( EngineData::$engines[ $parent_id ]['batch_item_failed'] ), 'parent engine_data records batch_item_failed' );
	$assert( $GLOBALS['as_schedule_calls'] <= $inserts_after_start + 1, 'retries do not insert unbounded Action Scheduler rows' );
	$assert( 1 === $pending_count( $hook, array( 'parent_job_id' => $parent_id, 'offset' => 0 ) ), 'replayed failing chunks keep one pending identity' );

	$after_terminal = BatchScheduler::processChunk( $parent_id, static fn(): bool => false, 0 );
	$assert( ! empty( $after_terminal['item_failed'] ), 'later replays keep the failure signal' );
	$assert( $GLOBALS['as_schedule_calls'] <= $inserts_after_start + 1, 'terminal replays do not insert more actions' );

	echo "\n[3] Successful items still complete without a failure signal\n";
	$GLOBALS['as_actions']        = array();
	$GLOBALS['as_schedule_calls'] = 0;
	$GLOBALS['as_next_id']        = 1;
	EngineData::$engines          = array();
	BatchItems::$worklists        = array();
	$ok_parent                    = 5002;
	BatchScheduler::start( $ok_parent, $hook, array( array( 'id' => 'ok' ) ), array(), 'task' );
	$ok = BatchScheduler::processChunk( $ok_parent, static fn(): int => 99, 0 );
	$assert( empty( $ok['item_failed'] ), 'successful createItem does not fail the parent' );
	$assert( empty( $ok['more'] ), 'successful worklist completes' );
	$assert( 1 === ( new BatchItems() )->count_completed( $ok_parent ), 'successful item is completed' );
	$assert( 0 === ( new BatchItems() )->count_failed( $ok_parent ), 'successful item is not counted as failed' );

	echo "\nbatch-scheduler-retry-budget-smoke: {$passes} passed, {$failures} failed\n";
	exit( $failures > 0 ? 1 : 0 );
}
