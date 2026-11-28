<?php
/**
 * Site-owned persistence for bounded Reddit comment-domain observations.
 *
 * @package DataMachineSocials
 * @subpackage Tracking
 */

namespace DataMachineSocials\Tracking;

defined( 'ABSPATH' ) || exit;

class RedditCommentDomainStore {

	public const STATE_OPTION = 'datamachine_socials_reddit_comment_monitor_state';
	public const CRON_HOOK    = 'datamachine_socials_reddit_comment_retention';
	private const LOCK_OPTION = 'datamachine_socials_reddit_comment_monitor_lock';
	private const DB_VERSION  = '1';
	private const MAX_SCOPES  = 25;

	/** Register the site-local table and daily retention task. */
	public static function register(): void {
		add_action( 'init', array( self::class, 'install' ) );
		add_action( 'init', array( self::class, 'scheduleRetention' ) );
		add_action( self::CRON_HOOK, array( self::class, 'cleanup' ) );
	}

	/** Create or update the current site's narrow observation table. */
	public static function install(): void {
		if ( self::DB_VERSION === get_option( 'datamachine_socials_reddit_comment_db_version' ) ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::tableName();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				mention_key char(64) NOT NULL,
				comment_id varchar(32) NOT NULL,
				comment_fullname varchar(35) NOT NULL,
				parent_post_id varchar(32) NOT NULL DEFAULT '',
				parent_post_title text NOT NULL,
				subreddit varchar(21) NOT NULL,
				author varchar(64) NOT NULL,
				comment_created_utc bigint(20) unsigned NOT NULL DEFAULT 0,
				score bigint(20) NOT NULL DEFAULT 0,
				permalink text NOT NULL,
				domain varchar(253) NOT NULL,
				matched_url text NOT NULL,
				matched_host varchar(253) NOT NULL,
				known_owner tinyint(1) NOT NULL DEFAULT 0,
				first_seen bigint(20) unsigned NOT NULL,
				last_seen bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (mention_key),
				KEY domain (domain),
				KEY subreddit (subreddit),
				KEY comment_created (comment_created_utc),
				KEY last_seen (last_seen)
			) {$charset};"
		);
		update_option( 'datamachine_socials_reddit_comment_db_version', self::DB_VERSION, false );
	}

	/** Schedule site-local daily cleanup when no event exists. */
	public static function scheduleRetention(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/** Remove this site's scheduled task on deactivation. Retained data is preserved. */
	public static function deactivate( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			$site_ids = get_sites( array(
				'fields' => 'ids',
				'number' => 0,
			) );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				wp_clear_scheduled_hook( self::CRON_HOOK );
				restore_current_blog();
			}
			return;
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/** Acquire an atomic site-local poll lock and return its owner token. */
	public static function acquireLock(): string|\WP_Error {
		$token = wp_generate_uuid4();
		$value = array(
			'token'      => $token,
			'expires_at' => time() + ( 30 * MINUTE_IN_SECONDS ),
		);
		if ( add_option( self::LOCK_OPTION, $value, '', false ) ) {
			return $token;
		}

		$current = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $current ) && (int) ( $current['expires_at'] ?? 0 ) < time() && self::replaceLock( $current, $value ) ) {
			return $token;
		}

		return new \WP_Error( 'reddit_comment_monitor_locked', 'A Reddit comment-domain poll is already running on this site.', array( 'status' => 409 ) );
	}

	/** Extend a lock only while the caller remains its owner. */
	public static function refreshLock( string $token ): bool {
		$current = get_option( self::LOCK_OPTION, array() );
		if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['token'] ?? '' ), $token ) ) {
			return false;
		}
		$replacement = array(
			'token'      => $token,
			'expires_at' => time() + ( 30 * MINUTE_IN_SECONDS ),
		);
		if ( $current === $replacement ) {
			return true;
		}
		return self::replaceLock( $current, $replacement );
	}

	/** Release a lock only while the caller remains its owner. */
	public static function releaseLock( string $token ): void {
		global $wpdb;
		$current = get_option( self::LOCK_OPTION, array() );
		if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['token'] ?? '' ), $token ) ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::LOCK_OPTION,
				maybe_serialize( $current )
			)
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );
	}

	/** Read checkpoint state for one subreddit on the current site. */
	public static function getScope( string $subreddit ): array {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) && is_array( $state[ strtolower( $subreddit ) ] ?? null ) ? $state[ strtolower( $subreddit ) ] : array();
	}

	/** Persist compact checkpoint state with a hard cross-invocation scope cap. */
	public static function putScope( string $subreddit, array $scope ): bool {
		$state                             = get_option( self::STATE_OPTION, array() );
		$state                             = is_array( $state ) ? $state : array();
		$state[ strtolower( $subreddit ) ] = $scope;
		uasort( $state, static fn( array $left, array $right ): int => (int) ( $right['last_poll_at'] ?? 0 ) <=> (int) ( $left['last_poll_at'] ?? 0 ) );
		$state = array_slice( $state, 0, self::MAX_SCOPES, true );
		return update_option( self::STATE_OPTION, $state, false );
	}

	/** Return all scope states, optionally restricted to requested scopes. */
	public static function getScopes( array $subreddits = array() ): array {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		return empty( $subreddits ) ? $state : array_intersect_key( $state, array_flip( array_map( 'strtolower', $subreddits ) ) );
	}

	/** Upsert normalized observations using one row per comment and canonical URL. */
	public static function upsert( array $records ): array|\WP_Error {
		global $wpdb;
		self::install();
		$inserted = 0;
		$updated  = 0;
		$table    = self::tableName();

		foreach ( $records as $record ) {
			$key    = hash( 'sha256', (string) $record['comment_fullname'] . '|' . (string) $record['matched_url'] . '|' . (string) $record['matched_host'] );
			$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE mention_key = %s', $table, $key ) );
			$sql    = $wpdb->prepare(
				'INSERT INTO %i (mention_key, comment_id, comment_fullname, parent_post_id, parent_post_title, subreddit, author, comment_created_utc, score, permalink, domain, matched_url, matched_host, known_owner, first_seen, last_seen)
				VALUES (%s, %s, %s, %s, %s, %s, %s, %d, %d, %s, %s, %s, %s, %d, %d, %d)
				ON DUPLICATE KEY UPDATE parent_post_id = VALUES(parent_post_id), parent_post_title = VALUES(parent_post_title), subreddit = VALUES(subreddit), author = VALUES(author), comment_created_utc = VALUES(comment_created_utc), score = VALUES(score), permalink = VALUES(permalink), known_owner = VALUES(known_owner), last_seen = VALUES(last_seen)',
				$table,
				$key,
				(string) $record['comment_id'],
				(string) $record['comment_fullname'],
				(string) $record['parent_post_id'],
				(string) $record['parent_post_title'],
				(string) $record['subreddit'],
				(string) $record['author'],
				(int) $record['comment_created_utc'],
				(int) $record['score'],
				(string) $record['permalink'],
				(string) $record['domain'],
				(string) $record['matched_url'],
				(string) $record['matched_host'],
				(int) $record['known_owner'],
				(int) $record['first_seen'],
				(int) $record['last_seen']
			);
			$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fully prepared above, including the table identifier.
			if ( false === $result ) {
				return new \WP_Error( 'reddit_comment_store_failed', 'Could not persist Reddit comment-domain observations.' );
			}
			$exists ? ++$updated : ++$inserted;
		}

		self::enforceBounds();
		return array(
			'inserted' => $inserted,
			'updated'  => $updated,
		);
	}

	/** Query current-site observations with bounded filters. */
	public static function report( array $filters ): array {
		global $wpdb;
		self::install();
		$table  = self::tableName();
		$where  = array( '1=1' );
		$values = array();
		$from   = self::timestamp( (string) ( $filters['date_from'] ?? '' ), false );
		$to     = self::timestamp( (string) ( $filters['date_to'] ?? '' ), true );

		foreach ( array( 'domain', 'subreddit' ) as $field ) {
			if ( '' !== (string) ( $filters[ $field ] ?? '' ) ) {
				$where[]  = "LOWER({$field}) = LOWER(%s)";
				$values[] = (string) $filters[ $field ];
			}
		}
		if ( $from ) {
			$where[]  = 'comment_created_utc >= %d';
			$values[] = $from;
		}
		if ( $to ) {
			$where[]  = 'comment_created_utc <= %d';
			$values[] = $to;
		}
		$where[]   = 'score >= %d';
		$values[]  = (int) ( $filters['min_score'] ?? PHP_INT_MIN );
		$owners    = array_map( 'strtolower', is_array( $filters['known_owners'] ?? null ) ? $filters['known_owners'] : array() );
		$ownership = (string) ( $filters['ownership'] ?? 'all' );
		if ( 'all' !== $ownership ) {
			if ( empty( $owners ) ) {
				$where[] = 'known_owner = ' . ( 'known_owner' === $ownership ? '1' : '0' );
			} else {
				$placeholders = implode( ', ', array_fill( 0, count( $owners ), '%s' ) );
				$where[]      = 'LOWER(author) ' . ( 'organic' === $ownership ? 'NOT ' : '' ) . "IN ({$placeholders})";
				$values       = array_merge( $values, $owners );
			}
		}
		$limit    = max( 1, min( 500, (int) ( $filters['limit'] ?? 100 ) ) );
		$values[] = $limit;
		$sql      = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY comment_created_utc DESC LIMIT %d';
		$values   = array_merge( array( $table ), $values );
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic clauses are code-owned and every value plus identifier uses a placeholder.

		$records = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$is_owner = empty( $owners ) ? (bool) $row['known_owner'] : in_array( strtolower( (string) $row['author'] ), $owners, true );
			unset( $row['mention_key'] );
			foreach ( array( 'comment_created_utc', 'score', 'first_seen', 'last_seen' ) as $integer ) {
				$row[ $integer ] = (int) $row[ $integer ];
			}
			$row['known_owner'] = $is_owner;
			$records[]          = $row;
		}
		return $records;
	}

	/** Delete expired rows and enforce the current site's hard record cap. */
	public static function cleanup(): array {
		global $wpdb;
		self::install();
		$table  = self::tableName();
		$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		self::enforceBounds();
		$retained = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		$result   = array(
			'deleted'        => $before - $retained,
			'retained'       => $retained,
			'retention_days' => self::retentionDays(),
			'max_records'    => self::maxRecords(),
		);
		do_action( 'datamachine_log', 'info', 'Cleaned Reddit comment-domain observations', $result );
		return $result;
	}

	private static function enforceBounds(): void {
		global $wpdb;
		$table  = self::tableName();
		$cutoff = time() - ( self::retentionDays() * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen < %d', $table, $cutoff ) );
		$max = self::maxRecords();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE mention_key NOT IN (SELECT mention_key FROM (SELECT mention_key FROM %i ORDER BY last_seen DESC, mention_key ASC LIMIT %d) retained)', $table, $table, $max ) );
	}

	private static function replaceLock( array $current, array $replacement ): bool {
		global $wpdb;
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s, autoload = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $replacement ),
				'no',
				self::LOCK_OPTION,
				maybe_serialize( $current )
			)
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		return 1 === $updated;
	}

	private static function tableName(): string {
		global $wpdb;
		return $wpdb->prefix . 'datamachine_socials_reddit_comment_mentions';
	}

	private static function retentionDays(): int {
		return max( 1, min( 365, (int) apply_filters( 'datamachine_socials_reddit_comment_retention_days', 90 ) ) );
	}

	private static function maxRecords(): int {
		return max( 100, min( 10000, (int) apply_filters( 'datamachine_socials_reddit_comment_max_records', 5000 ) ) );
	}

	private static function timestamp( string $date, bool $end_of_day ): int {
		if ( '' === trim( $date ) ) {
			return 0;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date .= $end_of_day ? ' 23:59:59 UTC' : ' 00:00:00 UTC';
		}
		$timestamp = strtotime( $date );
		return false === $timestamp ? 0 : $timestamp;
	}
}
