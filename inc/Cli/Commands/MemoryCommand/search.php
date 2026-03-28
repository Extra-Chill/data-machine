//! search — extracted from MemoryCommand.php.


	/**
	 * Search agent memory content.
	 *
	 * ## OPTIONS
	 *
	 * <query>
	 * : Search term (case-insensitive).
	 *
	 * [--agent=<slug>]
	 * : Agent slug or numeric ID.
	 *
	 * [--section=<section>]
	 * : Limit search to a specific section.
	 *
	 * ## EXAMPLES
	 *
	 *     # Search all memory
	 *     wp datamachine agent search "homeboy"
	 *
	 *     # Search within a section
	 *     wp datamachine agent search "docker" --section="Lessons Learned"
	 *
	 *     # Search a specific agent's memory
	 *     wp datamachine agent search "socials" --agent=studio
	 *
	 * @subcommand search
	 */
	public function search( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Search query is required.' );
			return;
		}

		$query   = $args[0];
		$section = $assoc_args['section'] ?? null;
		$scoping = $this->resolveMemoryScoping( $assoc_args );

		$result = AgentMemoryAbilities::searchMemory(
			array_merge(
				$scoping,
				array(
					'query'   => $query,
					'section' => $section,
				)
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] ?? 'Search failed.' );
			return;
		}

		if ( empty( $result['matches'] ) ) {
			WP_CLI::log( sprintf( 'No matches for "%s" in agent memory.', $query ) );
			return;
		}

		foreach ( $result['matches'] as $match ) {
			WP_CLI::log( sprintf( '--- [%s] line %d ---', $match['section'], $match['line'] ) );
			WP_CLI::log( $match['context'] );
			WP_CLI::log( '' );
		}

		WP_CLI::success( sprintf( '%d match(es) found.', $result['match_count'] ) );
	}

	/**
	 * Search daily memory files.
	 */
	private function daily_search( DailyMemory $daily, string $query, array $assoc_args ): void {
		$from = $assoc_args['from'] ?? null;
		$to   = $assoc_args['to'] ?? null;

		$result = $daily->search( $query, $from, $to );

		if ( empty( $result['matches'] ) ) {
			WP_CLI::log( sprintf( 'No matches for "%s" in daily memory.', $query ) );
			return;
		}

		foreach ( $result['matches'] as $match ) {
			WP_CLI::log( sprintf( '--- [%s] line %d ---', $match['date'], $match['line'] ) );
			WP_CLI::log( $match['context'] );
			WP_CLI::log( '' );
		}

		WP_CLI::success( sprintf( '%d match(es) found.', $result['match_count'] ) );
	}
