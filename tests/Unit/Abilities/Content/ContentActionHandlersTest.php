<?php
/**
 * ContentActionHandlers Tests
 *
 * Validates that the three content abilities (edit_post_blocks,
 * replace_post_blocks, insert_content) stage previews through the unified
 * PendingActionStore lane and resolve correctly via
 * ResolvePendingActionAbility.
 *
 * @package DataMachine\Tests\Unit\Abilities\Content
 * @since   0.79.0
 */

namespace DataMachine\Tests\Unit\Abilities\Content;

use DataMachine\Abilities\Content\EditPostBlocksAbility;
use DataMachine\Abilities\Content\InsertContentAbility;
use DataMachine\Abilities\Content\ReplacePostBlocksAbility;
use DataMachine\Engine\AI\Actions\PendingActionStore;
use DataMachine\Engine\AI\Actions\ResolvePendingActionAbility;
use WP_UnitTestCase;

class ContentActionHandlersTest extends WP_UnitTestCase {

	private int $admin_id;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_author'  => $this->admin_id,
				'post_content' => "<!-- wp:paragraph -->\n<p>Hello world.</p>\n<!-- /wp:paragraph -->",
			)
		);
	}

	public function test_three_kinds_register_on_handlers_filter(): void {
		$handlers = apply_filters( 'datamachine_pending_action_handlers', array() );

		$this->assertArrayHasKey( 'edit_post_blocks', $handlers );
		$this->assertArrayHasKey( 'replace_post_blocks', $handlers );
		$this->assertArrayHasKey( 'insert_content', $handlers );

		foreach ( array( 'edit_post_blocks', 'replace_post_blocks', 'insert_content' ) as $kind ) {
			$this->assertIsCallable( $handlers[ $kind ]['apply'], "apply for {$kind}" );
			$this->assertIsCallable( $handlers[ $kind ]['can_resolve'], "can_resolve for {$kind}" );
		}
	}

	public function test_edit_post_blocks_preview_stages_and_resolves(): void {
		$result = EditPostBlocksAbility::execute(
			array(
				'post_id' => $this->post_id,
				'edits'   => array(
					array(
						'block_index' => 0,
						'find'        => 'Hello world.',
						'replace'     => 'Hello cosmos.',
					),
				),
				'preview' => true,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['is_preview'] );
		$this->assertNotEmpty( $result['action_id'] );
		$this->assertStringStartsWith( 'act_', $result['action_id'] );
		$this->assertSame( 'edit_post_blocks', $result['kind'] );
		$this->assertIsArray( $result['preview'] );
		$this->assertSame( $result['action_id'], $result['preview']['actionId'] ?? null, 'preview_data.actionId should equal top-level action_id' );

		// Payload shape on disk.
		$payload = PendingActionStore::get( $result['action_id'] );
		$this->assertIsArray( $payload );
		$this->assertSame( 'edit_post_blocks', $payload['kind'] );
		$this->assertArrayNotHasKey( 'preview', $payload['apply_input'], 'apply_input must not carry preview flag' );
		$this->assertSame( $this->post_id, $payload['apply_input']['post_id'] );

		// Post content unchanged until resolution.
		$this->assertStringContainsString( 'Hello world.', get_post( $this->post_id )->post_content );

		// Accept.
		$resolution = ResolvePendingActionAbility::execute(
			array(
				'action_id' => $result['action_id'],
				'decision'  => 'accepted',
			)
		);

		$this->assertTrue( $resolution['success'] );
		$this->assertSame( 'accepted', $resolution['decision'] );
		$this->assertStringContainsString( 'Hello cosmos.', get_post( $this->post_id )->post_content );
		$this->assertNull( PendingActionStore::get( $result['action_id'] ), 'transient must be deleted post-resolution' );
	}

	public function test_edit_post_blocks_preview_rejects_without_applying(): void {
		$result = EditPostBlocksAbility::execute(
			array(
				'post_id' => $this->post_id,
				'edits'   => array(
					array(
						'block_index' => 0,
						'find'        => 'Hello world.',
						'replace'     => 'Goodbye.',
					),
				),
				'preview' => true,
			)
		);

		$this->assertTrue( $result['success'] );

		$resolution = ResolvePendingActionAbility::execute(
			array(
				'action_id' => $result['action_id'],
				'decision'  => 'rejected',
			)
		);

		$this->assertTrue( $resolution['success'] );
		$this->assertSame( 'rejected', $resolution['decision'] );
		$this->assertStringContainsString( 'Hello world.', get_post( $this->post_id )->post_content );
		$this->assertStringNotContainsString( 'Goodbye.', get_post( $this->post_id )->post_content );
		$this->assertNull( PendingActionStore::get( $result['action_id'] ) );
	}

	public function test_replace_post_blocks_preview_stages_and_resolves(): void {
		$result = ReplacePostBlocksAbility::execute(
			array(
				'post_id'      => $this->post_id,
				'replacements' => array(
					array(
						'block_index' => 0,
						'new_content' => 'Rewritten paragraph.',
					),
				),
				'preview'      => true,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'replace_post_blocks', $result['kind'] );
		$this->assertStringStartsWith( 'act_', $result['action_id'] );

		$payload = PendingActionStore::get( $result['action_id'] );
		$this->assertSame( 'replace_post_blocks', $payload['kind'] );
		$this->assertArrayNotHasKey( 'preview', $payload['apply_input'] );

		$resolution = ResolvePendingActionAbility::execute(
			array(
				'action_id' => $result['action_id'],
				'decision'  => 'accepted',
			)
		);

		$this->assertTrue( $resolution['success'] );
		$this->assertStringContainsString( 'Rewritten paragraph.', get_post( $this->post_id )->post_content );
	}

	public function test_insert_content_preview_stages_and_resolves(): void {
		$result = InsertContentAbility::execute(
			array(
				'post_id'  => $this->post_id,
				'content'  => 'Fresh insertion.',
				'position' => 'end',
				'preview'  => true,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'insert_content', $result['kind'] );
		$this->assertStringStartsWith( 'act_', $result['action_id'] );

		$payload = PendingActionStore::get( $result['action_id'] );
		$this->assertSame( 'insert_content', $payload['kind'] );
		$this->assertSame( 'end', $payload['apply_input']['position'] );

		$resolution = ResolvePendingActionAbility::execute(
			array(
				'action_id' => $result['action_id'],
				'decision'  => 'accepted',
			)
		);

		$this->assertTrue( $resolution['success'] );
		$this->assertStringContainsString( 'Fresh insertion.', get_post( $this->post_id )->post_content );
	}

	public function test_can_resolve_denies_unauthorized_user(): void {
		$result = EditPostBlocksAbility::execute(
			array(
				'post_id' => $this->post_id,
				'edits'   => array(
					array(
						'block_index' => 0,
						'find'        => 'Hello world.',
						'replace'     => 'Nope.',
					),
				),
				'preview' => true,
			)
		);

		$this->assertTrue( $result['success'] );

		// Log in as a subscriber who cannot edit the post.
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$resolution = ResolvePendingActionAbility::execute(
			array(
				'action_id' => $result['action_id'],
				'decision'  => 'accepted',
			)
		);

		$this->assertFalse( $resolution['success'] );
		$this->assertStringContainsString( 'permission', strtolower( $resolution['error'] ) );

		// Post untouched because the apply never ran.
		wp_set_current_user( $this->admin_id );
		$this->assertStringContainsString( 'Hello world.', get_post( $this->post_id )->post_content );

		// Transient still present — can_resolve failures don't delete.
		$this->assertIsArray( PendingActionStore::get( $result['action_id'] ) );
	}

	public function test_canonical_preview_exposes_actionId_not_diffId(): void {
		$result = EditPostBlocksAbility::execute(
			array(
				'post_id' => $this->post_id,
				'edits'   => array(
					array(
						'block_index' => 0,
						'find'        => 'Hello world.',
						'replace'     => 'Hi.',
					),
				),
				'preview' => true,
			)
		);

		$preview_data = $result['preview'] ?? array();
		$this->assertIsArray( $preview_data );
		$this->assertArrayHasKey( 'actionId', $preview_data );
		$this->assertArrayNotHasKey( 'diffId', $preview_data );
		$this->assertSame( $result['action_id'], $preview_data['actionId'] );
	}

	public function test_resolved_pending_action_fires_hook_once(): void {
		$fired = 0;
		add_action(
			'datamachine_pending_action_resolved',
			static function () use ( &$fired ) {
				$fired++;
			}
		);

		$result = EditPostBlocksAbility::execute(
			array(
				'post_id' => $this->post_id,
				'edits'   => array(
					array(
						'block_index' => 0,
						'find'        => 'Hello world.',
						'replace'     => 'Hi.',
					),
				),
				'preview' => true,
			)
		);

		ResolvePendingActionAbility::execute(
			array(
				'action_id' => $result['action_id'],
				'decision'  => 'accepted',
			)
		);

		$this->assertSame( 1, $fired );
	}
}
