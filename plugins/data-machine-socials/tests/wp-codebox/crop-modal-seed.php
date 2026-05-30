<?php
/**
 * Data Machine Socials crop-modal WP Codebox smoke-test seed.
 *
 * Goal: prove the data-machine-socials editor crop modal (react-easy-crop)
 * still WORKS under WordPress trunk / React 19 when driven by the
 * `wordpress.browser-actions` interaction probe.
 *
 * What this seed does
 * -------------------
 * 1. Activates the mounted data-machine-socials build (mounted by the recipe at
 *    /wordpress/wp-content/plugins/data-machine-socials).
 * 2. Seeds a real, same-origin image attachment so the cropper has genuine
 *    pixels to draw into a <canvas> (react-easy-crop renders against an
 *    <img> it loads from the attachment URL).
 * 3. Creates a draft post and sets that attachment as its featured image, so
 *    the socials sidebar's ImageSelector surfaces it via dmsData.featuredImage.
 * 4. Installs a tiny mu-plugin auth STUB for the one REST route the socials
 *    editor needs to leave its "No social media accounts connected" gate:
 *    GET /datamachine/v1/socials/auth/status. On production that route is
 *    served by Data Machine *core* (DataMachine\Abilities\AuthAbilities), which
 *    the socials plugin hard-depends on (`Requires Plugins: data-machine`) and
 *    which only registers its REST surface when core is active. We deliberately
 *    DO NOT boot Data Machine core (ActionScheduler + agents-api + the full
 *    pipeline engine) for this smoke test — that machinery is unrelated to
 *    whether the *cropper component* survives React 19. Instead we stub the
 *    single gating endpoint to report Instagram as authenticated. Everything
 *    downstream of that gate — PlatformSelector, ImageSelector, ImageCropper,
 *    and the real bundled react-easy-crop — is the genuine production code
 *    under test.
 * 5. Dismisses the Gutenberg welcome guide so the editor canvas is interactive
 *    immediately.
 *
 * Output: JSON describing the seeded post, attachment, the editor URL the probe
 * should navigate to, and which dependency was stubbed (for auditability).
 */

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

/*
 * 1. Locate the mounted data-machine-socials build.
 *
 * On WordPress 6.5+/trunk, core enforces the `Requires Plugins: data-machine`
 * header at ACTIVATION time, so activate_plugin() fails without Data Machine
 * core present. We deliberately do not boot core (see the auth-stub note
 * below). Instead, the mu-plugin harness written in step 4 enqueues the real
 * built editor bundle directly from the mounted plugin path — the exact same
 * index.js + dmsData contract the plugin's own admin_enqueue_scripts hook uses.
 * That keeps the *component under test* (the bundled react-easy-crop cropper)
 * genuinely real while bypassing only the activation-gate ceremony.
 */
$socials_plugin = null;
foreach ( get_plugins( '/data-machine-socials' ) as $plugin_file => $plugin_data ) {
	if ( ! empty( $plugin_data['Name'] ) ) {
		$socials_plugin = 'data-machine-socials/' . $plugin_file;
		break;
	}
}

$socials_build_url  = plugins_url( 'data-machine-socials/build/index.js' );
$socials_build_path = WP_PLUGIN_DIR . '/data-machine-socials/build/index.js';
$socials_asset_path = WP_PLUGIN_DIR . '/data-machine-socials/build/index.asset.php';

/*
 * 2. Seed a real same-origin image attachment.
 *
 * Generate a simple gradient PNG with GD (always available in Playground) and
 * import it into the media library so the cropper loads a real, same-origin
 * <img> (no canvas cross-origin taint, no remote fetch flakiness).
 */
$upload_dir = wp_upload_dir();
$image_path = trailingslashit( $upload_dir['path'] ) . 'dms-crop-modal-smoke.png';

$width  = 1200;
$height = 1200;
$im     = imagecreatetruecolor( $width, $height );

for ( $y = 0; $y < $height; $y++ ) {
	$ratio = $y / $height;
	$r     = (int) ( 40 + 180 * $ratio );
	$g     = (int) ( 90 + 120 * ( 1 - $ratio ) );
	$b     = (int) ( 200 - 120 * $ratio );
	$color = imagecolorallocate( $im, $r, $g, $b );
	imageline( $im, 0, $y, $width, $y, $color );
}

