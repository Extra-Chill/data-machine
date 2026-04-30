<?php
/**
 * Pure-PHP smoke test for in-flight source-item claims (#1682).
 *
 * Run with: php tests/processed-item-claims-smoke.php
 *
 * The production path is database-backed, but the race contract is simple:
 * the existing (flow_step_id, source_type, item_identifier) unique key must
 * represent exactly one active state at a time: claimed or processed.
 *
 * @package DataMachine\Tests
 */

$failed = 0;
$total  = 0;

function assert_claims_smoke( string $name, bool $cond, string $detail = '' ): void {
	global $failed, $total;
	++$total;
	if ( $cond ) {
		echo "  [PASS] $name\n";
		return;
	}

	echo "  [FAIL] $name" . ( $detail ? " — $detail" : '' ) . "\n";
	++$failed;
}

final class ClaimsLedgerForSmoke {
	public const CLAIMED = 'claimed';
	public const PROCESSED = 'processed';

	/** @var array<string,array{status:string,job_id:int,expires:int|null}> */
	private array $rows = array();
	private int $now = 1000;

	public function tick( int $seconds ): void {
		$this->now += $seconds;
	}

	public function claim( string $flow_step_id, string $source_type, string $item_identifier, int $job_id, int $ttl = 3600 ): bool {
		$this->deleteExpiredClaims();
		$key = $this->key( $flow_step_id, $source_type, $item_identifier );

		if ( isset( $this->rows[ $key ] ) ) {
			return false;
		}

		$this->rows[ $key ] = array(
			'status'  => self::CLAIMED,
			'job_id'  => $job_id,
			'expires' => $this->now + $ttl,
		);

		return true;
	}

	public function release( string $flow_step_id, string $source_type, string $item_identifier ): void {
		$key = $this->key( $flow_step_id, $source_type, $item_identifier );
		if ( isset( $this->rows[ $key ] ) && self::CLAIMED === $this->rows[ $key ]['status'] ) {
			unset( $this->rows[ $key ] );
		}
	}

	public function markProcessed( string $flow_step_id, string $source_type, string $item_identifier, int $job_id ): void {
		$key = $this->key( $flow_step_id, $source_type, $item_identifier );
		$this->rows[ $key ] = array(
			'status'  => self::PROCESSED,
			'job_id'  => $job_id,
			'expires' => null,
		);
	}

	public function shouldSkip( string $flow_step_id, string $source_type, string $item_identifier ): bool {
		$this->deleteExpiredClaims();
		return isset( $this->rows[ $this->key( $flow_step_id, $source_type, $item_identifier ) ] );
	}

	public function status( string $flow_step_id, string $source_type, string $item_identifier ): ?string {
		$key = $this->key( $flow_step_id, $source_type, $item_identifier );
		return $this->rows[ $key ]['status'] ?? null;
	}

	private function deleteExpiredClaims(): void {
		foreach ( $this->rows as $key => $row ) {
			if ( self::CLAIMED === $row['status'] && null !== $row['expires'] && $row['expires'] <= $this->now ) {
				unset( $this->rows[ $key ] );
			}
		}
	}

	private function key( string $flow_step_id, string $source_type, string $item_identifier ): string {
		return implode( '|', array( $flow_step_id, $source_type, $item_identifier ) );
	}
}

$ledger = new ClaimsLedgerForSmoke();
$flow   = 'fetch-step_5';
$source = 'mgs';
$item   = 'ticket-123';

echo "Case 1: parallel parents cannot claim the same item\n";
assert_claims_smoke( 'first parent claim succeeds', $ledger->claim( $flow, $source, $item, 100 ) );
assert_claims_smoke( 'second parent claim fails while first is active', ! $ledger->claim( $flow, $source, $item, 101 ) );
assert_claims_smoke( 'active claim makes fetch skip item', $ledger->shouldSkip( $flow, $source, $item ) );
assert_claims_smoke( 'row is in claimed state before completion', ClaimsLedgerForSmoke::CLAIMED === $ledger->status( $flow, $source, $item ) );

echo "Case 2: failed downstream work releases claim for retry\n";
$ledger->release( $flow, $source, $item );
assert_claims_smoke( 'released claim no longer skips', ! $ledger->shouldSkip( $flow, $source, $item ) );
assert_claims_smoke( 'later parent can reclaim after release', $ledger->claim( $flow, $source, $item, 102 ) );

echo "Case 3: successful completion converts claim to processed\n";
$ledger->markProcessed( $flow, $source, $item, 200 );
assert_claims_smoke( 'row is processed after final completion', ClaimsLedgerForSmoke::PROCESSED === $ledger->status( $flow, $source, $item ) );
assert_claims_smoke( 'processed row makes future fetch skip', $ledger->shouldSkip( $flow, $source, $item ) );
assert_claims_smoke( 'processed row cannot be reclaimed', ! $ledger->claim( $flow, $source, $item, 103 ) );

echo "Case 4: stale claims expire\n";
$stale = 'ticket-456';
assert_claims_smoke( 'short claim succeeds', $ledger->claim( $flow, $source, $stale, 300, 5 ) );
$ledger->tick( 5 );
assert_claims_smoke( 'expired claim no longer skips', ! $ledger->shouldSkip( $flow, $source, $stale ) );
assert_claims_smoke( 'expired claim can be reclaimed', $ledger->claim( $flow, $source, $stale, 301 ) );

echo "Case 5: production files expose the claim contract\n";
$processed_items = file_get_contents( __DIR__ . '/../inc/Core/Database/ProcessedItems/ProcessedItems.php' );
$fetch_handler   = file_get_contents( __DIR__ . '/../inc/Core/Steps/Fetch/Handlers/FetchHandler.php' );
$fail_handler    = file_get_contents( __DIR__ . '/../inc/Engine/Actions/Handlers/FailJobHandler.php' );
$execute_step    = file_get_contents( __DIR__ . '/../inc/Abilities/Engine/ExecuteStepAbility.php' );

assert_claims_smoke( 'repository defines claimed status', (bool) preg_match( "/STATUS_CLAIMED\s*=\s*'claimed'/", $processed_items ) );
assert_claims_smoke( 'repository defines processed status', (bool) preg_match( "/STATUS_PROCESSED\s*=\s*'processed'/", $processed_items ) );
assert_claims_smoke( 'repository has atomic claim method', str_contains( $processed_items, 'function claim_item' ) );
assert_claims_smoke( 'repository has release claim method', str_contains( $processed_items, 'function release_claim' ) );
assert_claims_smoke( 'repository ensures claim columns', str_contains( $processed_items, 'function ensure_claim_columns' ) );
assert_claims_smoke( 'fetch filters active claims', str_contains( $fetch_handler, 'isItemClaimed' ) );
assert_claims_smoke( 'fetch claims after max_items', strpos( $fetch_handler, 'array_slice' ) < strpos( $fetch_handler, 'claimItems' ) );
assert_claims_smoke( 'fail handler releases claims', str_contains( $fail_handler, 'releaseInFlightSourceClaims' ) );
assert_claims_smoke( 'failed status override releases claim', str_contains( $execute_step, 'releaseInFlightItemClaim' ) );

echo "\nProcessed item claims smoke complete: {$total} assertions, {$failed} failures.\n";
if ( $failed > 0 ) {
	exit( 1 );
}
