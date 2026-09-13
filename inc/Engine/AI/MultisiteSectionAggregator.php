<?php
/**
 * Multisite Section Aggregator
 *
 * Persists each site's rendered contribution to a root convention-path file
 * and deterministically combines those snapshots. WordPress does not load a
 * site's active plugins when switch_to_blog() changes context, so callbacks
 * cannot be rediscovered safely in one request.
 *
 * @package DataMachine\Engine\AI
 * @since   next
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

class MultisiteSectionAggregator {

	private const OPTION_NAME = 'datamachine_composable_section_snapshots';
	private const VERSION     = 1;

	/**
	 * Check whether the current site has written the current snapshot schema.
	 *
	 * @param string $filename Composable filename.
	 * @return bool Whether a current snapshot exists.
	 */
	public static function has_current_snapshot( string $filename ): bool {
		$stored   = get_option( self::OPTION_NAME, array() );
		$snapshot = is_array( $stored ) ? ( $stored[ $filename ] ?? array() ) : array();

		return self::valid_snapshot( $snapshot, (int) get_current_blog_id() );
	}

	/**
	 * Compose a convention-path file from per-site rendered snapshots.
	 *
	 * The current site replaces only its own snapshot. Snapshots from other
	 * sites are read through WordPress's per-blog option API and remain intact.
	 * A duplicate section slug is owned by the lowest blog ID, making the merge
	 * independent of the site that triggered composition.
	 *
	 * @param string $filename Composable filename.
	 * @param array  $context  Section generation context.
	 * @return string Deterministically assembled content.
	 */
	public static function compose( string $filename, array $context = array() ): string {
		$current_blog_id  = (int) get_current_blog_id();
		$current_snapshot = self::create_snapshot( $filename, $context, $current_blog_id );

		$stored              = get_option( self::OPTION_NAME, array() );
		$stored              = is_array( $stored ) ? $stored : array();
		$stored[ $filename ] = $current_snapshot;
		update_option( self::OPTION_NAME, $stored, false );

		$snapshots  = array();
		$blog_ids   = get_sites(
			array(
				'fields'   => 'ids',
				'number'   => 0,
				'archived' => 0,
				'deleted'  => 0,
				'spam'     => 0,
			)
		);
		$blog_ids[] = $current_blog_id;
		$blog_ids   = array_values( array_unique( array_filter( array_map( 'intval', (array) $blog_ids ) ) ) );
		sort( $blog_ids, SORT_NUMERIC );

		foreach ( $blog_ids as $blog_id ) {
			if ( $current_blog_id === $blog_id ) {
				$snapshot = $current_snapshot;
			} else {
				$site_snapshots = get_blog_option( $blog_id, self::OPTION_NAME, array() );
				$snapshot       = is_array( $site_snapshots ) ? ( $site_snapshots[ $filename ] ?? array() ) : array();
			}

			if ( self::valid_snapshot( $snapshot, $blog_id ) ) {
				$snapshots[] = $snapshot;
			}
		}

		// Never replace a shared root file with a partial first-run snapshot.
		// Each site seeds itself after plugins load; the final seed converges the
		// root file without discarding pre-upgrade guidance in the meantime.
		if ( count( $snapshots ) !== count( $blog_ids ) ) {
			return '';
		}

		return SectionRegistry::filter_content( self::merge_snapshots( $snapshots ), $filename, $context );
	}

	/**
	 * Create the current site's serializable section snapshot.
	 *
	 * @param string $filename Target filename.
	 * @param array  $context  Generation context.
	 * @param int    $blog_id  Current blog ID.
	 * @return array<string,mixed> Snapshot payload.
	 */
	private static function create_snapshot( string $filename, array $context, int $blog_id ): array {
		$site_url = untrailingslashit( (string) get_home_url( $blog_id, '/' ) );
		$sections = SectionRegistry::render_sections( $filename, $context );

		foreach ( $sections as &$section ) {
			$section['site_id']  = $blog_id;
			$section['site_url'] = $site_url;
			$section['content']  = self::route_wp_cli_commands( (string) $section['content'], $site_url );
		}
		unset( $section );

		return array(
			'version'  => self::VERSION,
			'site_id'  => $blog_id,
			'site_url' => $site_url,
			'sections' => $sections,
		);
	}

	/**
	 * Validate a stored site snapshot before including it.
	 *
	 * @param mixed $snapshot Stored value.
	 * @param int   $blog_id  Expected owner blog ID.
	 * @return bool Whether the snapshot follows the current contract.
	 */
	private static function valid_snapshot( mixed $snapshot, int $blog_id ): bool {
		return is_array( $snapshot )
			&& self::VERSION === ( $snapshot['version'] ?? 0 )
			&& (int) ( $snapshot['site_id'] ?? 0 ) === $blog_id
			&& is_array( $snapshot['sections'] ?? null );
	}

	/**
	 * Merge snapshots with stable ownership and ordering.
	 *
	 * @param array<int,array<string,mixed>> $snapshots Valid snapshots.
	 * @return string Assembled section content.
	 */
	private static function merge_snapshots( array $snapshots ): string {
		$sections = array();

		foreach ( $snapshots as $snapshot ) {
			foreach ( $snapshot['sections'] as $slug => $section ) {
				if ( ! is_array( $section ) || '' === trim( (string) ( $section['content'] ?? '' ) ) ) {
					continue;
				}

				$slug = sanitize_key( (string) $slug );
				if ( '' === $slug ) {
					continue;
				}

				$section['slug']    = $slug;
				$section['site_id'] = (int) $snapshot['site_id'];
				if ( ! isset( $sections[ $slug ] ) || $section['site_id'] < $sections[ $slug ]['site_id'] ) {
					$sections[ $slug ] = $section;
				}
			}
		}

		uasort(
			$sections,
			static function ( array $left, array $right ): int {
				$priority = (int) ( $left['priority'] ?? 50 ) <=> (int) ( $right['priority'] ?? 50 );
				return 0 !== $priority ? $priority : strcmp( (string) $left['slug'], (string) $right['slug'] );
			}
		);

		return implode(
			"\n\n",
			array_map(
				static fn( array $section ): string => trim( (string) $section['content'] ),
				$sections
			)
		);
	}

	/**
	 * Route WP-CLI commands to the site that rendered their section.
	 *
	 * Existing explicit --url options are preserved. The replacement is scoped
	 * to standalone `wp` command tokens and does not match names such as wp-cli.
	 *
	 * @param string $content  Rendered section content.
	 * @param string $site_url Owning site URL.
	 * @return string Routed content.
	 */
	private static function route_wp_cli_commands( string $content, string $site_url ): string {
		if ( '' === $site_url ) {
			return $content;
		}

		$routed = preg_replace_callback(
			'/(?<![A-Za-z0-9_-])wp(?<options>(?:\s+--[A-Za-z0-9-]+(?:=[^\s`]+)?)*)?(?=\s)/',
			static function ( array $matches ) use ( $site_url ): string {
				$options = (string) ( $matches['options'] ?? '' );
				if ( preg_match( '/\s--url(?:=|\s|$)/', $options ) ) {
					return $matches[0];
				}

				return 'wp --url=' . $site_url . $options;
			},
			$content
		);

		return is_string( $routed ) ? $routed : $content;
	}
}
