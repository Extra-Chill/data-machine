<?php
/**
 * Table-free, option-backed store for out-of-band branch payloads.
 *
 * WHY THIS EXISTS. The Action Scheduler branch executor enqueues one async
 * action per parallel branch. Before this store, the branch's ENTIRE
 * self-contained descriptor — nested steps, per-branch vars, AND the shared
 * immutable `context` snapshot (design intent, spec, brief) — rode INLINE in
 * the AS action `args`. Two things made that unsafe on any realistically-sized
 * workflow:
 *
 *   1. PAYLOAD SCALING. Action Scheduler enforces a hard 8,000-character limit
 *      on its `args` column (`ActionScheduler_Action::$args too long ... should
 *      not be more than 8000 characters when encoded as JSON`). A descriptor
 *      whose shared context is a few KB (a multi-page brief) blows past that
 *      limit and every enqueue throws. The payload scaled with context richness
 *      — precisely the workflows the async fanout exists to serve.
 *   2. DUPLICATION. The shared `context` is identical for every sibling branch,
 *      yet it was copied into each branch descriptor — N copies of the same
 *      multi-KB blob, compounding (1).
 *
 * This store moves the heavy payload OUT of the AS args and into option rows,
 * leaving only a small, stable reference in the args (`{ run_id, handle_id,
 * store_ref, context_ref }`) regardless of how rich the context is. The shared
 * context is stored ONCE per run (a run-scoped row) rather than duplicated into
 * every branch, so even the stored descriptors stay lean.
 *
 * TABLE-FREE. Under the substrate's no-new-tables constraint (agents-api is
 * headed for wpcom / WordPress core) this uses the WordPress options table —
 * the same table-free discipline as {@see WP_Agent_Workflow_Reconcile_Lock}
 * (which uses `add_option()` as a CAS) and the suspension frame (which lives in
 * `metadata._suspension`). No new DB table, no external service.
 *
 * LIFECYCLE. Rows carry an expiry so a run that dies before it resolves does
 * not strand orphaned option rows forever; a healthy run deletes its own rows
 * on resume via {@see self::forget_run()}. The TTL is generous relative to a
 * real fanout so a slow-but-live run is never evicted mid-flight, yet bounded
 * so a crashed run's rows are reclaimable.
 *
 * PLUGGABLE. A consumer with a stronger payload store (a custom table, an
 * object cache, an external blob store) can replace persistence via the
 * `wp_agent_workflow_branch_store` filter. The default here is correct and
 * dependency-free.
 *
 * @package AgentsAPI
 * @since   0.5.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

/**
 * Default option-backed branch payload store.
 */
final class WP_Agent_Workflow_Branch_Store {

	public const BACKEND_BUILTIN = 'builtin';
	public const BACKEND_CUSTOM  = 'custom';
	public const BACKEND_LEGACY  = 'legacy';
	public const BACKEND_TRANSITION = 'transition';

	/**
	 * Option-name prefix for a per-branch descriptor row. The run id and handle
	 * id are folded into the key so rows never collide across runs/branches and
	 * are trivially inspectable / cleanable.
	 *
	 * @since 0.5.0
	 */
	private const BRANCH_PREFIX = 'agents_wf_branch_';

	/**
	 * Option-name prefix for the run-scoped shared-context row. Stored ONCE per
	 * run so a shared context (identical for every branch) is not duplicated N
	 * times across branch rows.
	 *
	 * @since 0.5.0
	 */
	private const CONTEXT_PREFIX = 'agents_wf_branch_ctx_';

	/** Reconcile receipt key stored inside each branch descriptor. */
	private const RECONCILE_RECEIPT_KEY = '_agents_reconcile_receipt';

	/**
	 * Option-name prefix for the per-run index of branch ref keys, so
	 * {@see self::forget_run()} can delete every branch row a run wrote without
	 * scanning the options table.
	 *
	 * @since 0.5.0
	 */
	private const INDEX_PREFIX = 'agents_wf_branch_index_';

	/**
	 * Option-name prefix for dispatch admission fences.
	 */
	private const ADMISSION_PREFIX = 'agents_wf_branch_admission_';

