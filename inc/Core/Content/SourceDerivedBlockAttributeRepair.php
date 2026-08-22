<?php
/**
 * Conservative repair for the known Blocks Engine RichText corruption.
 *
 * @package DataMachine\Core\Content
 */

namespace DataMachine\Core\Content;

use WP_Block_Type_Registry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class SourceDerivedBlockAttributeRepair {

	private const TARGET_BLOCKS = array(
		'core/heading',
		'core/paragraph',
		'core/list-item',
	);

	/**
	 * Inspect serialized blocks and prepare a verified, narrowly scoped repair.
	 *
	 * @param string $content Serialized post content.
	 * @return array Repair result.
	 */
	public function inspect( string $content ): array {
		$blocks   = $this->parse( $content );
		$findings = array();

		$this->inspectBlocks( $blocks, $findings );

		$repairable = count( array_filter( $findings, static fn ( array $finding ): bool => 'repairable' === $finding['status'] ) );
		$result     = array(
			'content'         => $content,
			'findings'        => $findings,
			'repairable_count' => $repairable,
			'skipped_count'    => count( $findings ) - $repairable,
			'integrity_valid'  => true,
		);

		if ( 0 === $repairable ) {
			return $result;
		}

		$repaired = $this->serialize( $blocks );
		if ( $this->parse( $repaired ) !== $blocks ) {
			foreach ( $result['findings'] as &$finding ) {
				if ( 'repairable' === $finding['status'] ) {
					$finding['status'] = 'skipped';
					$finding['reason'] = 'integrity_verification_failed';
				}
			}
			unset( $finding );

			$result['repairable_count'] = 0;
			$result['skipped_count']    = count( $result['findings'] );
			$result['integrity_valid']  = false;
			$result['error_code']       = 'datamachine_blocks_repair_integrity_failed';
			$result['error']            = 'Repaired content did not round-trip to the exact expected block tree.';
			return $result;
		}

		$result['content'] = $repaired;
		return $result;
	}

	/**
	 * Process one post, defaulting to an inventory-only dry run.
	 *
	 * The updater receives the post ID, repaired content, and originally inspected
	 * content. The default implementation locks the current site's exact posts row,
	 * compares post_content, and updates while holding that lock.
	 *
	 * @param int           $post_id Post ID.
	 * @param string        $content Serialized post content.
	 * @param bool          $apply Whether to persist the repair.
	 * @param callable|null $updater Optional updater for deterministic tests.
	 * @return array Repair result.
	 */
	public function processPost( int $post_id, string $content, bool $apply = false, ?callable $updater = null ): array {
		$result            = $this->inspect( $content );
		$result['post_id'] = $post_id;
		$result['applied'] = false;

		if ( ! $apply || 0 === $result['repairable_count'] || ! $result['integrity_valid'] ) {
			return $result;
		}

		$updater ??= array( $this, 'updatePostAtomically' );
		$updated    = $updater( $post_id, $result['content'], $content );
		if ( is_wp_error( $updated ) ) {
			$result['error_code'] = $updated->get_error_code();
			$result['error']      = $updated->get_error_message();
			return $result;
		}

		if ( $post_id !== $updated ) {
			$result['error_code'] = 'datamachine_blocks_repair_update_failed';
			$result['error']      = 'The post updater did not return the expected post ID.';
			return $result;
		}

		$result['applied'] = true;
		return $result;
	}

	/**
	 * Atomically update a post only when its content is unchanged.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $repaired_content Verified repaired content.
	 * @param string $inspected_content Content used to build the repair.
	 * @param object|null $database Optional wpdb-compatible test double.
	 * @param callable|null $writer Optional writer used to instrument the locked path.
	 * @return int|WP_Error
	 */
	public function updatePostAtomically( int $post_id, string $repaired_content, string $inspected_content, $database = null, ?callable $writer = null ) {
		global $wpdb;

		$database ??= $wpdb;
		$writer   ??= 'wp_update_post';
		$open       = false;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- This bounded operator repair owns the transaction.
		if ( false === $database->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'datamachine_blocks_repair_transaction_failed', 'Could not start the repair transaction.' );
		}
		$open = true;

		try {
			$query = $database->prepare( 'SELECT post_content FROM %i WHERE ID = %d FOR UPDATE', $database->posts, $post_id );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared exact-row lock is required for atomic compare-and-update.
			$current = $database->get_var( $query );
			if ( null === $current ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Close the owned transaction on every exit.
				$database->query( 'ROLLBACK' );
				$open = false;
				return new WP_Error( 'datamachine_blocks_repair_post_missing', 'The post no longer exists.' );
			}

			if ( $current !== $inspected_content ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Close the owned transaction on conflict.
				$database->query( 'ROLLBACK' );
				$open = false;
				return new WP_Error( 'datamachine_blocks_repair_conflict', 'Post content changed after inspection; no repair was applied.' );
			}

			$updated = $writer(
				array(
					'ID'           => $post_id,
					'post_content' => $repaired_content,
				),
				true
			);
			if ( is_wp_error( $updated ) || $post_id !== $updated ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Never commit a failed update.
				$database->query( 'ROLLBACK' );
				$open = false;
				return is_wp_error( $updated ) ? $updated : new WP_Error( 'datamachine_blocks_repair_update_failed', 'The post updater did not return the expected post ID.' );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Commit only the exact successful update.
			if ( false === $database->query( 'COMMIT' ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Best-effort cleanup if COMMIT failed.
				$database->query( 'ROLLBACK' );
				$open = false;
				return new WP_Error( 'datamachine_blocks_repair_commit_failed', 'Could not commit the repair transaction.' );
			}

			$open = false;
			return $updated;
		} catch ( \Throwable $throwable ) {
			if ( $open ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Exceptions must not leak transactions.
				$database->query( 'ROLLBACK' );
				$open = false;
			}
			return new WP_Error( 'datamachine_blocks_repair_exception', 'The atomic repair failed before commit.' );
		} finally {
			if ( $open ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Defensive transaction cleanup.
				$database->query( 'ROLLBACK' );
			}
		}
	}

	/**
	 * Parse content through WordPress's canonical block parser.
	 *
	 * @param string $content Serialized content.
	 * @return array
	 */
	protected function parse( string $content ): array {
		return parse_blocks( $content );
	}

	/**
	 * Serialize the expected repaired tree through WordPress core.
	 *
	 * @param array $blocks Parsed block tree.
	 */
	protected function serialize( array $blocks ): string {
		return serialize_blocks( $blocks );
	}

	/**
	 * Find only the proven malformed RichText content signature.
	 *
	 * @param array  $blocks Parsed blocks, modified in place for repairable findings.
	 * @param array  $findings Redacted finding inventory, modified in place.
	 * @param string $parent_path Parent block path.
	 */
	private function inspectBlocks( array &$blocks, array &$findings, string $parent_path = '' ): void {
		foreach ( $blocks as $index => &$block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$path       = '' === $parent_path ? (string) $index : $parent_path . '.' . $index;
			$block_name = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
			if ( in_array( $block_name, self::TARGET_BLOCKS, true ) && array_key_exists( 'content', $block['attrs'] ?? array() ) ) {
				$value      = $block['attrs']['content'];
				$block_type = WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
				$schema     = is_object( $block_type ) && is_array( $block_type->attributes['content'] ?? null ) ? $block_type->attributes['content'] : array();
				$reason     = $this->nonRepairableReason( $block_name, $schema, $value, (string) ( $block['innerHTML'] ?? '' ) );

				$findings[] = $this->finding( $path, $block_name, $value, $reason );
				if ( '' === $reason ) {
					unset( $block['attrs']['content'] );
				}
			}

			if ( is_array( $block['innerBlocks'] ?? null ) ) {
				$this->inspectBlocks( $block['innerBlocks'], $findings, $path );
			}
		}
		unset( $block );
	}

	/**
	 * Return why a candidate cannot be repaired, or an empty string when safe.
	 *
	 * @param string $block_name Core block name.
	 * @param array  $schema Registered content attribute schema.
	 * @param mixed  $value Stored delimiter value.
	 * @param string $inner_html Saved block HTML.
	 */
	private function nonRepairableReason( string $block_name, array $schema, $value, string $inner_html ): string {
		if ( 'rich-text' !== ( $schema['type'] ?? null ) || 'rich-text' !== ( $schema['source'] ?? null ) ) {
			return 'schema_not_proven_rich_text';
		}

		if ( ! is_string( $value ) ) {
			return 'content_value_not_string';
		}

		if ( '' === $value ) {
			return 'content_value_empty';
		}

		$root_content = $this->canonicalRootRichText( $block_name, $inner_html );
		if ( null === $root_content ) {
			return 'canonical_root_ambiguous';
		}

		if ( $root_content !== $value ) {
			return 'content_not_exact_root_rich_text';
		}

		return '';
	}

	/**
	 * Build a redacted finding without exposing full content.
	 *
	 * @param string $path Block path.
	 * @param string $block_name Block name.
	 * @param mixed  $value Stored delimiter value.
	 * @param string $reason Skip reason, or empty when repairable.
	 * @return array Redacted finding.
	 */
	private function finding( string $path, string $block_name, $value, string $reason ): array {
		$value_type = get_debug_type( $value );
		$string     = is_string( $value ) ? $value : wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$string     = is_string( $string ) ? $string : '';

		return array(
			'block_path'  => $path,
			'block_name'  => $block_name,
			'attribute'   => 'content',
			'status'      => '' === $reason ? 'repairable' : 'skipped',
			'reason'      => $reason,
			'value_type'  => $value_type,
			'value_bytes' => strlen( $string ),
			'value_sha256' => hash( 'sha256', $string ),
		);
	}

	/**
	 * Extract exact RichText bytes from the one canonical root element.
	 *
	 * No entity decoding, tag stripping, or substring matching is permitted.
	 * Ambiguous or non-canonical structures fail closed.
	 *
	 * @param string $block_name Core block name.
	 * @param string $inner_html Saved inner HTML.
	 */
	private function canonicalRootRichText( string $block_name, string $inner_html ): ?string {
		$attributes = '(?:\s+[^\s"\'=<>`]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?)*\s*';
		if ( 'core/heading' === $block_name ) {
			$pattern = '~\A<h([1-6])' . $attributes . '>(.*)</h\1>\z~s';
			$content_group = 2;
		} elseif ( 'core/paragraph' === $block_name ) {
			$pattern = '~\A<p' . $attributes . '>(.*)</p>\z~s';
			$content_group = 1;
		} elseif ( 'core/list-item' === $block_name ) {
			$pattern = '~\A<li' . $attributes . '>(.*)</li>\z~s';
			$content_group = 1;
		} else {
			return null;
		}

		if ( ! preg_match( $pattern, $inner_html, $matches ) ) {
			return null;
		}

		$content = $matches[ $content_group ];
		if ( preg_match( '~</?(?:p|li|h[1-6])(?:\s|>)~i', $content ) ) {
			return null;
		}

		return $content;
	}
}
