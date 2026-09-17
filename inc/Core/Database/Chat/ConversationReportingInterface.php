<?php
/**
 * Conversation reporting contract.
 *
 * Covers non-mutating summary/metrics reads used by daily memory and
 * retention status reporting. Backends that cannot report storage metrics
 * may return null from get_storage_metrics().
 *
 * @package DataMachine\Core\Database\Chat
 * @since   next
 */

namespace DataMachine\Core\Database\Chat;

defined( 'ABSPATH' ) || exit;

interface ConversationReportingInterface {

	/**
	 * List lightweight session summaries created on the given date.
	 *
	 * Returns rows with `{session_id, title, context/mode, created_at}`.
	 * Used by the Daily Memory Task to summarize a day's chat activity
	 * without loading the full messages array.
	 *
	 * Implementations determine their own date comparison semantics. The
	 * default MySQL store uses `DATE(created_at) = $date` on the stored
	 * timestamp.
	 *
	 * @param string $date Date string in `Y-m-d` format.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_sessions_for_day( string $date ): array;

	/**
	 * List lightweight session summaries created on the given date, narrowed
	 * by an explicit authorization scope.
	 *
	 * Principal-bound callers (ordinary daily-memory compaction) pass both
	 * scope keys so only records visible to that agent/user pair reach the
	 * model (#3487). A single key is a deliberately authorized aggregate
	 * along that dimension (e.g. an agent-wide maintenance read spans all
	 * users of that agent); an empty scope array is the site-wide day
	 * aggregate. The breadth is therefore always an explicit authority
	 * choice by the caller, never an accident of omitted predicates.
	 *
	 * Implementations MUST apply the scope as storage-query predicates, not
	 * by filtering an already broad result in PHP. Scope keys:
	 *
	 * - `user_id` (int): only sessions owned by that user.
	 * - `agent_id` (int): only sessions bound to that agent.
	 *
	 * @param string $date  Date string in `Y-m-d` format.
	 * @param array  $scope Authorization scope. Keys are independent; absent
	 *                      keys narrow nothing.
	 * @return array<int, array<string, mixed>> Rows shaped like
	 *                                            list_sessions_for_day().
	 * @since next
	 */
	public function list_sessions_for_day_scoped( string $date, array $scope ): array;

	/**
	 * Report storage metrics for the retention CLI.
	 *
	 * Returns `['rows' => int, 'size_mb' => string]` for the default
	 * MySQL store. Stores that cannot report byte size return
	 * `size_mb => '0.0'`. Stores that cannot report rows either return
	 * `null` to opt out of the metrics table.
	 *
	 * @return array{rows: int, size_mb: string}|null
	 */
	public function get_storage_metrics(): ?array;
}
