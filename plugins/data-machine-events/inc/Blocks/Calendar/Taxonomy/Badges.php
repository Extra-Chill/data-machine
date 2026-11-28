<?php
/**
 * Calendar Block Taxonomy Badge System
 *
 * Self-contained badge rendering for Calendar block event items. Provides extensibility
 * filters for themes/plugins (data_machine_events_badge_wrapper_classes, data_machine_events_badge_classes,
 * data_machine_events_excluded_taxonomies) while keeping badge logic within Calendar block.
 * Supports hash-based color classes for consistent styling.
 *
 * Filter: data_machine_events_excluded_taxonomies
 * @param array  $excluded Array of taxonomy slugs to exclude
 * @param string $context  Context identifier: 'badge', 'modal', or empty for all contexts
 *
 * @package DataMachineEvents\Blocks\Calendar\Taxonomy
 */

namespace DataMachineEvents\Blocks\Calendar\Taxonomy;

use DataMachineEvents\Core\Event_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Badges {

	/**
	 * @param int $post_id Event post ID
	 * @return array Structured array of taxonomy objects and terms
	 */
	public static function get_event_taxonomies( $post_id ) {
		if ( ! $post_id ) {
			return array();
		}

		$all_taxonomies = get_object_taxonomies( Event_Post_Type::POST_TYPE, 'objects' );

		if ( empty( $all_taxonomies ) ) {
			return array();
		}

		$taxonomy_data = array();

		$excluded_taxonomies = apply_filters( 'data_machine_events_excluded_taxonomies', array(), 'badge' );

		foreach ( $all_taxonomies as $taxonomy_slug => $taxonomy_object ) {
			if ( in_array( $taxonomy_slug, $excluded_taxonomies, true ) ) {
				continue;
			}

			$terms = get_the_terms( $post_id, $taxonomy_slug );

			if ( ! $terms || is_wp_error( $terms ) ) {
				continue;
			}

			$taxonomy_data[ $taxonomy_slug ] = array(
				'taxonomy' => $taxonomy_object,
				'terms'    => $terms,
			);
		}

		return $taxonomy_data;
	}

	/**
	 * @param int $post_id Event post ID
	 * @return array Filtered badge display data shared by PHP and TypeScript renderers.
	 */
	public static function get_display_data( $post_id ): array {
		$taxonomies = self::get_event_taxonomies( $post_id );

		if ( empty( $taxonomies ) ) {
			return array(
				'wrapper_classes' => '',
				'items'           => array(),
			);
		}

		$venue_terms = get_the_terms( $post_id, 'venue' );
		$venue_name  = ( $venue_terms && ! is_wp_error( $venue_terms ) ) ? $venue_terms[0]->name : '';

		$wrapper_classes = apply_filters(
			'data_machine_events_badge_wrapper_classes',
			array(
				'data-machine-taxonomy-badges',
			),
			$post_id
		);

		$items = array();

		foreach ( $taxonomies as $taxonomy_slug => $taxonomy_data ) {
			foreach ( $taxonomy_data['terms'] as $term ) {
				if ( 'promoter' === $taxonomy_slug && '' !== $venue_name && strcasecmp( trim( $term->name ), trim( $venue_name ) ) === 0 ) {
					continue;
				}

				$badge_classes = array(
					'data-machine-taxonomy-badge',
					'data-machine-taxonomy-' . $taxonomy_slug,
					'data-machine-term-' . $term->slug,
				);

				$badge_classes = apply_filters( 'data_machine_events_badge_classes', $badge_classes, $taxonomy_slug, $term, $post_id );

				$term_link = get_term_link( $term, $taxonomy_slug );

				$items[] = array(
					'classes'  => implode( ' ', $badge_classes ),
					'taxonomy' => $taxonomy_slug,
					'term'     => $term->slug,
					'label'    => wp_specialchars_decode( $term->name, ENT_QUOTES ),
					'url'      => is_wp_error( $term_link ) ? '' : esc_url_raw( $term_link, array( 'http', 'https' ) ),
				);
			}
		}

		return array(
			'wrapper_classes' => implode( ' ', $wrapper_classes ),
			'items'           => $items,
		);
	}

	/**
	 * @param int $post_id Event post ID.
	 * @return string Escaped badge markup for the initial server render.
	 */
	public static function render_taxonomy_badges( $post_id ): string {
		$badges = self::get_display_data( $post_id );
		if ( empty( $badges['items'] ) ) {
			return '';
		}

		$output = '<div class="' . esc_attr( $badges['wrapper_classes'] ) . '">';
		foreach ( $badges['items'] as $badge ) {
			$attrs   = sprintf(
				'class="%s" data-taxonomy="%s" data-term="%s"',
				esc_attr( $badge['classes'] ),
				esc_attr( $badge['taxonomy'] ),
				esc_attr( $badge['term'] )
			);
			$output .= '' === $badge['url']
				? sprintf( '<span %s>%s</span>', $attrs, esc_html( $badge['label'] ) )
				: sprintf( '<a href="%s" %s>%s</a>', esc_url( $badge['url'] ), $attrs, esc_html( $badge['label'] ) );
		}

		return $output . '</div>';
	}

	/**
	 * @return array Taxonomies with slug and label, filtered by data_machine_events_excluded_taxonomies
	 */
	public static function get_used_taxonomies() {
		global $wpdb;

		$excluded_taxonomies = apply_filters( 'data_machine_events_excluded_taxonomies', array(), 'badge' );

		$base_query = "
			SELECT DISTINCT tt.taxonomy, t.name
			FROM {$wpdb->term_taxonomy} tt
			INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
			INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
			INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
			WHERE p.post_type = %s
			AND p.post_status = 'publish'
		";

		if ( ! empty( $excluded_taxonomies ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $excluded_taxonomies ), '%s' ) );
			$base_query  .= " AND tt.taxonomy NOT IN ($placeholders)";
			$query_args   = array_merge( array( Event_Post_Type::POST_TYPE ), $excluded_taxonomies );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is built with placeholders above
			$results = $wpdb->get_results( $wpdb->prepare( $base_query, $query_args ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Simple query with single placeholder
			$results = $wpdb->get_results( $wpdb->prepare( $base_query, Event_Post_Type::POST_TYPE ) );
		}

		if ( ! $results ) {
			return array();
		}

		$used_taxonomies = array();
		foreach ( $results as $result ) {
			if ( ! isset( $used_taxonomies[ $result->taxonomy ] ) ) {
				$taxonomy_object                      = get_taxonomy( $result->taxonomy );
				$used_taxonomies[ $result->taxonomy ] = array(
					'slug'  => $result->taxonomy,
					'label' => $taxonomy_object && isset( $taxonomy_object->labels->name )
						? $taxonomy_object->labels->name
						: $result->taxonomy,
				);
			}
		}

		return $used_taxonomies;
	}

	/**
	 * @param string $taxonomy_slug
	 * @return string Hash-based color class (data-machine-badge-{color})
	 */
	public static function get_taxonomy_color_class( $taxonomy_slug ) {
		$hash        = md5( $taxonomy_slug );
		$color_index = hexdec( substr( $hash, 0, 1 ) ) % 10;

		$color_classes = array(
			'data-machine-badge-blue',
			'data-machine-badge-green',
			'data-machine-badge-purple',
			'data-machine-badge-orange',
			'data-machine-badge-red',
			'data-machine-badge-teal',
			'data-machine-badge-pink',
			'data-machine-badge-yellow',
			'data-machine-badge-indigo',
			'data-machine-badge-gray',
		);

		return $color_classes[ $color_index ];
	}
}
