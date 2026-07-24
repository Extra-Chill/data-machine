<?php
/**
 * The Action Scheduler branch executor — the one executor core ships, and the
 * ONLY table-free path to asynchronous, concurrent parallel-branch execution.
 *
 * Under the substrate's hard constraint (agents-api may add NO new database
 * tables, because it is headed for wpcom / WordPress core), Action Scheduler is
 * the only substrate that supplies — without a new table — the two things async
 * needs:
 *
 *   1. Durable branch persistence. Each branch's self-contained descriptor
 *      rides in the AS action payload, stored in AS's OWN tables. No agents-api
 *      table, and the descriptor survives restart because AS makes it so.
 *   2. A cross-process atomic claim. AS's queue runner claims each action
 *      exactly once — precisely the compare-and-set the "last branch resumes
 *      exactly once" transition requires. The resume is itself enqueued as one
 *      more claimed action, so even under a simultaneous multi-branch finish
 *      exactly one resume runs (the rest are claimed no-ops that re-check the
 *      run is still SUSPENDED and bail). No hand-rolled lock, no lock table.
 *
 * This is a DIFFERENT Action Scheduler use-case from the cron bridge
 * ({@see WP_Agent_Workflow_Action_Scheduler_Bridge}), which schedules ONE
 * recurring action per workflow trigger under `wp_agent_workflow_run_scheduled`.
 * This executor enqueues ONE async action PER BRANCH under a distinct hook,
 * plus one RESUME action per suspended run. Both reuse GROUP `agents-api` so an
 * operator has a single group to reason about; the hooks keep them separate.
 *
 * The runner owns the suspend/resume state machine and the reconcile entry
 * point ({@see agents_reconcile_workflow_branch()}); this executor owns only the
 * mechanism that runs branches out-of-band and drives that reconcile back in.
 *
 * @package AgentsAPI
 * @since   0.5.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Workflow_Action_Scheduler_Branch_Executor implements WP_Agent_Workflow_Branch_Executor {

	/**
	 * Stable executor id stamped on every frame + handle so reconcile and the
	 * resume-dispatch seam can attribute a suspended run to this executor.
	 *
	 * @since 0.5.0
	 */
	public const ID = 'action_scheduler';

	/**
	 * The per-branch async action hook. One action per parallel branch is
	 * enqueued under this hook; its callback rehydrates the branch from its
	 * payload, runs it, and reconciles. Distinct from the cron bridge's
	 * `wp_agent_workflow_run_scheduled` so the two AS integrations never collide.
	 *
	 * @since 0.5.0
	 */
	public const BRANCH_HOOK = 'wp_agent_workflow_branch_run';

	/**
	 * The resume action hook. When a reconcile observes all branches terminal it
	 * enqueues ONE action under this hook rather than resuming inline; AS claims
	 * it exactly once, and the callback re-checks the run is still SUSPENDED
	 * before resuming — the exactly-once guarantee.
	 *
	 * @since 0.5.0
	 */
	public const RESUME_HOOK = 'wp_agent_workflow_run_resume';

	/**
	 * Shared AS group with the cron bridge so operators reason about one group.
	 * The hook, not the group, distinguishes the two integrations.
	 *
	 * @since 0.5.0
	 */
	public const GROUP = WP_Agent_Workflow_Action_Scheduler_Bridge::GROUP;

	/**
	 * Upper bound on how many branch actions may run concurrently.
	 *
	 * This caps the global `action_scheduler_queue_runner_concurrent_batches` raise
	 * ({@see register-workflow-branch-executor.php}) so a pathological branch count
	 * cannot ask the substrate to run an unbounded number of simultaneous batches —
	 * each concurrent batch is a live PHP worker process holding a claim, and the
	 * worker pool is finite. It also bounds the number of concurrent loopback
	 * runners {@see self::trigger_async_runner()} fires. A fan-out rarely has more
	 * than a handful of branches, so this bound is generous for real workloads while
	 * keeping the blast radius of a global concurrency raise predictable.
	 *
	 * @since 0.5.0
	 */
	public const MAX_BRANCH_CONCURRENCY = 8;

	/**
	 * Whether Action Scheduler's async enqueue is available. This — not mere
	 * presence of the plugin — is the async gate: `as_enqueue_async_action` is
	 * what supplies both the durable payload store and the atomic claim.
	 *
	 * @since 0.5.0
	 */
	public static function is_available(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * @inheritDoc
	 * @since 0.5.0
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Dispatch one Action Scheduler async action per branch.
	 *
	 * PAYLOAD OFFLOAD (§Bug 1). The branch's self-contained descriptor —
	 * including the shared immutable `context` snapshot — is NOT packed inline
	 * into the AS action `args`. Action Scheduler enforces a hard 8,000-character
	 * limit on its `args` column, and a rich context (a multi-page brief) blows
	 * past it. Instead the descriptor is persisted to the table-free branch store
	 * ({@see WP_Agent_Workflow_Branch_Store}) and the AS args carry only a small,
	 * stable reference — `{ run_id, handle_id, store_ref, context_ref }` — whose
	 * size does not scale with context richness. The shared context is stored
	 * ONCE per run (run-scoped) rather than duplicated into every branch payload.
	 *
	 * FAIL-LOUD (§Bug 2). Every `as_enqueue_async_action()` call is checked: an
	 * enqueue that returns a non-positive id (or throws) is a hard failure, not a
	 * phantom `ref => 0` handle. On ANY branch's enqueue failure this returns a
	 * WP_Error and the run fails fast with a descriptive message rather than
	 * suspending against a branch that was never enqueued and hanging until its
	 * budget expires. A partial dispatch (some branches enqueued, one failed) is
	 * also a hard failure — the run must not suspend against a partial branch set.
	 *
	 * The `run_id` / `step_id` a branch reconciles against come from the
	 * descriptor. The runner stamps them onto each descriptor before dispatch
	 * (see {@see WP_Agent_Workflow_Runner::role_branch_descriptor()} /
	 * {@see WP_Agent_Workflow_Runner::build_map_dispatch_plan()}), but as a
	 * belt-and-suspenders we also read them from the shared context.
	 *
	 * @param array<int,array<string,mixed>> $branches Branch descriptors.
	 * @param array<string,mixed>            $context  Shared context snapshot.
	 * @return array<int,array<string,mixed>>|\WP_Error BranchHandle[] or a hard failure.
	 * @since 0.5.0
	 */
	public function dispatch( array $branches, array $context ) {
		$context_run_id  = self::string_value( $context['_workflow_run_id'] ?? '' );
		$context_step_id = self::string_value( $context['_workflow_step_id'] ?? '' );

		// The shared immutable context is identical for every branch, so store it
		// ONCE per run and reference it from each branch — never duplicate a
		// multi-KB context into N branch payloads. Its ref rides in every branch's
		// AS args (a short option name), and the branch action re-seats it into the
		// descriptor's branch_vars.context on rehydrate.
		$run_id_for_ctx = '' !== $context_run_id ? $context_run_id : self::first_branch_run_id( $branches );
		$shared_context = is_array( $context['shared_context'] ?? null ) ? self::string_keyed_array( $context['shared_context'] ) : array();
		$context_ref    = '' !== $run_id_for_ctx
			? WP_Agent_Workflow_Branch_Store::put_shared_context( $run_id_for_ctx, $shared_context )
			: '';

		$handles = array();
		foreach ( $branches as $index => $branch ) {
			$key = self::string_value( $branch['key'] ?? (string) $index );

			// Prefer the self-contained descriptor's identity; fall back to the
			// dispatch context. The descriptor IS the durable payload, so its
			// run_id / step_id must be authoritative once it rides in the store.
			$run_id  = self::string_value( $branch['run_id'] ?? '' );
			$step_id = self::string_value( $branch['step_id'] ?? '' );
			$run_id  = '' !== $run_id ? $run_id : $context_run_id;
			$step_id = '' !== $step_id ? $step_id : $context_step_id;

			// Handle id is unique within the run so reconcile can address it and
			// a duplicate reconcile is a no-op.
			$handle_id = self::build_handle_id( $run_id, $step_id, $key, $index );

			// The descriptor is self-contained: it carries everything the branch
			// action needs to run and reconcile without re-reading the spec. Strip
			// the shared context out of the stored descriptor — it lives ONCE in
			// the run-scoped context row and is re-seated on rehydrate.
			$descriptor = array_merge(
				$branch,
				array(
					'run_id'    => $run_id,
					'step_id'   => $step_id,
					'handle_id' => $handle_id,
					'key'       => $key,
				)
			);
			$descriptor = self::strip_shared_context( $descriptor );

			// Offload the descriptor to the store; the AS args carry only the ref.
			$store_ref = WP_Agent_Workflow_Branch_Store::put_branch( $run_id, $handle_id, $descriptor );

			$payload = array(
				'run_id'      => $run_id,
				'handle_id'   => $handle_id,
				'store_ref'   => $store_ref,
				'context_ref' => $context_ref,
			);

			$action_id = self::enqueue_async_action( self::BRANCH_HOOK, array( $payload ), self::GROUP );

			// FAIL LOUD: a non-positive action id means the enqueue did not durably
			// persist the branch (AS's args-size guard threw, AS is down, etc.).
			// Returning a phantom ref=0 handle here is what previously made the run
			// suspend against a branch that never existed and hang. Fail the whole
			// dispatch instead — clean up what we already stored so no orphan rows
			// linger, and surface a descriptive WP_Error.
			if ( $action_id <= 0 ) {
				if ( '' !== $run_id ) {
					WP_Agent_Workflow_Branch_Store::forget_run( $run_id );
				}
				return new \WP_Error(
					'workflow_branch_dispatch_enqueue_failed',
					sprintf(
						'Failed to enqueue async branch action for branch `%s` (run `%s`): Action Scheduler returned no action id. The run is failing fast rather than suspending against a branch that was never enqueued.',
						$key,
						$run_id
					)
				);
			}

			$handles[] = array(
				'id'       => $handle_id,
				'key'      => $key,
				'executor' => self::ID,
				'status'   => 'dispatched',
				'required' => ! empty( $branch['required'] ),
				'ref'      => $action_id,
			);
		}

		// Every branch is now durably enqueued as a claimed AS action. Fire one
		// concurrent async-runner request per branch just enqueued so N distinct
		// worker processes each claim ONE branch in the same window — turning the
		// enqueued set into N branches running in parallel PIDs rather than one
		// worker draining them serially. This is a best-effort kick that lives on
		// TOP of Action Scheduler's own model: the branches are already durable AS
		// actions, so a runtime where the loopback dispatch is unavailable still
		// drains them through AS's own WP-Cron runner — the trigger no-ops cleanly
		// there (see self::trigger_async_runner()). The concurrency filters that let
		// those N workers each claim a distinct branch are registered persistently at
		// load ({@see register-workflow-branch-executor.php}) so they are in force in
		// each loopback WORKER process, not just here in the dispatching request.
		self::trigger_async_runner( count( $handles ) );

		return $handles;
	}

	/**
	 * Count the branch actions still IN FLIGHT for an active fan-out on this
	 * executor's OWN branch hook — PENDING *plus* IN-PROGRESS — live, no memoization.
	 *
	 * This is the gate that scopes the AS concurrency raise to an in-flight fan-out.
	 * It counts actions on {@see self::BRANCH_HOOK} specifically (not the whole
	 * {@see self::GROUP} group), so the elevated concurrency policy keys off THIS
	 * executor's own parallel branches and never touches unrelated AS workload.
	 *
	 * WHY IN-PROGRESS COUNTS, NOT JUST PENDING. The whole point of the fan-out is N
	 * branches running AT ONCE. But the instant a worker claims a branch, that
	 * action transitions PENDING -> in-progress, so a pending-only count collapses
	 * toward 0 the moment claiming starts — even though every branch is still in
	 * flight. If the gate keyed off pending alone, the first worker to claim a branch
	 * would drop the count enough to revert both concurrency filters to the AS
	 * defaults (`concurrent_batches` 1, `batch_size` 25), and
	 * `has_maximum_concurrent_batches()` (get_claim_count 1 >= concurrent_batches 1)
	 * would then BLOCK any further worker from claiming the remaining pending
	 * branches while that first branch runs its multi-minute AI call. The fan-out
	 * would drain SERIALLY — one branch at a time, ~the branch duration apart —
	 * defeating the entire mechanism. Compounded by the intentional 3600s long-branch
	 * reaper window ({@see register-workflow-branch-executor.php}), a single
	 * in-progress branch could hold the one claim slot for up to an hour.
	 *
	 * Counting PENDING + IN-PROGRESS keeps the ceiling raised to cover EVERY branch
	 * still in flight, so while some branches are running additional workers can still
	 * claim the ones that are pending — the ceiling stays open until the last branch
	 * leaves the in-flight set (both counts drain to 0), at which point the filters
	 * pass through unchanged and every other AS workload sees stock behavior.
	 *
	 * The count is read LIVE on every call rather than memoized per request. The two
	 * concurrency filters fire more than once within a single queue-runner request —
	 * `has_maximum_concurrent_batches()` at the top of `run()`, and again on shutdown
	 * when the worker considers self-chaining `maybe_dispatch()` — and between those
	 * calls the in-flight set changes as branches move PENDING -> in-progress ->
	 * complete. A request-static cache would pin the FIRST-observed value and go stale
	 * as the set drains, so a later `run()` in the same worker could see the wrong
	 * ceiling and either over- or under-claim. Reading live keeps the gate honest. The
	 * query is a small indexed lookup bounded to 2*MAX_BRANCH_CONCURRENCY ids, so it
	 * is cheap enough to run per filter application.
	 *
	 * Returns 0 when Action Scheduler's query API is unavailable (a runtime without
	 * AS), which makes both concurrency filters pass their value through untouched.
	 *
	 * @since 0.5.0
	 *
	 * @return int In-flight branch-action count (pending + in-progress).
	 */
	public static function branch_inflight_count(): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			return 0;
		}

		// Query PENDING and IN-PROGRESS in one call. Passing an array of statuses to
		// as_get_scheduled_actions() OR-matches them, so the returned ids are every
		// branch action that is either waiting to be claimed or currently running —
		// i.e. still in flight for this fan-out. per_page is bounded to twice
		// MAX_BRANCH_CONCURRENCY because at the boundary the in-flight set can hold up
		// to MAX_BRANCH_CONCURRENCY in-progress plus that many still pending; the
		// callers only need to know whether the count is >= their target, so a bound
		// that comfortably exceeds MAX_BRANCH_CONCURRENCY is sufficient.
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => self::BRANCH_HOOK,
				'status'   => array(
					\ActionScheduler_Store::STATUS_PENDING,
					\ActionScheduler_Store::STATUS_RUNNING,
				),
				'per_page' => self::MAX_BRANCH_CONCURRENCY * 2,
			),
			'ids'
		);

		return is_array( $ids ) ? count( $ids ) : 0;
	}

	/**
	 * Count the RESUME actions still IN FLIGHT — PENDING *plus* IN-PROGRESS — live,
	 * no memoization. The terminal-reconcile companion to {@see self::branch_inflight_count()}.
	 *
	 * WHY THIS EXISTS — RESUME STARVATION. A parallel fan-out ends with ONE resume
	 * action ({@see self::RESUME_HOOK}) that agents-api enqueues when the last branch
	 * reconciles ({@see self::maybe_defer_resume()}); running it drives the suspended
	 * run to terminal and fires the run-completed hook a caller finalizes on. The
	 * branch-concurrency raise ({@see register-workflow-branch-executor.php}) keys off
	 * {@see self::branch_inflight_count()} alone, which counts ONLY branch actions.
	 * The instant the last branch completes that count hits 0 and the ceiling reverts
	 * to the AS default (1) — but the resume action is now PENDING and DUE, and it is
	 * NOT a branch, so nothing keeps a claim slot open for it.
	 *
	 * That is fine when the queue is otherwise idle. It is NOT fine when ANY claim is
	 * still outstanding — for example a still-in-progress branch from an UNRELATED
	 * earlier fan-out held by AS's long-branch reaper window (up to the 3600s
	 * `action_scheduler_failure_period` this plugin sets). AS's WP-Cron runner gate,
	 * `has_maximum_concurrent_batches()`, compares the GLOBAL claim count against the
	 * ceiling (`get_claim_count() >= concurrent_batches`). With the ceiling back at 1
	 * and even a single stale claim outstanding the gate stays shut, so the queue
	 * runner claims NOTHING and the due resume sits unclaimed — the run never
	 * finalizes — until that stale claim is finally reaped (observed: ~51 minutes).
	 *
	 * Counting the in-flight resume lets the concurrency filter add a slot for it
	 * (see that filter), so the ceiling exceeds the outstanding-claim count by one and
	 * AS's gate opens far enough for the WP-Cron runner to claim the resume even while
	 * unrelated stale claims linger. Scoped to RESUME_HOOK so it only opens the extra
	 * slot when one of THIS executor's own fan-outs has a resume waiting; when none
	 * does the count is 0 and the ceiling is untouched.
	 *
	 * @since 0.5.0
	 *
	 * @return int In-flight resume-action count (pending + in-progress).
	 */
	public static function resume_inflight_count(): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			return 0;
		}

		// A run has exactly one resume action, but several runs can be in flight at
		// once, so bound the query to MAX_BRANCH_CONCURRENCY — the same order of
		// magnitude as the branch count. Callers only need "how many extra slots".
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => self::RESUME_HOOK,
				'status'   => array(
					\ActionScheduler_Store::STATUS_PENDING,
					\ActionScheduler_Store::STATUS_RUNNING,
				),
				'per_page' => self::MAX_BRANCH_CONCURRENCY,
			),
			'ids'
		);

		return is_array( $ids ) ? count( $ids ) : 0;
	}

	/**
	 * Trigger Action Scheduler's async-request queue runner: fire N CONCURRENT
	 * loopback HTTP requests to admin-ajax.php so AS spawns N separate native-PHP
	 * worker processes, each of which runs a queue batch. This is the mechanism that
	 * makes the branch actions run in PARALLEL PIDs (vs one in-process serial run).
	 *
	 * It replicates ActionScheduler_AsyncRequest_QueueRunner's own dispatch: a POST
	 * to `admin-ajax.php?action=as_async_request_queue_runner` with the matching
	 * nonce. Stores that support concurrent writes can claim pending branch actions
	 * from separate workers. SQLite serializes writes behind its single writer lock,
	 * so it cannot provide parallel branch execution.
	 *
	 * Public so a caller awaiting a suspended run in the foreground can re-nudge the
	 * pool during its poll (the loopback worker self-chain can lapse behind AS's
	 * allow() gate / a lock); the executor also calls it itself from `dispatch()`.
	 *
	 * ## Why the requests are fired in PARALLEL (curl_multi), not one at a time
	 *
	 * Action Scheduler's own dispatch fires a SINGLE non-blocking POST with a 0.01s
	 * timeout and relies on the worker self-chaining the rest. Two things make that
	 * insufficient for a real fan-out on a native-PHP runtime:
	 *
	 *   - The native-PHP runtime serves each request in a single-threaded `php -S`
	 *     worker. A `blocking => false` POST with a 0.01s timeout is torn down by the
	 *     HTTP client BEFORE the worker has accepted the connection over TLS — so the
	 *     request never actually reaches admin-ajax and no worker runs. (Measured: at
	 *     timeout 0.01–0.5s zero loopback POSTs land; the connection must live ~1s for
	 *     the worker to accept and dispatch it.) Firing them one-by-one with a longer
	 *     per-request timeout would then SERIALIZE the caller (N × ~1s), and
	 *     self-chaining alone only ever advances one batch at a time.
	 *   - To warm N workers AT ONCE, the N loopback connections must be opened
	 *     concurrently so N distinct `php -S` workers accept them in the same window.
	 *     WpOrg\Requests::request_multiple() opens them together via curl_multi, so
	 *     the runtime hands each to a different worker and N branches begin near-
	 *     simultaneously — the observed serial->parallel flip.
	 *
	 * The overall timeout is kept small: we only need the connections established (the
	 * worker dispatches its queue independently of our response), not the branch AI
	 * calls awaited. request_multiple returns once the short window elapses; the
	 * branches keep running in their own worker PIDs and the caller polls the
	 * recorder.
	 *
		 * A no-op (returns 0) on a runtime without loopback/admin-ajax, where the run
		 * falls back to AS's own WP-Cron runner or the caller's budget — acceptable
		 * degradation, never fabricated behavior. When request_multiple is unavailable it
		 * degrades to serial non-blocking POSTs (AS's own dispatch shape).
	 *
	 * @since 0.5.0
	 *
	 * @param int $workers How many async workers to dispatch (>= 1).
	 * @return int Number of loopback dispatches fired.
	 */
	public static function trigger_async_runner( int $workers ): int {
		if ( ! class_exists( '\ActionScheduler' ) || ! \ActionScheduler::is_initialized() ) {
			return 0;
		}
		if ( ! function_exists( 'admin_url' ) || ! function_exists( 'wp_create_nonce' ) ) {
			return 0;
		}

		$workers = max( 1, min( $workers, self::MAX_BRANCH_CONCURRENCY ) );

		// AS's async-request runner registers its handler on the ajax hook
		// `wp_ajax(_nopriv)_as_async_request_queue_runner` (prefix `as` + action
		// `async_request_queue_runner`), gated by a nonce of the same identifier. Fire
		// the same request AS fires internally so a separate worker process picks up
		// the queue.
		$identifier = 'as_async_request_queue_runner';
		$url        = add_query_arg(
			array(
				'action' => $identifier,
				'nonce'  => wp_create_nonce( $identifier ),
			),
			admin_url( 'admin-ajax.php' )
		);

		$sslverify = (bool) apply_filters( 'https_local_ssl_verify', false );

		// Loopback host rewrite. On a custom domain (e.g. a Studio `.local` host),
		// resolving the host can cost a multi-second multicast-DNS timeout. WordPress's
		// own HTTP API avoids this via a CURLOPT_RESOLVE pin on the `http_api_curl`
		// hook, but request_multiple drives curl_multi directly and never fires that
		// hook — so its concurrent handles each stall on host resolution and
		// effectively serialize. Point the loopback at 127.0.0.1 (the loop the local
		// server already listens on) and carry the real host in a Host header, so every
		// concurrent handle connects instantly and lands in its own worker. The
		// `wp_remote_post` fallback below still goes through the hook, so it keeps using
		// $url unchanged.
		$loopback         = self::loopback_dispatch_target( $url );
		$loopback_url     = $loopback['url'];
		$loopback_headers = $loopback['headers'];

		// Preferred path: open all N loopback connections CONCURRENTLY so N distinct
		// native-PHP workers accept them in the same window and each claims a branch.
		// request_multiple uses curl_multi under the hood. The `timeout` only needs to
		// outlast connection establishment (~1s on this TLS `php -S` runtime), not the
		// branch AI calls — the workers run their queue independently of our response.
		if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
			$requests = array();
			for ( $i = 0; $i < $workers; $i++ ) {
				$requests[] = array(
					'url'     => esc_url_raw( $loopback_url ),
					'type'    => 'POST',
					'data'    => array(),
					'headers' => $loopback_headers,
				);
			}

			// Timeout must comfortably outlast connection establishment — including host
			// resolution, which on an mDNS runtime can take a second or two per
			// concurrent handle. It does NOT gate the branch AI calls: the worker runs
			// its queue independently of our response, so a request that "times out"
			// waiting for the response has already handed its branch to a worker.
			// Filterable so a slower or faster runtime can tune it.
			$dispatch_timeout = apply_filters( 'agents_workflow_async_runner_dispatch_timeout', 10 );
			$options          = array(
				'timeout'         => is_numeric( $dispatch_timeout ) ? (float) $dispatch_timeout : 10.0,
				'connect_timeout' => 10,
				'verify'          => $sslverify,
			);

			try {
				// request_multiple fires every request in parallel and returns their
				// results/exceptions; a per-request failure (a worker that closed early)
				// is returned as a Requests\Exception in the results array, not thrown.
				$results = \WpOrg\Requests\Requests::request_multiple( $requests, $options );
			} catch ( \Throwable $error ) {
				// A total failure of the multi-request machinery — fall through to the
				// serial fallback below rather than aborting the dispatch entirely.
				unset( $error );
				$results = null;
			}

			if ( is_array( $results ) ) {
				$fired = 0;
				foreach ( $results as $result ) {
					// A successful loopback returns a Requests Response; a torn-down one
					// returns an exception. Count the ones the runtime accepted.
					if ( $result instanceof \WpOrg\Requests\Response ) {
						++$fired;
					}
				}

				// Count only loopbacks the runtime accepted. Connection-refused/transport
				// failures mean no queue runner was reached, so callers must see 0 and fall
				// back to another claim path instead of receiving fabricated success.
				return $fired;
			}
		}

		// Fallback: serial non-blocking POSTs (AS's own dispatch shape). Used when the
		// parallel Requests API is unavailable; relies on AS self-chaining to fan out.
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return 0;
		}

		$args = array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'body'      => array(),
			'cookies'   => $_COOKIE, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- forwarding the current request's cookies to the loopback worker, not reading input.
			'sslverify' => $sslverify,
		);

		$fired = 0;
		for ( $i = 0; $i < $workers; $i++ ) {
			$response = wp_remote_post( esc_url_raw( $url ), $args );
			if ( ! is_wp_error( $response ) ) {
				++$fired;
			}
		}

		return $fired;
	}

	/**
	 * Rewrite a loopback dispatch URL to connect over 127.0.0.1 while preserving the
	 * real host in a Host header, so concurrent curl_multi handles do not stall on
	 * host resolution (see the rationale in {@see self::trigger_async_runner()}).
	 *
	 * curl_multi (used by request_multiple) does NOT fire WordPress's `http_api_curl`
	 * hook, so any CURLOPT_RESOLVE pin a runtime installs there does not apply to it.
	 * Rewriting the host to the loopback IP and carrying the original host name in a
	 * `Host:` header reproduces that pin explicitly: the TCP connection goes straight
	 * to 127.0.0.1 (no DNS), and the local server still virtual-hosts the request to
	 * the correct site by the Host header. By default only the host is rewritten;
	 * scheme, port, path, and query (the AS nonce) are preserved unchanged.
	 *
	 * ## Why scheme + port must come from the INTERNAL server, not the public URL
	 *
	 * The dispatch URL is derived from `admin_url( 'admin-ajax.php' )`, i.e. the
	 * site's PUBLIC front door. On a single-server site the loopback and the public
	 * URL share a scheme/port, so blindly reusing them is correct. But on a SPLIT
	 * runtime — a public HTTPS front door on `.local:443` fronting an internal
	 * plain-HTTP worker pool on `localhost:8882` — they diverge. There, reusing the
	 * public `https://…:443` points the loopback at a TLS listener that does not
	 * exist on 127.0.0.1, so every concurrent async-runner POST fails the TLS
	 * handshake and the fan-out silently collapses to the serial WP-Cron drain.
	 *
	 * The loopback must therefore target where the local server is ACTUALLY
	 * reachable on the loop — its real scheme, host, and port — which only the
	 * runtime knows. The `agents_workflow_async_runner_loopback_base` filter lets a
	 * runtime (or an mu-plugin) declare that internal base, e.g.
	 * `http://localhost:8882`. When set, its scheme/host/port replace the public
	 * ones and the original path + query (the AS nonce) are carried onto it; the
	 * canonical Host header still names the PUBLIC host:port so the local server
	 * virtual-hosts the request to the correct site. When the filter is unset the
	 * behavior is byte-for-byte the pre-filter default (host → 127.0.0.1, scheme +
	 * port + Host header from the public URL) so no single-server runtime regresses.
	 *
	 * When the URL cannot be parsed the original URL is returned with no extra header
	 * — a safe pass-through that keeps the normal resolution path.
	 *
	 * @since 0.5.0
	 *
	 * @param string $url The admin-ajax loopback URL to dispatch to.
	 * @return array{url:string,headers:array<string,string>} Rewritten URL + Host header.
	 */
	private static function loopback_dispatch_target( string $url ): array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return array(
				'url'     => $url,
				'headers' => array(),
			);
		}

		$host  = (string) $parts['host'];
		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query = isset( $parts['query'] ) && '' !== (string) $parts['query'] ? '?' . (string) $parts['query'] : '';

		// The canonical Host authority is always the PUBLIC host (+ its explicit port,
		// if any) — the local server virtual-hosts on it, so a bare host or the
		// internal port would not match the site.
		$public_port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$host_header = '' !== $public_port ? $host . $public_port : $host;

		// A runtime whose internal loopback listener differs from its public front
		// door (different scheme/host/port) declares that internal base here. Empty
		// (the default) keeps the historical behavior below. The base is scheme +
		// authority only, e.g. `http://localhost:8882`; the path + query (AS nonce)
		// are always taken from the parsed dispatch URL.
		$loopback_base = self::string_value( apply_filters( 'agents_workflow_async_runner_loopback_base', '', $url ) );
		if ( '' !== $loopback_base ) {
			$base_parts = wp_parse_url( $loopback_base );
			if ( is_array( $base_parts ) && ! empty( $base_parts['host'] ) ) {
				$base_scheme = isset( $base_parts['scheme'] ) && '' !== (string) $base_parts['scheme'] ? (string) $base_parts['scheme'] : 'http';
				$base_host   = (string) $base_parts['host'];
				$base_port   = isset( $base_parts['port'] ) ? ':' . (int) $base_parts['port'] : '';

				// If the runtime pointed us at a loopback host (localhost / 127.0.0.1),
				// no Host header rewrite is needed to dodge mDNS — but we STILL carry the
				// canonical public Host header so the local server routes to the right
				// site (its listener may serve several by Host).
				return array(
					'url'     => $base_scheme . '://' . $base_host . $base_port . $path . $query,
					'headers' => array( 'Host' => $host_header ),
				);
			}
		}

		// Default (no override): already a loopback address — nothing to rewrite, no
		// Host header needed.
		if ( '127.0.0.1' === $host || 'localhost' === $host ) {
			return array(
				'url'     => $url,
				'headers' => array(),
			);
		}

		// Default (no override): rewrite the host to 127.0.0.1 to dodge mDNS, carrying
		// the public scheme + port unchanged (correct for a single-server site).
		$scheme = isset( $parts['scheme'] ) && '' !== (string) $parts['scheme'] ? (string) $parts['scheme'] : 'https';

		return array(
			'url'     => $scheme . '://127.0.0.1' . $public_port . $path . $query,
			'headers' => array( 'Host' => $host_header ),
		);
	}

	/**
	 * Whether every handle is terminal. Authoritative source is the
	 * reconcile-tracked frame status stamped on each handle (the runner keeps it
	 * current via {@see agents_reconcile_workflow_branch()}); this executor does
	 * not poll AS. When a `ref` (AS action id) is present it is cross-checked so
	 * an action that finished without reconciling (a crashed callback) is not
	 * mistaken for still-in-flight forever — but the frame status wins.
	 *
	 * @inheritDoc
	 * @since 0.5.0
	 */
	public function are_all_complete( array $handles ): bool {
		foreach ( $handles as $handle ) {
			$status = self::string_value( $handle['status'] ?? '' );
			if ( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED === $status
				|| WP_Agent_Workflow_Run_Result::STATUS_FAILED === $status ) {
				continue;
			}
			return false;
		}
		return true;
	}

	/**
	 * Collect terminal branch outputs keyed by the branch key (role or index).
	 * The authoritative outputs live in the reconcile-tracked frame; a handle
	 * only carries its key + status here, so `collect()` returns an empty result
	 * per key. The runner reads reconciled outputs from the frame's `completed`
	 * map (via {@see agents_workflow_branch_results_by_key()}), not from here —
	 * this method exists to satisfy the interface for the already-complete
	 * (synchronous) inline path, which the AS executor never takes.
	 *
	 * @inheritDoc
	 * @since 0.5.0
	 */
	public function collect( array $handles ): array {
		$out = array();
		foreach ( $handles as $handle ) {
			$key         = self::string_value( $handle['key'] ?? '' );
			$out[ $key ] = array(
				'key'    => $key,
				'status' => self::string_value( $handle['status'] ?? 'dispatched' ),
				'output' => null,
			);
		}
		return $out;
	}

	// ── Action callbacks ─────────────────────────────────────────────────────

	/**
	 * The BRANCH_HOOK callback. Rehydrates one branch from its self-contained
	 * descriptor, runs it through the SHARED branch runner
	 * ({@see WP_Agent_Workflow_Runner::run_branch_steps()} — the exact code the
	 * synchronous path uses), builds a BranchResult, and drives the REAL
	 * reconcile entry point. Nothing about branch execution diverges from sync;
	 * only WHERE (a claimed AS action) and WHEN (its result lands via reconcile).
	 *
	 * @since 0.5.0
	 *
	 * @param array<mixed> $payload Action payload: { run_id, handle_id, store_ref, context_ref }.
	 * @return void
	 */
	public static function run_branch_action( array $payload ): void {
		$run_id      = self::string_value( $payload['run_id'] ?? '' );
		$handle_id   = self::string_value( $payload['handle_id'] ?? '' );
		$store_ref   = self::string_value( $payload['store_ref'] ?? '' );
		$context_ref = self::string_value( $payload['context_ref'] ?? '' );

		if ( '' === $run_id || '' === $handle_id ) {
			return;
		}

		// Rehydrate the full self-contained descriptor from the branch store using
		// the lightweight ref the AS args carried. The store re-seats the run-scoped
		// shared context into branch_vars.context, so the branch runs against the
		// same descriptor shape the runner built at dispatch. A backward-compatible
		// inline descriptor (a payload enqueued before the offload) is still honored.
		$descriptor = '' !== $store_ref
			? WP_Agent_Workflow_Branch_Store::get_branch( $store_ref, $context_ref )
			: null;
		if ( null === $descriptor && is_array( $payload['branch'] ?? null ) ) {
			$descriptor = self::string_keyed_array( $payload['branch'] );
		}

		if ( ! is_array( $descriptor ) ) {
			// The stored descriptor is gone (expired / evicted) and no inline
			// fallback exists. Reconcile a clean failure so the run does not hang
			// SUSPENDED against a branch that can no longer run.
			$branch_result = array(
				'key'    => '',
				'status' => WP_Agent_Workflow_Run_Result::STATUS_FAILED,
				'output' => null,
				'steps'  => array(),
				'error'  => array(
					'code'    => 'workflow_branch_descriptor_missing',
					'message' => sprintf( 'Branch descriptor for handle `%s` (run `%s`) could not be rehydrated from the branch store.', $handle_id, $run_id ),
				),
				'item'   => null,
			);
			agents_reconcile_workflow_branch( $run_id, $handle_id, $branch_result );
			return;
		}

		$key           = self::string_value( $descriptor['key'] ?? '' );
		$branch_result = self::execute_branch( $descriptor, $key );

		agents_reconcile_workflow_branch( $run_id, $handle_id, $branch_result );
	}

	/**
	 * Run one branch's nested steps through the shared runner and normalize the
	 * outcome into a BranchResult ({ key, status, output, steps, error, item }).
	 * A required-branch failure is reported as a `failed` BranchResult so the
	 * runner's reconcile applies the same required-branch rule the sync path
	 * does; a non-required failure surfaces the error as the branch output but
	 * reports `succeeded`, matching {@see WP_Agent_Workflow_Runner::run_role_branch()}.
	 *
	 * @since 0.5.0
	 *
	 * @param array<string,mixed> $descriptor The self-contained branch descriptor.
	 * @param string              $key        Branch key (role or index).
	 * @return array<string,mixed> BranchResult.
	 */
	private static function execute_branch( array $descriptor, string $key ): array {
		$steps = is_array( $descriptor['steps'] ?? null ) ? $descriptor['steps'] : array();
		if ( empty( $steps ) ) {
			return array(
				'key'    => $key,
				'status' => WP_Agent_Workflow_Run_Result::STATUS_FAILED,
				'output' => null,
				'steps'  => array(),
				'error'  => array(
					'code'    => 'workflow_parallel_branch_steps_invalid',
					'message' => sprintf( 'parallel branch `%s` must include a non-empty nested `steps` list.', $key ),
				),
				'item'   => $descriptor['item'] ?? null,
			);
		}

		$continue_on_error = ! empty( $descriptor['continue_on_error'] );
		$required          = ! empty( $descriptor['required'] );
		$branch_vars       = is_array( $descriptor['branch_vars'] ?? null ) ? $descriptor['branch_vars'] : array();

		$handlers = self::resolve_handlers();
		$executor = new WP_Agent_Workflow_Step_Executor( $handlers );

		// Rebuild the branch context: a fresh, isolated per-branch context with
		// the branch's scoped vars (shared context + role contract, or the map
		// item/index), exactly like the sync branch context.
		$branch_context = ( new WP_Agent_Workflow_Run_Context(
			array(
				'inputs' => array(),
				'steps'  => array(),
				'vars'   => array(),
			)
		) )->with_vars( $branch_vars );

		$run = WP_Agent_Workflow_Runner::run_branch_steps(
			$steps,
			$branch_context,
			$executor,
			$handlers,
			$continue_on_error,
			$key
		);

		if ( is_wp_error( $run ) ) {
			if ( $required ) {
				return array(
					'key'    => $key,
					'status' => WP_Agent_Workflow_Run_Result::STATUS_FAILED,
					'output' => null,
					'steps'  => array(),
					'error'  => array(
						'code'    => $run->get_error_code(),
						'message' => $run->get_error_message(),
					),
					'item'   => $descriptor['item'] ?? null,
				);
			}

			// A non-required branch surfaces its error as the branch output but
			// still reports succeeded so the run is not failed by it.
			return array(
				'key'    => $key,
				'status' => WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED,
				'output' => array(
					'error' => array(
						'code'    => $run->get_error_code(),
						'message' => $run->get_error_message(),
					),
				),
				'steps'  => array(),
				'error'  => null,
				'item'   => $descriptor['item'] ?? null,
			);
		}

		return array(
			'key'    => $key,
			'status' => WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED,
			'output' => $run['last'],
			'steps'  => $run['steps'],
			'error'  => null,
			'item'   => $descriptor['item'] ?? null,
		);
	}

	/**
	 * Deferred-resume seam handler. Hooked on `wp_agent_workflow_resume_dispatch`
	 * (fired by the reconcile entry point once every branch is terminal). When
	 * the suspended run is owned by THIS executor, enqueue a single claimed
	 * RESUME action and return `true` so reconcile does NOT resume inline. AS's
	 * atomic claim guarantees exactly one of these actions is ever claimed-and-
	 * run, so a simultaneous multi-branch finish enqueues at most one effective
	 * resume; the handler re-checks SUSPENDED and no-ops otherwise.
	 *
	 * @since 0.5.0
	 *
	 * @param bool   $deferred    Whether resume is already deferred.
	 * @param string $run_id      The suspended run id.
	 * @param string $executor_id The frame's owning executor id.
	 * @return bool True when this executor claimed the resume.
	 */
	public static function maybe_defer_resume( bool $deferred, string $run_id, string $executor_id ): bool {
		if ( $deferred ) {
			return true;
		}
		if ( self::ID !== $executor_id || '' === $run_id ) {
			return false;
		}
		if ( ! self::is_available() ) {
			// AS vanished mid-flight — let reconcile resume inline rather than
			// strand the run.
			return false;
		}

		self::enqueue_async_action(
			self::RESUME_HOOK,
			array( array( 'run_id' => $run_id ) ),
			self::GROUP
		);

		return true;
	}

	/**
	 * The RESUME_HOOK callback. AS claimed this action exactly once. Re-check the
	 * run is still SUSPENDED (a concurrent claim may already have resumed it) and
	 * resume exactly once; a run that already resumed is a harmless no-op. This
	 * re-check-then-resume is the whole exactly-once correctness point — the
	 * guard is AS's claim, and the re-read of the frame's SUSPENDED status.
	 *
	 * @since 0.5.0
	 *
	 * @param array<mixed> $payload Action payload: { run_id }.
	 * @return void
	 */
	public static function run_resume_action( array $payload ): void {
		$run_id = self::string_value( $payload['run_id'] ?? '' );
		if ( '' === $run_id ) {
			return;
		}

		$recorder = agents_workflow_resolve_recorder();
		if ( null === $recorder ) {
			return;
		}

		$result = $recorder->find( $run_id );
		if ( null === $result || ! $result->is_suspended() ) {
			// Already resumed (or gone). The claimed action is a no-op. This is
			// the second-of-two simultaneous finishers being deduped by AS's
			// claim + this SUSPENDED re-check.
			return;
		}

		$runner = agents_workflow_resolve_runner( $recorder );
		$runner->resume( $run_id );

		// The run has resolved (the suspension frame was cleared by resume). Release
		// this run's stored branch payloads so no orphan option rows linger — same
		// cleanup discipline the suspension frame follows.
		WP_Agent_Workflow_Branch_Store::forget_run( $run_id );
	}

	// ── Internals ────────────────────────────────────────────────────────────

	/**
	 * Enqueue an async action, tolerating environments where the AS shim only
	 * defines the bare function. Returns the action id (int), or 0 on failure.
	 *
	 * FAIL-LOUD SEAM. Action Scheduler THROWS from `as_enqueue_async_action()`
	 * when the enqueue is rejected (most relevantly its 8,000-char args-size
	 * guard: `ActionScheduler_Action::$args too long`). When AS's queue runner
	 * calls this it catches+logs+swallows that throw, so the throw never reaches
	 * the caller — the enqueue silently returns nothing and the branch is never
	 * durably scheduled. We normalize BOTH failure modes to a `0` return so the
	 * caller ({@see self::dispatch()}) can detect the failure and fail the run
	 * loudly instead of returning a phantom `ref=0` handle that suspends against
	 * a branch that does not exist.
	 *
	 * @since 0.5.0
	 *
	 * @param string       $hook  Action hook.
	 * @param array<mixed> $args  Action args (a single-element list holding the payload array).
	 * @param string       $group Action group.
	 * @return int Action id, or 0 when the enqueue failed (threw or returned no id).
	 */
	private static function enqueue_async_action( string $hook, array $args, string $group ): int {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return 0;
		}
		try {
			// AS returns the new action id (a positive int) on success. dispatch()
			// treats a non-positive return as a hard failure.
			return (int) as_enqueue_async_action( $hook, $args, $group );
		} catch ( \Throwable $error ) {
			// AS rejected the enqueue (e.g. args too long / queue unavailable).
			// Normalize to 0 so dispatch() surfaces a clean WP_Error rather than
			// letting the throw be swallowed by AS's queue runner into a hang.
			unset( $error );
			return 0;
		}
	}

	/**
	 * Resolve the step-type handler map used to run a rehydrated branch's steps.
	 * Reuses the same reconcile-side resolver so branch execution and aggregate
	 * execution share one handler map.
	 *
	 * @since 0.5.0
	 *
	 * @return array<string,mixed>
	 */
	private static function resolve_handlers(): array {
		return agents_workflow_resolve_step_handlers();
	}

	/**
	 * Strip the shared immutable context out of a branch descriptor before it is
	 * stored. The shared context lives ONCE in the run-scoped context row; keeping
	 * a copy inside every branch descriptor would re-introduce the N-copies
	 * duplication the store exists to remove. The per-branch role/item scoping in
	 * `branch_vars` is left intact.
	 *
	 * @since 0.5.0
	 *
	 * @param array<string,mixed> $descriptor Branch descriptor.
	 * @return array<string,mixed>
	 */
	private static function strip_shared_context( array $descriptor ): array {
		if ( is_array( $descriptor['branch_vars'] ?? null ) && array_key_exists( 'context', $descriptor['branch_vars'] ) ) {
			unset( $descriptor['branch_vars']['context'] );
		}
		return $descriptor;
	}

	/**
	 * Recover a run id from the first branch descriptor as a belt-and-suspenders
	 * source for the run-scoped shared-context key when the dispatch context did
	 * not carry `_workflow_run_id`.
	 *
	 * @since 0.5.0
	 *
	 * @param array<int,array<string,mixed>> $branches Branch descriptors.
	 * @return string
	 */
	private static function first_branch_run_id( array $branches ): string {
		foreach ( $branches as $branch ) {
			$run_id = self::string_value( $branch['run_id'] ?? '' );
			if ( '' !== $run_id ) {
				return $run_id;
			}
		}
		return '';
	}

	/**
	 * Build a handle id unique within the run: `<run_id>:<step_id>:<key>:<index>`.
	 *
	 * @since 0.5.0
	 */
	private static function build_handle_id( string $run_id, string $step_id, string $key, int $index ): string {
		$parts = array_filter(
			array( $run_id, $step_id, $key, (string) $index ),
			static function ( string $part ): bool {
				return '' !== $part;
			}
		);
		return implode( ':', $parts );
	}

	/**
	 * @param mixed $value Value to normalize.
	 */
	private static function string_value( $value ): string {
		if ( is_scalar( $value ) || $value instanceof \Stringable ) {
			return (string) $value;
		}
		return '';
	}

	/**
	 * Keep only string keys, giving PHPStan a precise `array<string,mixed>` for
	 * a descriptor rehydrated from an opaque action payload.
	 *
	 * @param array<mixed> $value Raw array.
	 * @return array<string,mixed>
	 */
	private static function string_keyed_array( array $value ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $item;
			}
		}
		return $result;
	}
}