// Draw some high-contrast marks so a crop is visually obvious in screenshots.
$white = imagecolorallocate( $im, 255, 255, 255 );
imagefilledrectangle( $im, 80, 80, 320, 320, $white );
imagefilledellipse( $im, 900, 900, 360, 360, $white );

imagepng( $im, $image_path );
imagedestroy( $im );

$attachment_id = 0;
if ( file_exists( $image_path ) ) {
	$filetype   = wp_check_filetype( basename( $image_path ), null );
	$attachment = array(
		'guid'           => trailingslashit( $upload_dir['url'] ) . basename( $image_path ),
		'post_mime_type' => $filetype['type'],
		'post_title'     => 'DMS Crop Modal Smoke Image',
		'post_content'   => '',
		'post_status'    => 'inherit',
	);

	$attachment_id = wp_insert_attachment( $attachment, $image_path );
	if ( ! is_wp_error( $attachment_id ) && $attachment_id ) {
		$attach_data = wp_generate_attachment_metadata( $attachment_id, $image_path );
		wp_update_attachment_metadata( $attachment_id, $attach_data );
	}
}

/*
 * 3. Create a draft post and attach the featured image.
 *
 * The post ID is pinned via import_id so the recipe's browser-actions step can
 * navigate to a deterministic editor URL (/wp-admin/post.php?post=4321&...).
 */
$pinned_post_id = 4321;
$post_id        = wp_insert_post( array(
	'import_id'    => $pinned_post_id,
	'post_title'   => 'DMS Crop Modal Smoke Post',
	'post_content' => '<!-- wp:paragraph --><p>Seeded post for exercising the data-machine-socials crop modal under React 19.</p><!-- /wp:paragraph -->',
	'post_status'  => 'draft',
	'post_type'    => 'post',
	'post_author'  => 1,
) );

if ( is_wp_error( $post_id ) || ! $post_id ) {
	throw new RuntimeException( 'Failed to create smoke post' );
}

if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
	set_post_thumbnail( $post_id, $attachment_id );
}

/*
 * 4. Auth STUB: serve /datamachine/v1/socials/auth/status reporting Instagram
 *    authenticated, so the editor leaves its "no accounts connected" gate and
 *    the cropper trigger chain (platform -> image -> needsCropping) is reachable.
 *
 *    This is the single documented dependency stub. It is mu-plugin code written
 *    to the live sandbox so it survives the page navigation the probe performs.
 *    Instagram is chosen because its aspectRatios are NOT ['any'], which is what
 *    flips SocialEditor's `needsCropping` true and opens the modal.
 */
$mu_dir = WPMU_PLUGIN_DIR;
if ( ! is_dir( $mu_dir ) ) {
	wp_mkdir_p( $mu_dir );
}

// Read the real bundle's dependency/version metadata so the mu-plugin enqueues
// it exactly as the plugin would (correct wp-* script handles + cache version).
$socials_asset = file_exists( $socials_asset_path ) ? ( require $socials_asset_path ) : array(
	'dependencies' => array( 'wp-element', 'wp-components', 'wp-edit-post', 'wp-plugins', 'wp-api-fetch', 'wp-i18n' ),
	'version'      => '0',
);
$asset_export = var_export(
	array(
		'dependencies' => $socials_asset['dependencies'],
		'version'      => (string) $socials_asset['version'],
		'src'          => $socials_build_url,
		'style'        => plugins_url( 'data-machine-socials/build/style-index.css' ),
		'post_id'      => (int) $pinned_post_id,
		'featured'     => $attachment_id ? array(
			'id'     => (int) $attachment_id,
			'url'    => wp_get_attachment_url( $attachment_id ),
			'width'  => $width,
			'height' => $height,
		) : null,
	),
	true
);

$stub_code = <<<PHP
<?php
/**
 * Plugin Name: DMS Crop Modal Smoke Harness (cookbook smoke test)
 * Description: Loads the REAL data-machine-socials editor bundle and stubs the
 *              single Data Machine core REST route the editor needs to render
 *              past its "no accounts connected" gate, so the crop modal can be
 *              exercised under WordPress trunk / React 19 without booting Data
 *              Machine core.
 *
 * This is a test harness, NOT production code. It deliberately:
 *   - Enqueues the real built socials bundle (the cropper under test) on the
 *     block editor, mirroring the plugin's own admin_enqueue_scripts contract.
 *   - Hard-codes Instagram as an authenticated provider purely so the editor's
 *     gating chain (platform -> image -> needsCropping) is reachable.
 */