	/**
	 * Payload time-to-live (seconds). After this a row belonging to a run that
	 * never resolved is treated as expired and returns nothing on read. Generous
	 * (2 hours) so a slow-but-live fanout is never evicted mid-flight, yet
	 * bounded so a crashed run's rows do not linger indefinitely.
	 *
	 * @since 0.5.0
	 */
	private const TTL_SECONDS = 7200;

	/**
	 * Begin a fenced fan-out admission generation.
	 *
	 * Branch callbacks may be claimed while their siblings are still being
	 * enqueued, so they must not begin effects until this generation is admitted.
	 *
	 * @param string $run_id Run being admitted.
	 * @return string Opaque admission token, or an empty string on persistence failure.
	 */
	public static function begin_admission( string $run_id ): string {
		if ( '' === $run_id || ! function_exists( 'delete_option' ) ) {
			return '';
		}

		$ref   = self::ADMISSION_PREFIX . md5( $run_id );
		$token = $ref . ':' . md5( uniqid( $run_id . ':', true ) );
		self::write_row(
			$ref,
			array(
				'run_id'  => $run_id,
				'token'   => $token,
				'status'  => 'pending',
				'expires' => time() + self::TTL_SECONDS,
			)
		);

		if ( 'pending' === self::admission_status( $token ) ) {
			return $token;
		}

		delete_option( $ref );
		return '';
	}

	/**
	 * Open an admission fence after every sibling is durably enqueued.
	 */
	public static function admit( string $token ): bool {
		$ref = self::admission_ref( $token );
		$row = '' !== $ref ? self::read_row( $ref ) : null;
		if ( null === $row || ! hash_equals( $token, is_string( $row['token'] ?? null ) ? $row['token'] : '' ) || 'pending' !== ( $row['status'] ?? '' ) ) {
			return false;
		}

		$row['status'] = 'admitted';
		self::write_row( $ref, $row );
		return 'admitted' === self::admission_status( $token );
	}

	/**
	 * Close an admission fence before compensating a partial enqueue.
	 */
	public static function reject_admission( string $token ): void {
		$ref = self::admission_ref( $token );
		$row = '' !== $ref ? self::read_row( $ref ) : null;
		if ( null === $row || ! hash_equals( $token, is_string( $row['token'] ?? null ) ? $row['token'] : '' ) ) {
			return;
		}

		$row['status'] = 'rejected';
		self::write_row( $ref, $row );
	}

	/**
	 * Read an admission generation's state. A missing or expired fence is closed.
	 *
	 * @return string pending, admitted, or rejected.
	 */
	public static function admission_status( string $token ): string {
		$ref    = self::admission_ref( $token );
		$row    = '' !== $ref ? self::read_row( $ref ) : null;
		if ( null === $row || ! hash_equals( $token, is_string( $row['token'] ?? null ) ? $row['token'] : '' ) ) {
			return 'rejected';
		}
		$status = is_string( $row['status'] ?? null ) ? $row['status'] : '';
		return in_array( $status, array( 'pending', 'admitted' ), true ) ? $status : 'rejected';
	}

	/**
	 * Persist one branch descriptor under a per-(run_id, handle_id) key and
	 * return the opaque store ref the AS args carry. The descriptor stored here
	 * has its shared `context` STRIPPED — the branch action rehydrates that from
	 * the run-scoped context row instead (see {@see self::put_shared_context()}),
	 * so the shared context is not duplicated into every branch row.
	 *
	 * @since 0.5.0
	 *
	 * @param string              $run_id     Run the branch reconciles against.
	 * @param string              $handle_id  Unique branch handle id within the run.
	 * @param array<string,mixed> $descriptor Self-contained branch descriptor (context stripped).
	 * @return string The store ref (the option name) placed in the AS args.
	 */
	public static function put_branch( string $run_id, string $handle_id, array $descriptor ): string {
		$stored = self::put_branch_with_provenance( $run_id, $handle_id, $descriptor );
		return $stored['ref'];
	}

