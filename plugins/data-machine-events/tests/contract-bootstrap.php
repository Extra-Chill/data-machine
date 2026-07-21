<?php
/**
 * Bootstrap and faithful WordPress doubles for calendar contract tests.
 *
 * @package DataMachineEvents\Tests
 */

// phpcs:disable -- Faithful test doubles intentionally define WordPress functions and classes across their owning namespaces.

namespace {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	define( 'DATA_MACHINE_EVENTS_PATH', dirname( __DIR__ ) . '/' );

	final class DME_Contract_Term {
		public function __construct(
			public int $term_id,
			public string $name,
			public string $slug
		) {}
	}

	function get_permalink( int $post_id ): string {
		return 'https://producer.invalid/events/' . $post_id;
	}

	function get_the_terms( int $post_id, string $taxonomy ): array|false {
		if ( 507001 === $post_id && 'venue' === $taxonomy ) {
			return array( new DME_Contract_Term( 507101, 'Seeded Hall', 'seeded-hall' ) );
		}

		return false;
	}

	function is_wp_error(): bool {
		return false;
	}

	function get_term_link( DME_Contract_Term $term, string $taxonomy ): string {
		return 'https://producer.invalid/' . $taxonomy . '/' . $term->slug;
	}

	function apply_filters( string $hook, mixed $value ): mixed {
		return $value;
	}

	require dirname( __DIR__ ) . '/vendor/autoload.php';
}

namespace DataMachineEvents\Core {
	final class Venue_Taxonomy {
		public static function get_venue_data( int $term_id ): array {
			return array(
				'term_id'     => $term_id,
				'name'        => 'Seeded Hall',
				'address'     => '100 Fixture Way',
				'city'        => 'Seed City',
				'state'       => 'SC',
				'zip'         => '29000',
				'country'     => 'US',
				'coordinates' => '32.000000,-80.000000',
				'timezone'    => 'America/New_York',
				'website'     => 'https://venue.invalid',
			);
		}
	}
}

namespace DataMachineEvents\Blocks\Calendar\Taxonomy {
	final class Badges {
		public static function get_event_taxonomies( int $post_id ): array {
			if ( 507001 !== $post_id ) {
				return array();
			}

			return array(
				'artist'   => array( 'terms' => array( new \DME_Contract_Term( 507102, 'The Seeded Performers', 'the-seeded-performers' ) ) ),
				'location' => array( 'terms' => array( new \DME_Contract_Term( 507103, 'Seed City', 'seed-city' ) ) ),
				'promoter' => array( 'terms' => array( new \DME_Contract_Term( 507104, 'Seeded Productions', 'seeded-productions' ) ) ),
			);
		}

		public static function render_taxonomy_badges( int $post_id ): string {
			return 507001 === $post_id ? '<div>presentation-only</div>' : '';
		}
	}
}

namespace DataMachineEvents\Blocks\Calendar\Display {
	final class DisplayVars {
		public static function build( array $event_data, array $display_context ): array {
			return array(
				'formatted_time_display' => '7:30 PM',
				'multi_day_label'        => 'through Jul 22',
				'iso_start_date'         => '2099-07-21T19:30:00-04:00',
				'venue_name'             => $event_data['venue'],
				'performer_name'         => $event_data['performer'],
				'show_performer'         => false,
				'show_ticket_link'       => true,
				'is_continuation'        => $display_context['is_continuation'],
				'is_multi_day'           => $display_context['is_multi_day'],
			);
		}
	}
}