\$dms_smoke = $asset_export;

// Serve the gating REST route Data Machine core would normally provide.
//
// The editor requests this via apiFetch at /datamachine/v1/socials/auth/status
// (correct, single REST-root prefix). The earlier double-/wp-json/ harness shim
// for #145 is no longer needed now that REST_BASE in src/utils/api.ts is fixed.
\$dms_auth_status_callback = function () {
	return new WP_REST_Response( array(
		array(
			'platform'      => 'instagram',
			'authenticated' => true,
			'username'      => 'smoke-test',
		),
	) );
};
\$dms_auth_permission = function () {
	return current_user_can( 'edit_posts' );
};
add_action( 'rest_api_init', function () use ( \$dms_auth_status_callback, \$dms_auth_permission ) {
	register_rest_route( 'datamachine/v1', '/socials/auth/status', array(
		'methods'             => 'GET',
		'permission_callback' => \$dms_auth_permission,
		'callback'            => \$dms_auth_status_callback,
	) );
}, 5 );

// Enqueue the real editor bundle on the block editor for the seeded post.
add_action( 'enqueue_block_editor_assets', function () use ( \$dms_smoke ) {
	if ( empty( \$dms_smoke['src'] ) ) {
		return;
	}

	wp_enqueue_script(
		'data-machine-socials-editor',
		\$dms_smoke['src'],
		\$dms_smoke['dependencies'],
		\$dms_smoke['version'],
		true
	);

	if ( ! empty( \$dms_smoke['style'] ) ) {
		wp_enqueue_style(
			'data-machine-socials-editor',
			\$dms_smoke['style'],
			array(),
			\$dms_smoke['version']
		);
	}

	wp_localize_script(
		'data-machine-socials-editor',
		'dmsData',
		array(
			'postId'        => \$dms_smoke['post_id'],
			'restNonce'     => wp_create_nonce( 'wp_rest' ),
			'featuredImage' => \$dms_smoke['featured'],
		)
	);
} );
PHP;

$stub_path    = trailingslashit( $mu_dir ) . 'dms-crop-modal-smoke-harness.php';
$stub_written = (bool) file_put_contents( $stub_path, $stub_code );

/*
 * 5. Dismiss the Gutenberg welcome guide for the admin user so the canvas is
 *    interactive immediately (no modal intercepting the first clicks).
 */
$prefs = get_user_meta( 1, 'wp_persisted_preferences', true );
if ( ! is_array( $prefs ) ) {
	$prefs = array();
}
$prefs['core/edit-post'] = array_merge(
	isset( $prefs['core/edit-post'] ) && is_array( $prefs['core/edit-post'] ) ? $prefs['core/edit-post'] : array(),
	array( 'welcomeGuide' => false, 'fullscreenMode' => false )
);
$prefs['core/preferences'] = array_merge(
	isset( $prefs['core/preferences'] ) && is_array( $prefs['core/preferences'] ) ? $prefs['core/preferences'] : array(),
	array( 'welcomeGuide' => false )
);
$prefs['_modified'] = gmdate( 'c' );
update_user_meta( 1, 'wp_persisted_preferences', $prefs );

// Pretty permalinks (belt-and-suspenders for any URL resolution).
update_option( 'permalink_structure', '/%postname%/' );
global $wp_rewrite;
$wp_rewrite->init();
$wp_rewrite->flush_rules( false );

// Auto-login admin so the editor and the stubbed REST route are authorized.
wp_set_auth_cookie( 1, true );

echo wp_json_encode( array(
	'post_id'             => (int) $post_id,
	'attachment_id'       => (int) $attachment_id,
	'attachment_url'      => $attachment_id ? wp_get_attachment_url( $attachment_id ) : null,
	'editor_url'          => admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' ),
	'editor_url_relative' => '/wp-admin/post.php?post=' . (int) $post_id . '&action=edit',
	'socials_plugin'      => $socials_plugin,
	'socials_bundle_url'  => $socials_build_url,
	'socials_bundle_exists' => file_exists( $socials_build_path ),
	'harness_written'     => $stub_written,
	'harness_path'        => $stub_path,
	'stubbed_dependency'  => 'GET /datamachine/v1/socials/auth/status (normally served by Data Machine core AuthAbilities). The editor BUNDLE itself is the real built artifact.',
	'admin_url'           => admin_url(),
	'home_url'            => home_url( '/' ),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
echo "\n";
