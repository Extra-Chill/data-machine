//! daily — extracted from MemoryCommand.php.


	/**
	 * Daily memory operations.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action to perform: list, read, write, append, delete, search.
	 *
	 * [<date>]
	 * : Date in YYYY-MM-DD format. Defaults to today for write/append.
	 *
	 * [<content>]
	 * : Content for write/append actions.
	 *
	 * [--agent=<slug>]
	 * : Agent slug or numeric ID.
	 *
	 * ## EXAMPLES
	 *
	 *     # List all daily memory files
	 *     wp datamachine agent daily list
	 *
	 *     # Read today's daily memory
	 *     wp datamachine agent daily read
	 *
	 *     # Read a specific date
	 *     wp datamachine agent daily read 2026-02-24
	 *
	 *     # Write to today's daily memory (replaces content)
	 *     wp datamachine agent daily write "## Session notes"
	 *
	 *     # Append to a specific date
	 *     wp datamachine agent daily append 2026-02-24 "- Additional discovery"
	 *
	 *     # Delete a daily file
	 *     wp datamachine agent daily delete 2026-02-24
	 *
	 *     # Search daily memory
	 *     wp datamachine agent daily search "homeboy"
	 *
	 *     # Search with date range
	 *     wp datamachine agent daily search "deploy" --from=2026-02-01 --to=2026-02-28
	 *
	 * @subcommand daily
	 */
	public function daily( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine agent daily <list|read|write|append|delete> [date] [content]' );
			return;
		}

		$action   = $args[0];
		$agent_id = AgentResolver::resolve( $assoc_args );
		$user_id  = ( null === $agent_id ) ? UserResolver::resolve( $assoc_args ) : 0;
		$daily    = new DailyMemory( $user_id, $agent_id ?? 0 );

		switch ( $action ) {
			case 'list':
				$this->daily_list( $daily, $assoc_args );
				break;
			case 'read':
				$date = $args[1] ?? gmdate( 'Y-m-d' );
				$this->daily_read( $daily, $date );
				break;
			case 'write':
				$this->daily_write( $daily, $args );
				break;
			case 'append':
				$this->daily_append( $daily, $args );
				break;
			case 'delete':
				$date = $args[1] ?? null;
				if ( ! $date ) {
					WP_CLI::error( 'Date is required for delete. Usage: wp datamachine agent daily delete 2026-02-24' );
					return;
				}
				$this->daily_delete( $daily, $date );
				break;
			case 'search':
				$search_query = $args[1] ?? null;
				if ( ! $search_query ) {
					WP_CLI::error( 'Search query is required. Usage: wp datamachine agent daily search "query" [--from=...] [--to=...]' );
					return;
				}
				$this->daily_search( $daily, $search_query, $assoc_args );
				break;
			default:
				WP_CLI::error( "Unknown daily action: {$action}. Use: list, read, write, append, delete, search" );
		}
	}

	/**
	 * List daily memory files.
	 */
	private function daily_list( DailyMemory $daily, array $assoc_args ): void {
		$result = $daily->list_all();
		$months = $result['months'];

		if ( empty( $months ) ) {
			WP_CLI::log( 'No daily memory files found.' );
			return;
		}

		$items = array();
		foreach ( $months as $month_key => $days ) {
			foreach ( $days as $day ) {
				list( $year, $month ) = explode( '/', $month_key );
				$items[]              = array(
					'date'  => "{$year}-{$month}-{$day}",
					'month' => $month_key,
				);
			}
		}

		// Sort descending by date.
		usort(
			$items,
			function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);

		$this->format_items( $items, array( 'date', 'month' ), $assoc_args );
		WP_CLI::log( sprintf( 'Total: %d daily memory file(s).', count( $items ) ) );
	}

	/**
	 * Append to a daily memory file.
	 */
	private function daily_append( DailyMemory $daily, array $args ): void {
		// append [date] <content> — date defaults to today.
		if ( count( $args ) < 2 ) {
			WP_CLI::error( 'Content is required. Usage: wp datamachine agent daily append [date] <content>' );
			return;
		}

		if ( count( $args ) >= 3 ) {
			$date    = $args[1];
			$content = $args[2];
		} else {
			$date    = gmdate( 'Y-m-d' );
			$content = $args[1];
		}

		$parts = DailyMemory::parse_date( $date );
		if ( ! $parts ) {
			WP_CLI::error( sprintf( 'Invalid date format: %s. Use YYYY-MM-DD.', $date ) );
			return;
		}

		$result = $daily->append( $parts['year'], $parts['month'], $parts['day'], $content );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] );
			return;
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * Delete a daily memory file.
	 */
	private function daily_delete( DailyMemory $daily, string $date ): void {
		$parts = DailyMemory::parse_date( $date );
		if ( ! $parts ) {
			WP_CLI::error( sprintf( 'Invalid date format: %s. Use YYYY-MM-DD.', $date ) );
			return;
		}

		$result = $daily->delete( $parts['year'], $parts['month'], $parts['day'] );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] );
			return;
		}

		WP_CLI::success( $result['message'] );
	}