	/**
	 * Persist a descriptor and report which backend owns its opaque ref.
	 *
	 * @param string              $run_id     Run id.
	 * @param string              $handle_id  Branch handle id.
	 * @param array<string,mixed> $descriptor Branch descriptor.
	 * @return array{ref:string,backend:string}
	 */
	public static function put_branch_with_provenance( string $run_id, string $handle_id, array $descriptor ): array {
		$override = self::filtered_put_branch( $run_id, $handle_id, $descriptor );
		if ( is_string( $override ) ) {
			return array( 'ref' => $override, 'backend' => self::BACKEND_CUSTOM );
		}

		$ref = self::branch_ref( $run_id, $handle_id );
		self::write_row(
			$ref,
			array(
				'run_id'     => $run_id,
				'handle_id'  => $handle_id,
				'descriptor' => $descriptor,
				'expires'    => time() + self::TTL_SECONDS,
			)
		);
		self::index_ref( $run_id, $ref );
		return array( 'ref' => $ref, 'backend' => self::BACKEND_BUILTIN );
	}

	/**
	 * Persist the run-scoped shared context ONCE and return its ref. Every branch
	 * ref points at this single row rather than carrying its own copy of a
	 * multi-KB context.
	 *
	 * @since 0.5.0
	 *
	 * @param string              $run_id  Run the context belongs to.
	 * @param array<string,mixed> $context Shared immutable context snapshot.
	 * @return string The context ref (the option name) placed in the AS args.
	 */
	public static function put_shared_context( string $run_id, array $context ): string {
		$ref = self::CONTEXT_PREFIX . md5( $run_id );
		self::write_row(
			$ref,
			array(
				'run_id'  => $run_id,
				'context' => $context,
				'expires' => time() + self::TTL_SECONDS,
			)
		);
		return $ref;
	}

	/**
	 * Rehydrate a stored branch descriptor from its ref, re-merging the
	 * run-scoped shared context (from `$context_ref`) back into the descriptor's
	 * `branch_vars.context`. Returns null when the row is missing or expired (a
	 * caller must treat that as a branch that can no longer run).
	 *
	 * @since 0.5.0
	 *
	 * @param string $store_ref   The branch descriptor ref.
	 * @param string $context_ref The run-scoped shared-context ref ('' when none).
	 * @return array<string,mixed>|null The reassembled descriptor, or null.
	 */
	public static function get_branch( string $store_ref, string $context_ref ): ?array {
		$override = self::filtered_get_branch( $store_ref, $context_ref );
		if ( is_array( $override ) ) {
			return $override;
		}

		$row = self::read_row( $store_ref );
		if ( null === $row || ! is_array( $row['descriptor'] ?? null ) ) {
			return null;
		}

		/** @var array<string,mixed> $descriptor */
		$descriptor = $row['descriptor'];

		if ( '' !== $context_ref ) {
			$context_row = self::read_row( $context_ref );
			$context     = is_array( $context_row['context'] ?? null ) ? $context_row['context'] : array();
			$branch_vars = is_array( $descriptor['branch_vars'] ?? null ) ? $descriptor['branch_vars'] : array();
			// Re-seat the shared context the run-scoped row owns; branch_vars kept
			// its per-branch role/item scoping, which never left the branch row.
			$branch_vars['context']     = $context;
			$descriptor['branch_vars']  = $branch_vars;
		}

		return $descriptor;
	}

