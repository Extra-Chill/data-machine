<?php
/**
 * Object-capability authorization for datamachine/upsert-post.
 *
 * The chat action alone must not authorize content writes: inserts need the
 * post type's create/publish capabilities and updates need edit_post on the
 * resolved target, bounded by any agent token capability ceiling.
 *
 * These tests avoid identity_meta so they run on the SQLite suite.
 *
 * @package DataMachine\Tests\Unit\Abilities\Content
 */

namespace DataMachine\Tests\Unit\Abilities\Content;

use DataMachine\Abilities\Content\UpsertPostAbility;
use DataMachine\Abilities\PermissionHelper;
use WP_UnitTestCase;

class UpsertPostPermissionTest extends WP_UnitTestCase {

	public function tear_down(): void {
		PermissionHelper::clear_agent_context();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** Create a user holding the chat capability and make them current. */
	private function act_as( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		get_user_by( 'id', $user_id )->add_cap( 'datamachine_chat' );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	private function input( array $overrides = array() ): array {
		return array_merge(
			array(
				'post_type' => 'post',
				'title'     => 'Upsert permission',
				'content'   => '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
			),
			$overrides
		);
	}

	private function assertForbidden( $result ): void {
		$this->assertWPError( $result );
		$this->assertSame( 'upsert_post_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	private function assertDenied( $permission ): void {
		$this->assertTrue( false === $permission || is_wp_error( $permission ), 'Permission check should deny.' );
	}

	private function foreign_post( string $status = 'publish', string $title = 'Foreign' ): int {
		$owner = self::factory()->user->create( array( 'role' => 'editor' ) );
		return self::factory()->post->create(
			array(
				'post_author' => $owner,
				'post_status' => $status,
				'post_title'  => $title,
				'post_name'   => sanitize_title( $title ),
			)
		);
	}

	public function test_subscriber_with_chat_cannot_insert_draft_or_publish(): void {
		$this->act_as( 'subscriber' );
		$this->assertTrue( PermissionHelper::can( 'chat' ) );

		foreach ( array( 'draft', 'publish', 'pending' ) as $status ) {
			$input = $this->input( array( 'post_status' => $status ) );
			$this->assertDenied( UpsertPostAbility::check_permission( $input ) );
			$this->assertForbidden( UpsertPostAbility::execute( $input ) );
		}

		// Default status is publish.
		$this->assertForbidden( UpsertPostAbility::execute( $this->input() ) );
		$this->assertSame( 0, (int) ( new \WP_Query( array( 'title' => 'Upsert permission', 'post_status' => 'any', 'fields' => 'ids' ) ) )->found_posts );
	}

	public function test_subscriber_with_chat_cannot_update_existing_post(): void {
		$post_id = $this->foreign_post();
		$this->act_as( 'subscriber' );

		$input = $this->input(
			array(
				'post_id' => $post_id,
				'title'   => 'Overwritten',
			)
		);
		$this->assertDenied( UpsertPostAbility::check_permission( $input ) );
		$this->assertForbidden( UpsertPostAbility::execute( $input ) );
		$this->assertSame( 'Foreign', get_post( $post_id )->post_title );
	}

	public function test_subscriber_cannot_create_pages(): void {
		$this->act_as( 'subscriber' );
		$this->assertForbidden( UpsertPostAbility::execute( $this->input( array( 'post_type' => 'page', 'post_status' => 'draft' ) ) ) );
	}

	public function test_author_can_create_own_draft_and_publish(): void {
		$author = $this->act_as( 'author' );

		$draft = $this->input( array( 'post_status' => 'draft', 'title' => 'Author draft' ) );
		$this->assertTrue( UpsertPostAbility::check_permission( $draft ) );
		$result = UpsertPostAbility::execute( $draft );
		$this->assertIsArray( $result );
		$this->assertSame( 'created', $result['action'] );
		$this->assertSame( 'draft', get_post_status( $result['post_id'] ) );
		$this->assertSame( $author, (int) get_post( $result['post_id'] )->post_author );

		// Publishing their own draft through an update is allowed.
		$update = UpsertPostAbility::execute(
			$this->input(
				array(
					'post_id'     => $result['post_id'],
					'post_status' => 'publish',
					'title'       => 'Author draft',
				)
			)
		);
		$this->assertIsArray( $update );
		$this->assertSame( 'updated', $update['action'] );
		$this->assertSame( 'publish', get_post_status( $result['post_id'] ) );

		$published = UpsertPostAbility::execute( $this->input( array( 'title' => 'Author published' ) ) );
		$this->assertIsArray( $published );
		$this->assertSame( 'publish', get_post_status( $published['post_id'] ) );
	}

	public function test_author_cannot_edit_others_posts_by_id_or_slug(): void {
		$post_id = $this->foreign_post( 'publish', 'Editor owned' );
		$this->act_as( 'author' );

		$by_id = $this->input(
			array(
				'post_id' => $post_id,
				'title'   => 'Hijacked',
			)
		);
		$this->assertDenied( UpsertPostAbility::check_permission( $by_id ) );
		$this->assertForbidden( UpsertPostAbility::execute( $by_id ) );

		// Slug resolution happens at execute time and must be re-authorized.
		$by_slug = $this->input(
			array(
				'slug'  => 'editor-owned',
				'title' => 'Hijacked',
			)
		);
		$this->assertTrue( UpsertPostAbility::check_permission( $by_slug ) );
		$this->assertForbidden( UpsertPostAbility::execute( $by_slug ) );

		$this->assertSame( 'Editor owned', get_post( $post_id )->post_title );
	}

	public function test_author_cannot_create_posts_as_another_user(): void {
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->act_as( 'author' );

		$input = $this->input(
			array(
				'post_author' => $other,
				'post_status' => 'draft',
			)
		);
		$this->assertDenied( UpsertPostAbility::check_permission( $input ) );
		$this->assertForbidden( UpsertPostAbility::execute( $input ) );
	}

	public function test_protected_meta_requires_edit_others_posts(): void {
		$this->act_as( 'author' );

		$protected = $this->input(
			array(
				'post_status' => 'draft',
				'meta_input'  => array( '_wp_page_template' => 'custom.php' ),
			)
		);
		$this->assertDenied( UpsertPostAbility::check_permission( $protected ) );
		$this->assertForbidden( UpsertPostAbility::execute( $protected ) );

		$public = UpsertPostAbility::execute(
			$this->input(
				array(
					'post_status' => 'draft',
					'meta_input'  => array( 'public_note' => 'ok' ),
				)
			)
		);
		$this->assertIsArray( $public );
		$this->assertSame( 'ok', get_post_meta( $public['post_id'], 'public_note', true ) );

		$this->act_as( 'editor' );
		$this->assertTrue( UpsertPostAbility::check_permission( $protected ) );
	}

	public function test_editor_can_edit_others_posts(): void {
		$post_id = $this->foreign_post( 'publish', 'Someone else' );
		$this->act_as( 'editor' );

		$input = $this->input(
			array(
				'post_id' => $post_id,
				'title'   => 'Edited by editor',
			)
		);
		$this->assertTrue( UpsertPostAbility::check_permission( $input ) );
		$result = UpsertPostAbility::execute( $input );
		$this->assertIsArray( $result );
		$this->assertSame( 'updated', $result['action'] );
		$this->assertSame( 'Edited by editor', get_post( $post_id )->post_title );
	}

	public function test_contributor_can_draft_but_not_publish(): void {
		$this->act_as( 'contributor' );

		foreach ( array( 'publish', 'future', 'private' ) as $status ) {
			$input = $this->input( array( 'post_status' => $status ) );
			$this->assertDenied( UpsertPostAbility::check_permission( $input ) );
			$this->assertForbidden( UpsertPostAbility::execute( $input ) );
		}

		$pending = UpsertPostAbility::execute( $this->input( array( 'post_status' => 'pending' ) ) );
		$this->assertIsArray( $pending );
		$this->assertSame( 'pending', get_post_status( $pending['post_id'] ) );

		// And cannot publish their own pending post through an update.
		$this->assertForbidden(
			UpsertPostAbility::execute(
				$this->input(
					array(
						'post_id'     => $pending['post_id'],
						'post_status' => 'publish',
					)
				)
			)
		);
		$this->assertSame( 'pending', get_post_status( $pending['post_id'] ) );
	}

	public function test_agent_token_ceiling_bounds_owner_capabilities(): void {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		get_user_by( 'id', $owner )->add_cap( 'datamachine_chat' );
		// Bearer-token requests authenticate as the agent owner.
		wp_set_current_user( $owner );

		// Token scope allows drafting but not publishing.
		PermissionHelper::set_agent_context( 77, $owner, array( 'read', 'datamachine_chat', 'edit_posts' ) );

		$this->assertForbidden( UpsertPostAbility::execute( $this->input( array( 'post_status' => 'publish' ) ) ) );

		$draft = UpsertPostAbility::execute( $this->input( array( 'post_status' => 'draft' ) ) );
		$this->assertIsArray( $draft );
		$this->assertSame( $owner, (int) get_post( $draft['post_id'] )->post_author );

		// Owner capabilities remain the outer bound: an author's token cannot
		// edit another user's post even when unrestricted.
		PermissionHelper::clear_agent_context();
		PermissionHelper::set_agent_context( 77, $owner, null );
		$this->assertForbidden(
			UpsertPostAbility::execute(
				$this->input(
					array(
						'post_id' => $this->foreign_post(),
						'title'   => 'Agent overwrite',
					)
				)
			)
		);
	}

	public function test_system_context_keeps_working_without_a_user(): void {
		wp_set_current_user( 0 );

		$result = PermissionHelper::run_as_system(
			fn() => UpsertPostAbility::execute( $this->input( array( 'title' => 'System write' ) ) )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'created', $result['action'] );
	}

	public function test_logged_out_requests_are_denied(): void {
		wp_set_current_user( 0 );
		$this->assertFalse( UpsertPostAbility::check_permission( $this->input() ) );
		$this->assertForbidden( UpsertPostAbility::execute( $this->input() ) );
	}
}
