<?php
/**
 * Repair Event Details blocks whose comment JSON was corrupted on save.
 *
 * Content saved through wp_insert_post() without wp_slash() lost every
 * backslash, so an escaped quote in an attribute (a performer named
 * `Christone "Kingfish" Ingram`) became a bare quote. The block comment JSON
 * then fails to parse and parse_blocks() returns null attrs for the whole
 * block (#870; root cause fixed in data-machine upsert-post).
 *
 * The damage is limited to lost backslashes, so attributes are recoverable:
 * split the comment on its `"key":` boundaries, take each string value
 * verbatim, and re-encode. A repair is kept only if the rebuilt block parses
 * back to exactly the recovered attributes.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\Event_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlockAttributeJsonRepairAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbility();
			self::$registered = true;
		}
	}

	private function registerAbility(): void {
		$register_callback = function () {
				wp_register_ability(
					'data-machine-events/repair-event-details-json',
					array(
						'label'               => __( 'Repair Event Details Block JSON', 'data-machine-events' ),
						'description'         => __( 'Recover Event Details block attributes whose comment JSON lost its escaping (dry run by default)', 'data-machine-events' ),
						'category'            => AbilityCategories::EVENTS,
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(
								'dry_run' => array(
									'type'    => 'boolean',
									'default' => true,
								),
								'limit'   => array(
									'type'    => 'integer',
									'default' => -1,
								),
							),
						),
						'output_schema'       => array(
							'type'       => 'object',
							'properties' => array(
								'dry_run'      => array( 'type' => 'boolean' ),
								'scanned'      => array( 'type' => 'integer' ),
								'repaired'     => array( 'type' => 'integer' ),
								'unrepairable' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'integer' ),
								),
								'changes'      => array(
									'type'  => 'array',
									'items' => array( 'type' => 'object' ),
								),
								'message'      => array( 'type' => 'string' ),
							),
						),
						'execute_callback'    => array( $this, 'executeRepair' ),
						'permission_callback' => AbilityPermissions::canWrite(),
						'meta'                => array( 'show_in_rest' => true ),
					)
				);
		};

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/**
	 * Recover attributes from corrupted block comment JSON.
	 *
	 * @param string $json Comment JSON (`{...}`).
	 * @return array|null Attributes, or null when not recoverable.
	 */
	public static function recoverAttributes( string $json ): ?array {
		$json = trim( $json );
		if ( '' === $json || '{' !== $json[0] || '}' !== substr( $json, -1 ) ) {
			return null;
		}
		$inner = substr( $json, 1, -1 );
		if ( ! preg_match_all( '/(?:^|,)"([A-Za-z_][A-Za-z0-9_]*)":/', $inner, $matches, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$attrs = array();
		$count = count( $matches[0] );
		for ( $i = 0; $i < $count; $i++ ) {
			$key   = $matches[1][ $i ][0];
			$start = $matches[0][ $i ][1] + strlen( $matches[0][ $i ][0] );
			$end   = $i + 1 < $count ? $matches[0][ $i + 1 ][1] : strlen( $inner );
			$raw   = substr( $inner, $start, $end - $start );
			if ( strlen( $raw ) >= 2 && '"' === $raw[0] && '"' === substr( $raw, -1 ) ) {
				$attrs[ $key ] = substr( $raw, 1, -1 );
				continue;
			}
			$value = json_decode( $raw, true );
			if ( null === $value && 'null' !== trim( $raw ) ) {
				return null;
			}
			$attrs[ $key ] = $value;
		}
		return $attrs;
	}

	/**
	 * Rebuild post content with repaired Event Details attributes.
	 *
	 * @param string $content Post content.
	 * @return array{content:string,attrs:array}|null Null when nothing to repair or not recoverable.
	 */
	public static function repairContent( string $content ): ?array {
		$name = preg_quote( Event_Post_Type::EVENT_DETAILS_BLOCK_NAME, '#' );
		if ( ! preg_match( '#<!-- wp:' . $name . ' (\{.*?\}) (/)?-->#s', $content, $match ) ) {
			return null;
		}
		if ( is_array( json_decode( $match[1], true ) ) ) {
			return null;
		}
		$attrs = self::recoverAttributes( $match[1] );
		if ( null === $attrs || empty( $attrs['startDate'] ) ) {
			return null;
		}
		$comment = '<!-- wp:' . Event_Post_Type::EVENT_DETAILS_BLOCK_NAME . ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE ) . ' ' . ( $match[2] ?? '' ) . '-->';
		$rebuilt = str_replace( $match[0], $comment, $content );
		foreach ( parse_blocks( $rebuilt ) as $block ) {
			if ( Event_Post_Type::EVENT_DETAILS_BLOCK_NAME === $block['blockName'] ) {
				// @phpstan-ignore function.alreadyNarrowedType (WP stubs type attrs as array; invalid comment JSON parses to null in practice, #870.)
				return is_array( $block['attrs'] ) && $block['attrs'] == $attrs // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- key order may differ; values compared.
					? array(
						'content' => $rebuilt,
						'attrs'   => $attrs,
					)
					: null;
			}
		}
		return null;
	}

	/**
	 * Whether the Event Details block's comment JSON fails to decode.
	 *
	 * Checked on the raw comment, not parse_blocks() attrs: WordPress types
	 * attrs as always-array, which is exactly what fails here (#870).
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	public static function hasCorruptedDetails( string $content ): bool {
		$name = preg_quote( Event_Post_Type::EVENT_DETAILS_BLOCK_NAME, '#' );
		if ( ! preg_match( '#<!-- wp:' . $name . ' (\{.*?\}) /?-->#s', $content, $match ) ) {
			return false;
		}
		return ! is_array( json_decode( $match[1], true ) );
	}

	/**
	 * Scan published events and repair corrupted Event Details JSON.
	 *
	 * @param array $input dry_run, limit.
	 * @return array
	 */
	public function executeRepair( array $input ): array {
		global $wpdb;
		$dry_run      = (bool) ( $input['dry_run'] ?? true );
		$limit        = (int) ( $input['limit'] ?? -1 );
		$scanned      = 0;
		$repaired     = 0;
		$changes      = array();
		$unrepairable = array();
		$ids          = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded one-off repair scan.
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID",
				Event_Post_Type::POST_TYPE,
				'%' . $wpdb->esc_like( '<!-- wp:' . Event_Post_Type::EVENT_DETAILS_BLOCK_NAME . ' {' ) . '%'
			)
		);
		foreach ( $ids as $id ) {
			if ( $limit > 0 && $repaired >= $limit ) {
				break;
			}
			++$scanned;
			$post = get_post( (int) $id );
			if ( ! $post ) {
				continue;
			}
			if ( ! self::hasCorruptedDetails( (string) $post->post_content ) ) {
				continue;
			}
			$repair = self::repairContent( $post->post_content );
			if ( null === $repair ) {
				$unrepairable[] = (int) $post->ID;
				continue;
			}
			if ( ! $dry_run ) {
				$result = wp_update_post(
					wp_slash(
						array(
							'ID'           => (int) $post->ID,
							'post_content' => $repair['content'],
						)
					),
					true
				);
				if ( is_wp_error( $result ) ) {
					$unrepairable[] = (int) $post->ID;
					continue;
				}
			}
			++$repaired;
			$changes[] = array(
				'post_id'   => (int) $post->ID,
				'title'     => $post->post_title,
				'performer' => (string) ( $repair['attrs']['performer'] ?? '' ),
			);
		}
		return array(
			'dry_run'      => $dry_run,
			'scanned'      => $scanned,
			'repaired'     => count( $changes ),
			'unrepairable' => $unrepairable,
			'changes'      => $changes,
			'message'      => sprintf( '%s %d event(s); %d unrepairable.', $dry_run ? 'Would repair' : 'Repaired', count( $changes ), count( $unrepairable ) ),
		);
	}
}