	/**
	 * Persist a terminal branch result and opaque continuation state as a
	 * receipt-only record. This deliberately replaces neither a stale descriptor
	 * snapshot nor the shared run index, so terminal cleanup cannot be followed by
	 * descriptor resurrection.
	 *
	 * @since 0.7.0
	 *
	 * @param string              $run_id        Run the branch belongs to.
	 * @param string              $handle_id     Branch handle id.
	 * @param string              $store_ref     Existing branch descriptor ref.
	 * @param string              $context_ref   Existing shared-context ref.
	 * @param string              $backend       Explicit descriptor backend provenance.
	 * @param array<string,mixed> $branch_result Terminal BranchResult.
	 * @param array<string,mixed> $continuation  Opaque reconcile continuation state.
	 * @return string|\WP_Error Durable receipt ref, or a hard persistence failure.
	 */
	public static function put_reconcile_receipt( string $run_id, string $handle_id, string $store_ref, string $context_ref, string $backend, array $branch_result, array $continuation = array() ) {
		$receipt = array(
			'branch_result' => $branch_result,
			'continuation'  => $continuation,
		);

		if ( self::BACKEND_BUILTIN === $backend ) {
			self::write_row(
				$store_ref,
				array(
					'run_id'                       => $run_id,
					'handle_id'                    => $handle_id,
					self::RECONCILE_RECEIPT_KEY    => $receipt,
					'expires'                      => time() + self::TTL_SECONDS,
				)
			);
			$persisted = self::read_row( $store_ref );
			if ( $receipt === ( $persisted[ self::RECONCILE_RECEIPT_KEY ] ?? null ) ) {
				return $store_ref;
			}

			return new \WP_Error( 'workflow_branch_result_persistence_failed', sprintf( 'Could not durably persist the terminal result for branch `%s` in run `%s`.', $handle_id, $run_id ) );
		}

		if ( self::BACKEND_CUSTOM !== $backend ) {
			return new \WP_Error( 'workflow_branch_receipt_backend_unknown', sprintf( 'Branch `%s` in run `%s` has no explicit receipt backend provenance.', $handle_id, $run_id ) );
		}

		$result_ref = self::filtered_put_receipt( $store_ref, $run_id, $handle_id, $receipt );
		if ( null === $result_ref ) {
			return new \WP_Error( 'workflow_branch_receipt_backend_unsupported', sprintf( 'The custom branch store that owns `%s` does not provide durable reconcile receipt persistence.', $store_ref ) );
		}

		$persisted = self::filtered_get_receipt( $result_ref, $context_ref );
		if ( null === $persisted || $receipt !== $persisted ) {
			return new \WP_Error( 'workflow_branch_result_persistence_failed', sprintf( 'The branch store could not verify the terminal result for branch `%s` in run `%s`.', $handle_id, $run_id ) );
		}

		return $result_ref;
	}

	/**
	 * Read a durable reconcile receipt through the same built-in or custom store
	 * that owns the branch descriptor.
	 *
	 * @since 0.7.0
	 *
	 * @param string $result_ref  Opaque receipt ref.
	 * @param string $context_ref Existing shared-context ref.
	 * @param string $backend     Explicit descriptor backend provenance.
	 * @return array{branch_result:array<string,mixed>,continuation:array<string,mixed>}|null
	 */
	public static function get_reconcile_receipt( string $result_ref, string $context_ref, string $backend ): ?array {
		if ( self::BACKEND_CUSTOM === $backend ) {
			$receipt = self::filtered_get_receipt( $result_ref, $context_ref );
			if ( null === $receipt || ! is_array( $receipt['branch_result'] ?? null ) ) {
				return null;
			}
			return array(
				'branch_result' => self::string_keyed_array( $receipt['branch_result'] ),
				'continuation'  => is_array( $receipt['continuation'] ?? null ) ? self::string_keyed_array( $receipt['continuation'] ) : array(),
			);
		}
		if ( self::BACKEND_BUILTIN !== $backend ) {
			return null;
		}
		$row = self::read_row( $result_ref );
		if ( null !== $row && is_array( $row[ self::RECONCILE_RECEIPT_KEY ] ?? null ) ) {
			$receipt = $row[ self::RECONCILE_RECEIPT_KEY ];
		} else {
			$descriptor = null !== $row && is_array( $row['descriptor'] ?? null )
			? $row['descriptor']
			: self::filtered_get_branch( $result_ref, $context_ref );
			$receipt = is_array( $descriptor[ self::RECONCILE_RECEIPT_KEY ] ?? null ) ? $descriptor[ self::RECONCILE_RECEIPT_KEY ] : array();
		}
		if ( ! is_array( $receipt['branch_result'] ?? null ) ) {
			return null;
		}

		return array(
			'branch_result' => self::string_keyed_array( $receipt['branch_result'] ),
			'continuation'  => is_array( $receipt['continuation'] ?? null ) ? self::string_keyed_array( $receipt['continuation'] ) : array(),
		);
	}

