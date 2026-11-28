<?php
/**
 * Smoke test: fetch-wordpress-media is usable by Media Library uploaders.
 *
 * Users who can upload_files (and so can run upload-media) can list media,
 * scoped to attachments whose parent post they can read. Subscribers are
 * denied; operators keep full access.
 *
 * Run with: php tests/fetch-wordpress-media-permission-smoke.php
 *
 * @see https://github.com/Extra-Chill/data-machine/issues/4005
 * @package DataMachine\Tests
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['fwm_user']  = 0;
	$GLOBALS['fwm_caps']  = array();
	$GLOBALS['fwm_posts'] = array();

	function is_user_logged_in(): bool {
		return $GLOBALS['fwm_user'] > 0;
	}
	function get_current_user_id(): int {
		return $GLOBALS['fwm_user'];
	}
	function user_can( $user_id, string $cap, ...$args ): bool {
		if ( 'read_post' === $cap ) {
			// Mirror core: an inherit attachment is readable when its parent
			// is published, or when the user authored a non-public parent.
			$post   = $GLOBALS['fwm_posts'][ $args[0] ] ?? null;
			$parent = $post ? ( $GLOBALS['fwm_posts'][ $post->post_parent ] ?? null ) : null;
			if ( ! $parent ) {
				return false;
			}
			return 'publish' === $parent->post_status
				|| (int) $parent->post_author === (int) $user_id
				|| in_array( 'read_private_posts', $GLOBALS['fwm_caps'][ $user_id ] ?? array(), true );
		}
		return in_array( $cap, $GLOBALS['fwm_caps'][ $user_id ] ?? array(), true );
	}
	function doing_action( $hook = null ): bool {
		return false;
	}
	function apply_filters( $hook, $value ) {
		return $value;
	}
	function get_post_meta( $id, $key, $single = false ) {
		return '';
	}
	function get_post_mime_type( $id ) {
		return 'image/jpeg';
	}
	function get_attached_file( $id ) {
		return '/nonexistent/file-' . $id . '.jpg';
	}
	function get_bloginfo( $show ) {
		return 'Test Site';
	}
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text );
	}
	function size_format( $bytes ) {
		return $bytes . ' B';
	}
	function get_post( $id ) {
		return $GLOBALS['fwm_posts'][ $id ] ?? null;
	}
	function get_permalink( $id ) {
		return 'https://example.test/?p=' . $id;
	}
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}

	class WP_Error {
	}

	class WP_Query {
		public array $posts = array();
		public function __construct( array $args ) {
			foreach ( $GLOBALS['fwm_posts'] as $post ) {
				if ( 'attachment' === $post->post_type && $post->post_parent > 0 ) {
					$this->posts[] = $post;
				}
			}
		}
	}

	$make_post = static function ( int $id, string $type, string $status, int $parent, int $author ): object {
		return (object) array(
			'ID'            => $id,
			'post_type'     => $type,
			'post_status'   => $status,
			'post_parent'   => $parent,
			'post_author'   => $author,
			'post_title'    => "Post {$id}",
			'post_content'  => '',
			'post_excerpt'  => '',
			'post_date_gmt' => '2025-01-01 00:00:00',
		);
	};

	$GLOBALS['fwm_posts'] = array(
		100 => $make_post( 100, 'post', 'publish', 0, 9 ),
		101 => $make_post( 101, 'post', 'draft', 0, 9 ),
		102 => $make_post( 102, 'post', 'private', 0, 38 ),
		200 => $make_post( 200, 'attachment', 'inherit', 100, 9 ),
		201 => $make_post( 201, 'attachment', 'inherit', 101, 9 ),
		202 => $make_post( 202, 'attachment', 'inherit', 102, 38 ),
	);

	$GLOBALS['fwm_caps'] = array(
		38 => array( 'read', 'upload_files' ), // extra_chill_team-style uploader.
		2  => array( 'read' ),                  // Subscriber.
		1  => array( 'read', 'manage_options' ), // Administrator.
		5  => array( 'read', 'datamachine_manage_flows' ), // Operator.
	);

	require dirname( __DIR__ ) . '/inc/Abilities/PermissionHelper.php';
	require dirname( __DIR__ ) . '/inc/Abilities/Fetch/Traits/HasApplyKeywordSearch.php';
	require dirname( __DIR__ ) . '/inc/Abilities/Fetch/Traits/HasApplyKeywordExclusion.php';
	require dirname( __DIR__ ) . '/inc/Abilities/Fetch/FetchWordPressMediaAbility.php';

	$failures = 0;
	$check    = static function ( bool $cond, string $msg ) use ( &$failures ): void {
		if ( ! $cond ) {
			++$failures;
			fwrite( STDERR, "FAIL: {$msg}\n" );
		} else {
			echo "ok - {$msg}\n";
		}
	};

	$ability = ( new ReflectionClass( \DataMachine\Abilities\Fetch\FetchWordPressMediaAbility::class ) )->newInstanceWithoutConstructor();

	$ids = static function ( $result ): array {
		$items = is_array( $result ) ? ( $result['data']['items'] ?? array() ) : array();
		$out   = array_map( static fn( $item ) => $item['metadata']['original_id'], $items );
		sort( $out );
		return $out;
	};

	$GLOBALS['fwm_user'] = 0;
	$check( ! $ability->checkPermission(), 'logged-out request is denied' );

	$GLOBALS['fwm_user'] = 2;
	$check( ! $ability->checkPermission(), 'subscriber is denied' );

	$GLOBALS['fwm_user'] = 38;
	$check( $ability->checkPermission(), 'upload_files non-manager is allowed' );
	$check( ! \DataMachine\Abilities\PermissionHelper::can_manage(), 'uploader is still not an operator' );
	$check(
		array( 200, 202 ) === $ids( $ability->execute( array() ) ),
		'uploader sees published-parent and own-parent media, not others\' draft-parent media'
	);

	$GLOBALS['fwm_user'] = 1;
	$check( $ability->checkPermission(), 'administrator is allowed' );
	$check( array( 200, 201, 202 ) === $ids( $ability->execute( array() ) ), 'administrator sees all media' );

	$GLOBALS['fwm_user'] = 5;
	$check( $ability->checkPermission(), 'manager (manage_flows) is allowed' );
	$check( array( 200, 201, 202 ) === $ids( $ability->execute( array() ) ), 'manager sees all media' );

	if ( $failures ) {
		fwrite( STDERR, "{$failures} failure(s).\n" );
		exit( 1 );
	}
	echo "Fetch WordPress media permission smoke passed.\n";
}
