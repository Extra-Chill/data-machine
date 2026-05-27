<?php
/**
 * Artist URL Import REST Controller
 *
 * Thin wrappers around the four ArtistUrlImportAbilities. Each route is
 * just `wp_get_ability( ... )->execute( $input )` — business logic
 * lives in the ability, not here.
 *
 * Direct-navigation guard: preview + submit endpoints inspect the
 * Accept / X-Requested-With headers and refuse browser address-bar
 * requests, returning a 404 so a curious user pasting the URL doesn't
 * see raw JSON. (Same defensive shape #297 calls for on the calendar
 * REST endpoints — the prompt names this "BrowserNavigationGuard"; we
 * implement it inline since no shared class exists in the tree yet.)
 *
 * @package DataMachineEvents\Api\Controllers
 * @since   0.40.0
 */

namespace DataMachineEvents\Api\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ArtistUrlImport {

	/**
	 * Returns true when the request looks like an XHR/JSON client (the
	 * site's own JS), false when it looks like a direct browser nav.
	 *
	 * Accept header containing `application/json` OR an
	 * `X-Requested-With: XMLHttpRequest` header is enough to pass.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool
	 */
	public static function looks_like_xhr( \WP_REST_Request $request ): bool {
		$xrw = $request->get_header( 'x_requested_with' );
		if ( '' !== (string) $xrw && stripos( (string) $xrw, 'xmlhttprequest' ) !== false ) {
			return true;
		}

		$accept = (string) $request->get_header( 'accept' );
		if ( '' !== $accept && stripos( $accept, 'application/json' ) !== false ) {
			return true;
		}

		// POST with a JSON content-type is also fine.
		$ct = (string) $request->get_header( 'content_type' );
		if ( '' !== $ct && stripos( $ct, 'application/json' ) !== false ) {
			return true;
		}

		return false;
	}

	/**
	 * Build a 404-flavored WP_Error for direct-browser navigations.
	 *
	 * @return \WP_Error
	 */
	private static function direct_nav_404(): \WP_Error {
		return new \WP_Error(
			'rest_no_route',
			__( 'No route was found matching the URL and request method.', 'data-machine-events' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Wrap an ability invocation in a REST response. Forwards WP_Errors,
	 * including their `status` field from the ability layer.
	 *
	 * @param string $ability_name
	 * @param array  $input
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function run_ability( string $ability_name, array $input ) {
		$ability = wp_get_ability( $ability_name );
		if ( ! $ability ) {
			return new \WP_Error(
				'ability_missing',
				sprintf(
					/* translators: %s: ability name */
					__( 'Ability %s is not registered.', 'data-machine-events' ),
					$ability_name
				),
				array( 'status' => 500 )
			);
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}

	// ────────────────────────────────────────────────────────────────────
	// Route callbacks
	// ────────────────────────────────────────────────────────────────────

	public function preview( \WP_REST_Request $request ) {
		if ( ! self::looks_like_xhr( $request ) ) {
			return self::direct_nav_404();
		}

		return self::run_ability(
			'data-machine-events/preview-artist-url',
			array( 'url' => (string) $request->get_param( 'url' ) )
		);
	}

	public function submit( \WP_REST_Request $request ) {
		if ( ! self::looks_like_xhr( $request ) ) {
			return self::direct_nav_404();
		}

		return self::run_ability(
			'data-machine-events/submit-artist-url',
			array(
				'url'           => (string) $request->get_param( 'url' ),
				'contact_email' => (string) $request->get_param( 'contact_email' ),
				'contact_name'  => (string) $request->get_param( 'contact_name' ),
			)
		);
	}

	public function approve( \WP_REST_Request $request ) {
		return self::run_ability(
			'data-machine-events/approve-artist-url-submission',
			array(
				'submission_id'     => (int) $request->get_param( 'id' ),
				'artist_term_id'    => (int) $request->get_param( 'artist_term_id' ),
				'artist_name'       => (string) $request->get_param( 'artist_name' ),
				'schedule_interval' => (string) $request->get_param( 'schedule_interval' ),
			)
		);
	}

	public function reject( \WP_REST_Request $request ) {
		return self::run_ability(
			'data-machine-events/reject-artist-url-submission',
			array(
				'submission_id' => (int) $request->get_param( 'id' ),
				'reason'        => (string) $request->get_param( 'reason' ),
			)
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// Permission callbacks
	// ────────────────────────────────────────────────────────────────────

	public static function permission_logged_in(): bool {
		return is_user_logged_in();
	}

	public static function permission_admin(): bool {
		return current_user_can( 'manage_options' );
	}
}