	/**
	 * Resolve a receipt ref from explicit backend ownership and descriptor identity.
	 *
	 * @return string Empty when no receipt is discoverable.
	 */
	public static function locate_reconcile_receipt( string $store_ref, string $run_id, string $handle_id, string $context_ref, string $backend ): string {
		if ( self::BACKEND_BUILTIN === $backend ) {
			return $store_ref;
		}
		if ( self::BACKEND_CUSTOM !== $backend || ! function_exists( 'apply_filters' ) ) {
			return '';
		}
		/**
		 * Locate a custom reconcile receipt by its owning descriptor identity.
		 *
		 * @since 0.7.0
		 *
		 * @param string $receipt_ref No locator by default.
		 * @param string $store_ref   Descriptor ref.
		 * @param string $run_id      Run id.
		 * @param string $handle_id   Handle id.
		 * @param string $context_ref Shared-context ref.
		 */
		$receipt_ref = apply_filters( 'wp_agent_workflow_branch_receipt_locate', '', $store_ref, $run_id, $handle_id, $context_ref );
		return $receipt_ref;
	}

	/**
	 * Delete one successfully reconciled receipt without touching sibling refs.
	 *
	 * @since 0.7.0
	 *
	 * @param string $run_id      Run id.
	 * @param string $handle_id   Branch handle id.
	 * @param string $result_ref  Receipt ref.
	 * @param string $context_ref Shared-context ref for custom stores.
	 * @param string $backend     Explicit descriptor backend provenance.
	 * @return void
	 */
	public static function forget_reconcile_receipt( string $run_id, string $handle_id, string $result_ref, string $context_ref, string $backend ): void {
		if ( self::BACKEND_CUSTOM === $backend ) {
			self::filtered_forget_receipt( $result_ref, $run_id, $handle_id, $context_ref );
			return;
		}
		if ( self::BACKEND_BUILTIN !== $backend ) {
			return;
		}
		$row = self::read_row( $result_ref );
		if ( null === $row ) {
			return;
		}

		// Descriptor-backed and consumer-owned receipts belong to run-level cleanup.
		// Never rewrite them here: a resume may have already called forget_run()
		// after this read, and a write would resurrect the terminal run's storage.
		if ( is_array( $row['descriptor'] ?? null ) ) {
			return;
		}

		// Receipt-only missing-descriptor rows are safe to delete exactly. Deletion
		// remains harmless if terminal cleanup won the race first.
		if ( self::branch_ref( $run_id, $handle_id ) === $result_ref && function_exists( 'delete_option' ) ) {
			delete_option( $result_ref );
		}
	}

