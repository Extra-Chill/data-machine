//! sections — extracted from MemoryCommand.php.


	/**
	 * Read agent memory — full file or a specific section.
	 *
	 * ## OPTIONS
	 *
	 * [<section>]
	 * : Section name to read (without ##). If omitted, returns full file.
	 *
	 * [--agent=<slug>]
	 * : Agent slug or numeric ID. When provided, reads that agent's memory
	 *   instead of the current user's agent.
	 *
	 * ## EXAMPLES
	 *
	 *     # Read full memory file
	 *     wp datamachine agent read
	 *
	 *     # Read a specific section
	 *     wp datamachine agent read "Fleet"
	 *
	 *     # Read lessons learned
	 *     wp datamachine agent read "Lessons Learned"
	 *
	 *     # Read memory for a specific agent
	 *     wp datamachine agent read --agent=studio
	 *
	 *     # Read memory for a specific user
	 *     wp datamachine agent read --user=2
	 *
	 * @subcommand read
	 */
	public function read( array $args, array $assoc_args ): void {
		$section = $args[0] ?? null;
		$input   = $this->resolveMemoryScoping( $assoc_args );

		if ( null !== $section ) {
			$input['section'] = $section;
		}

		$result = AgentMemoryAbilities::getMemory( $input );

		if ( ! $result['success'] ) {
			$message = $result['message'] ?? 'Failed to read memory.';
			if ( ! empty( $result['available_sections'] ) ) {
				$message .= "\nAvailable sections: " . implode( ', ', $result['available_sections'] );
			}
			WP_CLI::error( $message );
			return;
		}

		WP_CLI::log( $result['content'] ?? '' );
	}

	/**
	 * List all sections in agent memory.
	 *
	 * ## OPTIONS
	 *
	 * [--agent=<slug>]
	 * : Agent slug or numeric ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List memory sections
	 *     wp datamachine agent sections
	 *
	 *     # List as JSON
	 *     wp datamachine agent sections --format=json
	 *
	 *     # List sections for a specific agent
	 *     wp datamachine agent sections --agent=studio
	 *
	 * @subcommand sections
	 */
	public function sections( array $args, array $assoc_args ): void {
		$scoping = $this->resolveMemoryScoping( $assoc_args );
		$result  = AgentMemoryAbilities::listSections( $scoping );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] ?? 'Failed to list sections.' );
			return;
		}

		$sections = $result['sections'] ?? array();

		if ( empty( $sections ) ) {
			WP_CLI::log( 'No sections found in memory file.' );
			return;
		}

		$items = array_map(
			function ( $section ) {
				return array( 'section' => $section );
			},
			$sections
		);

		$this->format_items( $items, array( 'section' ), $assoc_args );
	}

	/**
	 * Read a daily memory file.
	 */
	private function daily_read( DailyMemory $daily, string $date ): void {
		$parts = DailyMemory::parse_date( $date );
		if ( ! $parts ) {
			WP_CLI::error( sprintf( 'Invalid date format: %s. Use YYYY-MM-DD.', $date ) );
			return;
		}

		$result = $daily->read( $parts['year'], $parts['month'], $parts['day'] );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] );
			return;
		}

		WP_CLI::log( $result['content'] );
	}
