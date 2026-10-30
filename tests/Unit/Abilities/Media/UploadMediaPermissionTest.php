<?php
/**
 * Coverage for #3688: importing a remote URL into the Media Library through
 * datamachine/upload-media needs only core `upload_files` (the
 * `upload_media` permission action), while local file paths and
 * repository-only storage stay behind can_manage().
 *
 * @package DataMachine\Tests\Unit\Abilities\Media
 * @see https://github.com/Extra-Chill/data-machine/issues/3688
 */

namespace DataMachine\Tests\Unit\Abilities\Media;

use DataMachine\Abilities\Media\MediaAbilities;
use DataMachine\Abilities\PermissionHelper;
use WP_UnitTestCase;

class UploadMediaPermissionTest extends WP_UnitTestCase {

	private const LIBRARY_IMPORT = array(
		'url'              => 'https://cdn.example.com/attachments/1/2/flyer.jpg',
		'to_media_library' => true,
	);

	public function set_up(): void {
		parent::set_up();
		new MediaAbilities();
	}

	public function tear_down(): void {
		PermissionHelper::clear_agent_context();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	private function editor(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	public function test_editor_can_import_a_url_into_the_media_library(): void {
		$this->editor();

		$this->assertTrue( MediaAbilities::canUploadMedia( self::LIBRARY_IMPORT ) );
	}

	public function test_editor_is_denied_local_file_paths(): void {
		$this->editor();

		$this->assertFalse(
			MediaAbilities::canUploadMedia(
				array(
					'file_path'        => '/etc/passwd',
					'to_media_library' => true,
				)
			)
		);
		$this->assertFalse(
			MediaAbilities::canUploadMedia( self::LIBRARY_IMPORT + array( 'file_path' => '/etc/passwd' ) )
		);
	}

	public function test_editor_is_denied_repository_storage(): void {
		$this->editor();

		$this->assertFalse( MediaAbilities::canUploadMedia( array( 'url' => self::LIBRARY_IMPORT['url'] ) ) );
		$this->assertFalse( MediaAbilities::canUploadMedia( self::LIBRARY_IMPORT + array( 'flow_id' => 7 ) ) );
		$this->assertFalse( MediaAbilities::canUploadMedia( self::LIBRARY_IMPORT + array( 'pipeline_id' => 3 ) ) );
		$this->assertFalse( MediaAbilities::canUploadMedia( null ) );
	}

	public function test_user_without_upload_files_is_denied(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$this->assertFalse( MediaAbilities::canUploadMedia( self::LIBRARY_IMPORT ) );
	}

	public function test_operators_keep_every_mode(): void {
		$user_id = self::factory()->user->create();
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'datamachine_manage_flows' );
		wp_set_current_user( $user_id );

		$this->assertTrue( MediaAbilities::canUploadMedia( self::LIBRARY_IMPORT ) );
		$this->assertTrue( MediaAbilities::canUploadMedia( array( 'file_path' => '/tmp/a.jpg' ) ) );
		$this->assertTrue( MediaAbilities::canUploadMedia( array( 'url' => self::LIBRARY_IMPORT['url'] ) ) );

		$user->remove_cap( 'datamachine_manage_flows' );
	}

	public function test_upload_media_action_maps_to_upload_files(): void {
		$this->editor();
		$this->assertTrue( PermissionHelper::can( 'upload_media' ) );
		$this->assertFalse( PermissionHelper::can_manage() );
	}

	public function test_agent_token_ceiling_bounds_upload_media(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		PermissionHelper::set_agent_context( 123, $owner_id, array( 'upload_files' ), 456 );
		$this->assertTrue( PermissionHelper::can( 'upload_media' ) );

		PermissionHelper::clear_agent_context();
		PermissionHelper::set_agent_context( 123, $owner_id, array( 'datamachine_chat' ), 456 );
		$this->assertFalse( PermissionHelper::can( 'upload_media' ) );
	}

	public function test_ability_routes_permission_through_input_and_is_mcp_public(): void {
		$ability = wp_get_ability( 'datamachine/upload-media' );
		$this->assertNotNull( $ability );

		$this->editor();
		$this->assertTrue( $ability->check_permissions( self::LIBRARY_IMPORT ) );
		$this->assertNotTrue( $ability->check_permissions( array( 'file_path' => '/tmp/a.jpg' ) ) );

		$meta = $ability->get_meta();
		$this->assertTrue( $meta['show_in_rest'] );
		$this->assertTrue( $meta['mcp']['public'] ?? false );
	}
}
