<?php
/**
 * Public content authorization regressions for issue #4015.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\Fetch\GetWordPressPostAbility;
use DataMachine\Abilities\Fetch\QueryWordPressPostsAbility;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Abilities\Taxonomy\GetTaxonomyTermsAbility;
use WP_UnitTestCase;

class PublicContentReadAbilitiesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'datamachine_cli_bypass_permissions', '__return_false' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		remove_filter( 'datamachine_cli_bypass_permissions', '__return_false' );
		parent::tear_down();
	}

	public function test_editor_can_query_and_fetch_public_posts(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$query   = new QueryWordPressPostsAbility();
		$get     = new GetWordPressPostAbility();

		$this->assertFalse( PermissionHelper::can_manage() );
		$this->assertTrue( $query->checkPermission() );
		$this->assertTrue( $get->checkPermission() );
		$result = $query->execute( array( 'include_file_info' => false ) );
		$this->assertNotWPError( $result );
		$this->assertContains( $post_id, array_column( array_column( $result['data']['items'], 'metadata' ), 'original_id' ) );
		$result = $get->execute( array( 'post_id' => $post_id ) );
		$this->assertNotWPError( $result );
		$this->assertSame( $post_id, $result['data']['post_id'] );
	}

	public function test_editor_cannot_fetch_or_query_another_users_draft(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => self::factory()->user->create( array( 'role' => 'author' ) ),
			)
		);
		$result = ( new GetWordPressPostAbility() )->execute( array( 'post_id' => $post_id ) );
		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$result = ( new QueryWordPressPostsAbility() )->execute( array( 'post_status' => 'draft' ) );
		$this->assertNotWPError( $result );
		$this->assertEmpty( $result['data'] );
	}

	public function test_editor_can_get_public_terms_but_not_write_them(): void {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$read    = new GetTaxonomyTermsAbility();
		$this->assertTrue( $read->checkPermission() );
		$result = $read->execute( array( 'taxonomy' => 'category' ) );
		$this->assertNotWPError( $result );
		$this->assertContains( $term_id, array_column( $result['terms'], 'term_id' ) );

		foreach ( array( 'CreateTaxonomyTermAbility', 'UpdateTaxonomyTermAbility', 'DeleteTaxonomyTermAbility', 'MergeTermMetaAbility', 'ResolveTermAbility' ) as $name ) {
			$class = 'DataMachine\\Abilities\\Taxonomy\\' . $name;
			$this->assertFalse( ( new $class() )->checkPermission(), $name );
		}
	}

	public function test_subscriber_reads_public_content_but_not_private_content(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$get     = new GetWordPressPostAbility();
		// Another author: WordPress lets a subscriber read their own private post.
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => self::factory()->user->create( array( 'role' => 'author' ) ),
			)
		);
		$this->assertTrue( $get->checkPermission() );
		$this->assertNotWPError( $get->execute( array( 'post_id' => $post_id ) ) );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'private' ) );
		$this->assertWPError( $get->execute( array( 'post_id' => $post_id ) ) );
		$result = ( new QueryWordPressPostsAbility() )->execute( array( 'post_status' => 'private' ) );
		$this->assertNotWPError( $result );
		$this->assertEmpty( $result['data'] );
	}

	public function test_non_public_taxonomies_are_denied_and_excluded(): void {
		register_taxonomy( 'dm_private_terms', 'post', array( 'public' => false ) );
		try {
			$term_id = self::factory()->term->create( array( 'taxonomy' => 'dm_private_terms' ) );
			$read    = new GetTaxonomyTermsAbility();
			$this->assertWPError( $read->execute( array( 'taxonomy' => 'dm_private_terms' ) ) );
			$result = $read->execute( array() );
			$this->assertNotWPError( $result );
			$this->assertNotContains( $term_id, array_column( $result['terms'], 'term_id' ) );
		} finally {
			unregister_taxonomy( 'dm_private_terms' );
		}
	}

	public function test_owner_can_read_own_draft_and_operator_can_read_others_draft(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => get_current_user_id(),
			)
		);
		$get = new GetWordPressPostAbility();
		$this->assertNotWPError( $get->execute( array( 'post_id' => $post_id ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( PermissionHelper::can_manage() );
		$this->assertNotWPError( $get->execute( array( 'post_id' => $post_id ) ) );
	}

	public function test_read_alone_cannot_expose_password_protected_or_non_public_post_types(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		$this->assertWPError( ( new GetWordPressPostAbility() )->execute( array( 'post_id' => $post_id ) ) );
		register_post_type( 'dm_private_post', array( 'public' => false ) );
		try {
			$post_id = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_type'   => 'dm_private_post',
				)
			);
			$this->assertWPError( ( new GetWordPressPostAbility() )->execute( array( 'post_id' => $post_id ) ) );
			$result = ( new QueryWordPressPostsAbility() )->execute( array( 'post_type' => 'dm_private_post' ) );
			$this->assertNotWPError( $result );
			$this->assertEmpty( $result['data'] );
		} finally {
			unregister_post_type( 'dm_private_post' );
		}
	}

	public function test_anonymous_caller_is_denied(): void {
		wp_set_current_user( 0 );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		// The Abilities API enforces checkPermission() before execute().
		$this->assertFalse( ( new GetWordPressPostAbility() )->checkPermission() );
		$this->assertFalse( ( new QueryWordPressPostsAbility() )->checkPermission() );
		$this->assertFalse( ( new GetTaxonomyTermsAbility() )->checkPermission() );
		// Object-level visibility still holds on direct execution.
		$this->assertWPError( ( new GetWordPressPostAbility() )->execute( array( 'post_id' => $post_id ) ) );
		$result = ( new QueryWordPressPostsAbility() )->execute( array( 'include_file_info' => false ) );
		$this->assertNotWPError( $result );
		$this->assertEmpty( $result['data'] );
	}
}