	/**
	 * Delete every row a run wrote (its branch descriptors, its shared-context
	 * row, and its index) once the run resolves. Same cleanup discipline the
	 * suspension frame follows — no orphaned store rows.
	 *
	 * @since 0.5.0
	 *
	 * @param string $run_id Run whose payload rows are released.
	 * @return void
	 */
	public static function forget_run( string $run_id ): void {
		// Admission has a deterministic per-run ref, so its cleanup never depends
		// on the mutable branch index or a consumer-owned payload store.
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::ADMISSION_PREFIX . md5( $run_id ) );
		}
		if ( self::filtered_forget_run( $run_id ) ) {
			return;
		}
		if ( ! function_exists( 'delete_option' ) || ! function_exists( 'get_option' ) ) {
			return;
		}

		$index = get_option( self::INDEX_PREFIX . md5( $run_id ), array() );
		if ( is_array( $index ) ) {
			foreach ( $index as $ref ) {
				if ( is_string( $ref ) ) {
					delete_option( $ref );
				}
			}
		}
		delete_option( self::INDEX_PREFIX . md5( $run_id ) );
		delete_option( self::CONTEXT_PREFIX . md5( $run_id ) );
	}

	/**
	 * Resolve the deterministic option ref embedded in an admission token.
	 */
	private static function admission_ref( string $token ): string {
		$separator = strrpos( $token, ':' );
		$ref       = false !== $separator ? substr( $token, 0, $separator ) : '';
		return str_starts_with( $ref, self::ADMISSION_PREFIX ) ? $ref : '';
	}

	/**
	 * Write one option row (no autoload — these are transient runtime payloads).
	 *
	 * @since 0.5.0
	 *
	 * @param string              $option Option name.
	 * @param array<string,mixed> $value  Row value.
	 * @return bool Whether WordPress reported that it changed the row.
	 */
	private static function write_row( string $option, array $value ): bool {
		if ( function_exists( 'update_option' ) ) {
			return update_option( $option, $value, false );
		}
		return false;
	}

	/**
	 * Read one option row, returning null when missing or past its expiry.
	 *
	 * @since 0.5.0
	 *
	 * @param string $option Option name.
	 * @return array<string,mixed>|null
	 */
	private static function read_row( string $option ): ?array {
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}
		$row = get_option( $option, null );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$expires = is_numeric( $row['expires'] ?? null ) ? (int) $row['expires'] : 0;
		if ( $expires > 0 && $expires <= time() ) {
			if ( function_exists( 'delete_option' ) ) {
				delete_option( $option );
			}
			return null;
		}

		$normalized = array();
		foreach ( $row as $key => $value ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $value;
			}
		}
		return $normalized;
	}

	/**
	 * Record a branch ref in the per-run index so {@see self::forget_run()} can
	 * delete every branch row without scanning the options table.
	 *
	 * @since 0.5.0
	 *
	 * @param string $run_id Run id.
	 * @param string $ref    Branch ref (option name) to index.
	 * @return void
	 */
	private static function index_ref( string $run_id, string $ref ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		$option = self::INDEX_PREFIX . md5( $run_id );
		$index  = get_option( $option, array() );
		if ( ! is_array( $index ) ) {
			$index = array();
		}
		if ( ! in_array( $ref, $index, true ) ) {
			$index[] = $ref;
			update_option( $option, $index, false );
		}
	}

	/** Return the deterministic built-in descriptor ref for one branch. */
	private static function branch_ref( string $run_id, string $handle_id ): string {
		return self::BRANCH_PREFIX . md5( $run_id . ':' . $handle_id );
	}

	/**
	 * Offer a consumer's store the chance to persist a branch descriptor. A
	 * filter that returns a non-empty string ref owns persistence for this
	 * (run, handle); a falsey return falls through to the built-in option store.
	 *
	 * @since 0.5.0
	 *
	 * @param string              $run_id     Run id.
	 * @param string              $handle_id  Handle id.
	 * @param array<string,mixed> $descriptor Branch descriptor.
	 * @return string|null A ref when a consumer store handled it, else null.
	 */
	private static function filtered_put_branch( string $run_id, string $handle_id, array $descriptor ): ?string {
		if ( ! function_exists( 'apply_filters' ) ) {
			return null;
		}
		/**
		 * Filter branch-descriptor persistence. Return a non-empty string ref to
		 * take over storage with a custom backend; return null (the default) to
		 * use the built-in option store.
		 *
		 * @since 0.5.0
		 *
		 * @param string|null         $ref        No override by default.
		 * @param string              $run_id     Run id.
		 * @param string              $handle_id  Handle id.
		 * @param array<string,mixed> $descriptor Branch descriptor.
		 */
		$ref = apply_filters( 'wp_agent_workflow_branch_store_put', null, $run_id, $handle_id, $descriptor );
		return is_string( $ref ) && '' !== $ref ? $ref : null;
	}

	/**
	 * Offer a consumer's store the chance to rehydrate a branch descriptor.
	 *
	 * @since 0.5.0
	 *
	 * @param string $store_ref   Branch ref.
	 * @param string $context_ref Shared-context ref.
	 * @return array<string,mixed>|null A descriptor when a consumer store handled it, else null.
	 */
	private static function filtered_get_branch( string $store_ref, string $context_ref ): ?array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return null;
		}
		/**
		 * Filter branch-descriptor rehydration. Return an array descriptor to take
		 * over retrieval with a custom backend; return null (the default) to use
		 * the built-in option store.
		 *
		 * @since 0.5.0
		 *
		 * @param array<string,mixed>|null $descriptor No override by default.
		 * @param string                   $store_ref   Branch ref.
		 * @param string                   $context_ref Shared-context ref.
		 */
		$descriptor = apply_filters( 'wp_agent_workflow_branch_store_get', null, $store_ref, $context_ref );
		return is_array( $descriptor ) ? $descriptor : null;
	}

	/** @param array<string,mixed> $receipt */
	private static function filtered_put_receipt( string $store_ref, string $run_id, string $handle_id, array $receipt ): ?string {
		if ( ! function_exists( 'apply_filters' ) ) {
			return null;
		}
		/**
		 * Persist a reconcile receipt in the custom backend that owns a branch ref.
		 *
		 * @since 0.7.0
		 *
		 * @param string|null         $receipt_ref No implementation by default.
		 * @param string              $store_ref   Owning descriptor ref.
		 * @param string              $run_id      Run id.
		 * @param string              $handle_id   Handle id.
		 * @param array<string,mixed> $receipt     Receipt payload.
		 */
		$receipt_ref = apply_filters( 'wp_agent_workflow_branch_receipt_put', null, $store_ref, $run_id, $handle_id, $receipt );
		return is_string( $receipt_ref ) && '' !== $receipt_ref ? $receipt_ref : null;
	}

	/** @return array<string,mixed>|null */
	private static function filtered_get_receipt( string $receipt_ref, string $context_ref ): ?array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return null;
		}
		/**
		 * Read a reconcile receipt from a custom branch backend.
		 *
		 * @since 0.7.0
		 *
		 * @param array<string,mixed>|null $receipt    No implementation by default.
		 * @param string                   $receipt_ref Receipt ref.
		 * @param string                   $context_ref Shared-context ref.
		 */
		$receipt = apply_filters( 'wp_agent_workflow_branch_receipt_get', null, $receipt_ref, $context_ref );
		return is_array( $receipt ) ? $receipt : null;
	}

	private static function filtered_forget_receipt( string $receipt_ref, string $run_id, string $handle_id, string $context_ref ): bool {
		if ( ! function_exists( 'apply_filters' ) ) {
			return false;
		}
		/**
		 * Delete one reconcile receipt from a custom branch backend.
		 *
		 * @since 0.7.0
		 *
		 * @param bool   $handled     No implementation by default.
		 * @param string $receipt_ref Receipt ref.
		 * @param string $run_id      Run id.
		 * @param string $handle_id   Handle id.
		 * @param string $context_ref Shared-context ref.
		 */
		return (bool) apply_filters( 'wp_agent_workflow_branch_receipt_delete', false, $receipt_ref, $run_id, $handle_id, $context_ref );
	}

	/**
	 * Offer a consumer's store the chance to release a run's payload rows.
	 *
	 * @since 0.5.0
	 *
	 * @param string $run_id Run id.
	 * @return bool True when a consumer store handled cleanup (skip the built-in).
	 */
	private static function filtered_forget_run( string $run_id ): bool {
		if ( ! function_exists( 'apply_filters' ) ) {
			return false;
		}
		/**
		 * Filter branch-payload cleanup. Return true to take over cleanup with a
		 * custom backend; return false (the default) to use the built-in option
		 * store.
		 *
		 * @since 0.5.0
		 *
		 * @param bool   $handled Whether a consumer store handled cleanup.
		 * @param string $run_id  Run id.
		 */
		return (bool) apply_filters( 'wp_agent_workflow_branch_store_forget', false, $run_id );
	}

	/**
	 * Normalize an array to string keys for static-analysis-safe public returns.
	 *
	 * @param array<mixed> $value Value to normalize.
	 * @return array<string,mixed>
	 */
	private static function string_keyed_array( array $value ): array {
		$normalized = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $item;
			}
		}
		return $normalized;
	}

}
