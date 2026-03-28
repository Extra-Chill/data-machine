//! write — extracted from MemoryCommand.php.


	/**
	 * Write to a section of agent memory.
	 *
	 * ## OPTIONS
	 *
	 * <section>
	 * : Section name (without ##). Created if it does not exist.
	 *
	 * <content>
	 * : Content to write. Use quotes for multi-word content.
	 *
	 * [--agent=<slug>]
	 * : Agent slug or numeric ID.
	 *
	 * [--mode=<mode>]
	 * : Write mode.
	 * ---
	 * default: set
	 * options:
	 *   - set
	 *   - append
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Replace a section
	 *     wp datamachine agent write "State" "- Data Machine v0.30.0 installed"
	 *
	 *     # Append to a section
	 *     wp datamachine agent write "Lessons Learned" "- Always check file permissions" --mode=append
	 *
	 *     # Create a new section
	 *     wp datamachine agent write "New Section" "Initial content"
	 *
	 *     # Write to a specific agent's memory
	 *     wp datamachine agent write "State" "- Studio agent active" --agent=studio
	 *
	 * @subcommand write
	 */
	public function write( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || empty( $args[1] ) ) {
			WP_CLI::error( 'Both section name and content are required.' );
			return;
		}

		$section = $args[0];
		$content = $args[1];
		$mode    = $assoc_args['mode'] ?? 'set';

		if ( ! in_array( $mode, $this->valid_modes, true ) ) {
			WP_CLI::error( sprintf( 'Invalid mode "%s". Must be one of: %s', $mode, implode( ', ', $this->valid_modes ) ) );
			return;
		}

		$scoping = $this->resolveMemoryScoping( $assoc_args );

		$result = AgentMemoryAbilities::updateMemory(
			array_merge(
				$scoping,
				array(
					'section' => $section,
					'content' => $content,
					'mode'    => $mode,
				)
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] ?? 'Failed to write memory.' );
			return;
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * Write (replace) a daily memory file.
	 */
	private function daily_write( DailyMemory $daily, array $args ): void {
		// write [date] <content> — date defaults to today.
		if ( count( $args ) < 2 ) {
			WP_CLI::error( 'Content is required. Usage: wp datamachine agent daily write [date] <content>' );
			return;
		}

		// If 3 args: write date content. If 2 args: write content (today).
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

		$result = $daily->write( $parts['year'], $parts['month'], $parts['day'], $content );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] );
			return;
		}

		WP_CLI::success( $result['message'] );
	}
