<?php
/**
 * Reddit Domain Mentions Ability
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Reddit
 */

namespace DataMachineSocials\Abilities\Reddit;

use DataMachine\Abilities\PermissionHelper;
use DataMachineSocials\Abilities\AbstractSocialAbility;

defined( 'ABSPATH' ) || exit;

/**
 * Build a read-only report of Reddit posts that mention a domain.
 */
class RedditDomainMentionsAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/reddit-domain-mentions',
				array(
					'label'               => __( 'Report Reddit Domain Mentions', 'data-machine-socials' ),
					'description'         => __( 'Report Reddit posts that directly link to or mention a domain in self-post text.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'domain' ),
						'properties' => array(
							'domain'          => array(
								'type'        => 'string',
								'description' => __( 'Domain or root URL to report.', 'data-machine-socials' ),
							),
							'owners'          => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'default'     => array(),
								'description' => __( 'Reddit usernames whose posts count as owned.', 'data-machine-socials' ),
							),
							'timeframe_limit' => array(
								'type'        => 'string',
								'default'     => 'all_time',
								'description' => __( 'Fetch ability timeframe filter.', 'data-machine-socials' ),
							),
							'limit'           => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'maximum'     => 500,
								'default'     => 100,
								'description' => __( 'Maximum deduplicated report rows.', 'data-machine-socials' ),
							),
							'max_pages'       => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'maximum'     => 10,
								'default'     => 5,
								'description' => __( 'Maximum Reddit pages fetched per query variant.', 'data-machine-socials' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'required'   => array( 'success', 'report' ),
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'report'  => self::reportSchema(),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};
	}

	public function checkPermission(): bool {
		return PermissionHelper::can( 'use_tools' );
	}

	private static function reportSchema(): array {
		$count_map = array(
			'type'                 => 'object',
			'additionalProperties' => array( 'type' => 'integer' ),
		);

		return array(
			'type'       => 'object',
			'required'   => array( 'domain', 'owners', 'totals', 'breakdowns', 'rows', 'truncated', 'limits', 'coverage' ),
			'properties' => array(
				'domain'     => array( 'type' => 'string' ),
				'owners'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'totals'     => array(
					'type'       => 'object',
					'required'   => array( 'total', 'owned', 'organic' ),
					'properties' => array(
						'total'   => array( 'type' => 'integer' ),
						'owned'   => array( 'type' => 'integer' ),
						'organic' => array( 'type' => 'integer' ),
					),
				),
				'breakdowns' => array(
					'type'       => 'object',
					'required'   => array( 'author', 'subreddit', 'matched_host', 'date', 'year' ),
					'properties' => array(
						'author'       => $count_map,
						'subreddit'    => $count_map,
						'matched_host' => $count_map,
						'date'         => $count_map,
						'year'         => $count_map,
					),
				),
				'rows'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'required'   => array( 'item_id', 'title', 'reddit_permalink', 'matched_target_url', 'matched_host', 'author', 'subreddit', 'timestamp', 'score', 'comment_count', 'match_type', 'ownership' ),
						'properties' => array(
							'item_id'            => array( 'type' => 'string' ),
							'title'              => array( 'type' => 'string' ),
							'reddit_permalink'   => array( 'type' => 'string' ),
							'matched_target_url' => array( 'type' => 'string' ),
							'matched_host'       => array( 'type' => 'string' ),
							'author'             => array( 'type' => 'string' ),
							'subreddit'          => array( 'type' => 'string' ),
							'timestamp'          => array( 'type' => 'string' ),
							'score'              => array( 'type' => 'integer' ),
							'comment_count'      => array( 'type' => 'integer' ),
							'match_type'         => array(
								'type' => 'string',
								'enum' => array( 'direct_link', 'self_text' ),
							),
							'ownership'          => array(
								'type' => 'string',
								'enum' => array( 'owned', 'organic' ),
							),
						),
					),
				),
				'truncated'  => array( 'type' => 'boolean' ),
				'limits'     => array(
					'type'       => 'object',
					'required'   => array( 'rows', 'pages_per_query', 'query_variants' ),
					'properties' => array(
						'rows'            => array( 'type' => 'integer' ),
						'pages_per_query' => array( 'type' => 'integer' ),
						'query_variants'  => array( 'type' => 'integer' ),
					),
				),
				'coverage'   => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Execute the report by composing the existing Reddit fetch ability.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute( array $input ): array|\WP_Error {
		$domain = self::normalizeDomain( (string) ( $input['domain'] ?? '' ) );
		if ( is_wp_error( $domain ) ) {
			return $domain;
		}

		$provider = $this->resolveProvider( 'reddit', 'Reddit' );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$access_token = $provider->get_valid_access_token();
		if ( ! is_string( $access_token ) || '' === trim( $access_token ) ) {
			return new \WP_Error( 'missing_auth', 'Reddit access token expired and refresh failed', array( 'status' => 401 ) );
		}
		$access_token = trim( $access_token );

		$owners = self::normalizeOwners( $input['owners'] ?? array() );
		if ( is_wp_error( $owners ) ) {
			return $owners;
		}

		$limit     = (int) ( $input['limit'] ?? 100 );
		$max_pages = (int) ( $input['max_pages'] ?? 5 );
		$timeframe = (string) ( $input['timeframe_limit'] ?? 'all_time' );
		if ( $limit < 1 || $limit > 500 ) {
			return new \WP_Error( 'invalid_param', 'limit must be between 1 and 500', array( 'status' => 400 ) );
		}
		if ( $max_pages < 1 || $max_pages > 10 ) {
			return new \WP_Error( 'invalid_param', 'max_pages must be between 1 and 10', array( 'status' => 400 ) );
		}
		if ( ! in_array( $timeframe, array( 'all_time', '24_hours', '72_hours', '7_days', '30_days', '90_days', '6_months', '1_year' ), true ) ) {
			return new \WP_Error( 'invalid_param', 'Unsupported timeframe_limit', array( 'status' => 400 ) );
		}

		$fetch = wp_get_ability( 'datamachine/fetch-reddit' );
		if ( ! $fetch ) {
			return new \WP_Error( 'missing_ability', 'datamachine/fetch-reddit ability not registered', array( 'status' => 500 ) );
		}

		$items     = array();
		$truncated = false;
		$queries   = array( 'url:' . $domain, $domain );
		foreach ( $queries as $query ) {
			$result = $fetch->execute(
				array(
					'query'            => $query,
					'access_token'     => $access_token,
					'sort_by'          => 'relevance',
					'timeframe_limit'  => $timeframe,
					'fetch_batch_size' => 100,
					'max_pages'        => $max_pages,
					'max_items'        => $limit,
					'download_images'  => false,
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( empty( $result['success'] ) ) {
				return new \WP_Error( 'api_error', (string) ( $result['error'] ?? 'Reddit domain search failed' ), array( 'status' => 500 ) );
			}

			$query_items = $result['items'] ?? array();
			$truncated   = $truncated || ! empty( $result['pagination']['truncated'] );
			foreach ( $query_items as $item ) {
				$row = self::itemToRow( $item, $domain, $owners );
				if ( null === $row ) {
					continue;
				}

				$id = $row['item_id'];
				if ( ! isset( $items[ $id ] ) || 'direct_link' === $row['match_type'] ) {
					$items[ $id ] = $row;
				}
			}
		}

		$rows = array_values( $items );
		usort( $rows, static fn( array $a, array $b ): int => strcmp( $b['timestamp'], $a['timestamp'] ) );
		if ( count( $rows ) > $limit ) {
			$truncated = true;
			$rows      = array_slice( $rows, 0, $limit );
		}

		return array(
			'success' => true,
			'report'  => self::buildReport( $domain, $owners, $rows, $truncated, $limit, $max_pages ),
		);
	}

	/**
	 * Normalize a bare domain or root URL and reject ambiguous URL components.
	 *
	 * @return string|\WP_Error
	 */
	private static function normalizeDomain( string $input ): string|\WP_Error {
		$input = trim( strtolower( $input ) );
		if ( '' === $input || preg_match( '/\s/', $input ) ) {
			return new \WP_Error( 'invalid_domain', 'Provide a valid domain or root URL', array( 'status' => 400 ) );
		}

		$has_scheme = str_contains( $input, '://' );
		$url        = $has_scheme ? $input : 'https://' . $input;
		$parts      = wp_parse_url( $url );
		if (
			! is_array( $parts ) ||
			empty( $parts['host'] ) ||
			isset( $parts['user'] ) ||
			isset( $parts['pass'] ) ||
			isset( $parts['port'] ) ||
			! empty( $parts['query'] ) ||
			! empty( $parts['fragment'] ) ||
			( isset( $parts['path'] ) && ! in_array( $parts['path'], array( '', '/' ), true ) ) ||
			( $has_scheme && ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) )
		) {
			return new \WP_Error( 'invalid_domain', 'Domain must not include credentials, a port, path, query, or fragment', array( 'status' => 400 ) );
		}

		$domain = rtrim( strtolower( (string) $parts['host'] ), '.' );
		if ( function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			if ( false !== $ascii ) {
				$domain = strtolower( $ascii );
			}
		}

		if (
			strlen( $domain ) > 253 ||
			! str_contains( $domain, '.' ) ||
			filter_var( $domain, FILTER_VALIDATE_IP ) ||
			! preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $domain )
		) {
			return new \WP_Error( 'invalid_domain', 'Provide a valid public domain name', array( 'status' => 400 ) );
		}

		return $domain;
	}

	/**
	 * @return array|\WP_Error
	 */
	private static function normalizeOwners( mixed $input ): array|\WP_Error {
		$values = is_array( $input ) ? $input : array( $input );
		$owners = array();
		foreach ( $values as $value ) {
			foreach ( explode( ',', (string) $value ) as $owner ) {
				$owner = strtolower( preg_replace( '#^(?:u/|/u/)#i', '', trim( $owner ) ) );
				if ( '' === $owner ) {
					continue;
				}
				if ( ! preg_match( '/^[a-z0-9_-]{3,20}$/', $owner ) ) {
					return new \WP_Error( 'invalid_owner', 'Owner usernames must be valid Reddit usernames', array( 'status' => 400 ) );
				}
				$owners[] = $owner;
			}
		}

		return array_values( array_unique( $owners ) );
	}

	private static function itemToRow( array $item, string $domain, array $owners ): ?array {
		$data        = $item['data'] ?? array();
		$metadata    = $data['metadata'] ?? array();
		$item_id     = (string) ( $item['item_id'] ?? $metadata['original_id'] ?? '' );
		$target_url  = html_entity_decode( (string) ( $metadata['target_url'] ?? '' ) );
		$target_host = self::matchingHost( $target_url, $domain );

		$match_type   = 'direct_link';
		$matched_url  = $target_host ? $target_url : '';
		$matched_host = $target_host;
		if ( ! $target_host ) {
			if ( empty( $metadata['is_self_post'] ) ) {
				return null;
			}
			$match_type = 'self_text';
			$match      = self::findTextMatch( (string) ( $data['content'] ?? '' ), $domain );
			if ( null === $match ) {
				return null;
			}
			$matched_url  = $match['url'];
			$matched_host = $match['host'];
		}

		if ( '' === $item_id ) {
			return null;
		}

		$timestamp = (string) ( $metadata['original_date_gmt'] ?? '' );
		$author    = (string) ( $metadata['author'] ?? '[deleted]' );

		return array(
			'item_id'            => $item_id,
			'title'              => (string) ( $data['title'] ?? '' ),
			'reddit_permalink'   => (string) ( $item['source_url'] ?? '' ),
			'matched_target_url' => $matched_url,
			'matched_host'       => $matched_host,
			'author'             => $author,
			'subreddit'          => (string) ( $metadata['subreddit'] ?? '' ),
			'timestamp'          => $timestamp,
			'score'              => (int) ( $metadata['upvotes'] ?? 0 ),
			'comment_count'      => (int) ( $metadata['comment_count'] ?? 0 ),
			'match_type'         => $match_type,
			'ownership'          => in_array( strtolower( $author ), $owners, true ) ? 'owned' : 'organic',
		);
	}

	private static function matchingHost( string $url, string $domain ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return self::hostMatches( $host, $domain ) ? $host : '';
	}

	private static function hostMatches( string $host, string $domain ): bool {
		return $host === $domain || str_ends_with( $host, '.' . $domain );
	}

	/**
	 * @return array{url:string,host:string}|null
	 */
	private static function findTextMatch( string $text, string $domain ): ?array {
		$text = html_entity_decode( $text );
		if ( preg_match_all( '#https?://[^\s<>\]\[()"\']+#i', $text, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$url  = rtrim( $url, '.,;:!?' );
				$host = self::matchingHost( $url, $domain );
				if ( $host ) {
					return array(
						'url'  => $url,
						'host' => $host,
					);
				}
			}
		}

		$quoted_domain = preg_quote( $domain, '/' );
		if ( preg_match( '/(?<![a-z0-9.-])((?:[a-z0-9-]+\.)*' . $quoted_domain . ')(?![a-z0-9.-])/i', $text, $match ) ) {
			return array(
				'url'  => '',
				'host' => strtolower( $match[1] ),
			);
		}

		return null;
	}

	private static function buildReport( string $domain, array $owners, array $rows, bool $truncated, int $limit, int $max_pages ): array {
		$owned = count( array_filter( $rows, static fn( array $row ): bool => 'owned' === $row['ownership'] ) );

		return array(
			'domain'     => $domain,
			'owners'     => $owners,
			'totals'     => array(
				'total'   => count( $rows ),
				'owned'   => $owned,
				'organic' => count( $rows ) - $owned,
			),
			'breakdowns' => array(
				'author'       => self::breakdown( $rows, 'author' ),
				'subreddit'    => self::breakdown( $rows, 'subreddit' ),
				'matched_host' => self::breakdown( $rows, 'matched_host' ),
				'date'         => self::dateBreakdown( $rows, 'Y-m-d' ),
				'year'         => self::dateBreakdown( $rows, 'Y' ),
			),
			'rows'       => $rows,
			'truncated'  => $truncated,
			'limits'     => array(
				'rows'            => $limit,
				'pages_per_query' => $max_pages,
				'query_variants'  => 2,
			),
			'coverage'   => 'Reddit post search results only; comments are not searched.',
		);
	}

	private static function breakdown( array $rows, string $key ): array {
		$counts = array_count_values( array_map( static fn( array $row ): string => (string) $row[ $key ], $rows ) );
		arsort( $counts );
		return $counts;
	}

	private static function dateBreakdown( array $rows, string $format ): array {
		$values = array();
		foreach ( $rows as $row ) {
			$timestamp = strtotime( $row['timestamp'] );
			if ( false !== $timestamp ) {
				$values[] = gmdate( $format, $timestamp );
			}
		}
		$counts = array_count_values( $values );
		krsort( $counts );
		return $counts;
	}
}
