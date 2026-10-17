<?php
/**
 * Coverage for #3635: image-template rendering is a content-creation
 * action gated on a dedicated `render_images` permission action
 * (`datamachine_render_images` capability), not pipeline/agent
 * administration (`can_manage()`).
 *
 * - A user holding only `datamachine_render_images` can render.
 * - A user without it cannot.
 * - `can_manage()` holders continue to pass (implied-by-management,
 *   same precedent as `view_analytics`).
 * - Agent token ceilings understand the new action.
 *
 * @package DataMachine\Tests\Unit\Abilities\Media
 * @see https://github.com/Extra-Chill/data-machine/issues/3635
 */

namespace DataMachine\Tests\Unit\Abilities\Media;

use DataMachine\Abilities\PermissionHelper;
use WP_UnitTestCase;

class ImageTemplateRenderPermissionTest extends WP_UnitTestCase {

	public function tear_down(): void {
		PermissionHelper::clear_agent_context();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_dedicated_render_images_cap_grants_render_action(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'datamachine_render_images' );
		wp_set_current_user( $user_id );

		$this->assertTrue( PermissionHelper::can( 'render_images' ) );

		$user->remove_cap( 'datamachine_render_images' );
	}

	public function test_render_images_denied_without_the_cap(): void {
		// Mirrors the concrete case in #3635: contributor with only
		// datamachine_chat must not be able to render — but also must not
		// need flow/agent/settings admin to get there.
		$user_id = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'datamachine_chat' );
		wp_set_current_user( $user_id );

		$this->assertFalse( PermissionHelper::can( 'render_images' ) );

		$user->remove_cap( 'datamachine_chat' );
	}

	public function test_render_images_implied_by_management_capability(): void {
		$user_id = self::factory()->user->create();
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'datamachine_manage_flows' );
		wp_set_current_user( $user_id );

		// A manage_flows holder keeps rendering access — no regression.
		$this->assertTrue( PermissionHelper::can( 'render_images' ) );

		$user->remove_cap( 'datamachine_manage_flows' );
	}

	public function test_render_images_granted_to_admin(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->assertTrue( PermissionHelper::can( 'render_images' ) );
	}

	public function test_render_images_cap_does_not_grant_management_surface(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'datamachine_render_images' );
		wp_set_current_user( $user_id );

		// Content-creation access granted...
		$this->assertTrue( PermissionHelper::can( 'render_images' ) );

		// ...but NOT the pipeline/agent/settings admin surface.
		$this->assertFalse( PermissionHelper::can_manage() );

		$user->remove_cap( 'datamachine_render_images' );
	}

	public function test_agent_token_ceiling_understands_render_images(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		PermissionHelper::set_agent_context(
			123,
			$owner_id,
			array( 'datamachine_render_images' ),
			456
		);

		$this->assertTrue( PermissionHelper::can( 'render_images' ) );
		$this->assertFalse( PermissionHelper::can( 'manage_flows' ) );
	}

	public function test_agent_token_ceiling_without_render_images_denies(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		PermissionHelper::set_agent_context(
			123,
			$owner_id,
			array( 'datamachine_chat' ),
			456
		);

		$this->assertFalse( PermissionHelper::can( 'render_images' ) );
	}

	public function test_render_ability_permission_callback_uses_render_images_action(): void {
		$render = wp_get_ability( 'datamachine/render-image-template' );
		$list   = wp_get_ability( 'datamachine/list-image-templates' );

		if ( ! $render || ! $list ) {
			$this->markTestSkipped( 'Image-template abilities not registered.' );
		}

		// Content-team user with only the dedicated cap can reach both abilities.
		$user_id = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'datamachine_render_images' );
		wp_set_current_user( $user_id );

		$this->assertTrue( $render->check_permissions( array() ) );
		$this->assertTrue( $list->check_permissions( array() ) );

		// Without it, both abilities deny.
		$user->remove_cap( 'datamachine_render_images' );
		wp_set_current_user( $user_id );

		$this->assertNotTrue( $render->check_permissions( array() ) );
		$this->assertNotTrue( $list->check_permissions( array() ) );

		// can_manage() holders (admins/operators) still pass — no regression.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->assertTrue( $render->check_permissions( array() ) );
		$this->assertTrue( $list->check_permissions( array() ) );
	}

	public function test_activation_grants_render_images_to_administrator_role(): void {
		\DataMachine\Core\Bootstrap\ActivationServiceProvider::register_capabilities();

		$role = get_role( 'administrator' );

		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( 'datamachine_render_images' ) );

		// Grantable to non-admin roles via the standard WordPress cap mechanism.
		$contributor = get_role( 'contributor' );
		$this->assertNotNull( $contributor );
		$this->assertFalse( $contributor->has_cap( 'datamachine_render_images' ) );
		$contributor->add_cap( 'datamachine_render_images' );
		$this->assertTrue( get_role( 'contributor' )->has_cap( 'datamachine_render_images' ) );
		$contributor->remove_cap( 'datamachine_render_images' );
	}
}
