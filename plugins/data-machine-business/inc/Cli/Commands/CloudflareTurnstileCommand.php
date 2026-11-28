<?php
/**
 * Cloudflare Turnstile WP-CLI command.
 *
 * Thin wrapper around the datamachine/cloudflare-turnstile-* abilities. All
 * business logic (config resolution, read-modify-write, validation) lives in
 * CloudflareTurnstileAbilities; this command only handles CLI args and
 * output formatting.
 *
 * @package DataMachineBusiness\Cli\Commands
 */

namespace DataMachineBusiness\Cli\Commands;

use DataMachine\Cli\BaseCommand;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

class CloudflareTurnstileCommand extends BaseCommand {

	/**
	 * List all Cloudflare Turnstile widgets on the configured account.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine cloudflare turnstile list-widgets
	 *
	 * @subcommand list-widgets
	 * @when after_wp_load
	 */
	public function list_widgets( array $args, array $assoc_args ): void {
		$result = $this->execute_ability( 'datamachine/cloudflare-turnstile-list-widgets', array() );

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$widgets = is_array( $result['widgets'] ?? null ) ? $result['widgets'] : array();
		if ( empty( $widgets ) ) {
			WP_CLI::success( 'No widgets found.' );
			return;
		}

		$flat = array_map( array( $this, 'flatten' ), $widgets );
		$this->format_items( $flat, array_keys( reset( $flat ) ), $assoc_args );
	}

	/**
	 * Get one Cloudflare Turnstile widget by sitekey.
	 *
	 * ## OPTIONS
	 *
	 * <sitekey>
	 * : The Turnstile widget sitekey (public; rendered in page HTML).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine cloudflare turnstile get-widget 0x4AAAAAAAExampleSiteKey
	 *
	 * @subcommand get-widget
	 * @when after_wp_load
	 */
	public function get_widget( array $args, array $assoc_args ): void {
		$sitekey = $args[0] ?? '';
		if ( '' === $sitekey ) {
			WP_CLI::error( 'A sitekey is required.' );
			return;
		}

		$result = $this->execute_ability( 'datamachine/cloudflare-turnstile-get-widget', array( 'sitekey' => $sitekey ) );

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$widget = is_array( $result['widget'] ?? null ) ? $result['widget'] : array();
		if ( empty( $widget ) ) {
			WP_CLI::success( 'No widget data.' );
			return;
		}

		$flat = $this->flatten( $widget );
		$this->format_items( array( $flat ), array_keys( $flat ), $assoc_args );
	}

	/**
	 * Add and/or remove hostnames from a Turnstile widget's allowed domains.
	 *
	 * Read-modify-write: preserves the widget's name, mode, and every
	 * existing domain (Cloudflare's PUT replaces the whole config). Dry-run
	 * by default; pass --apply to write the change. Idempotent — adding a
	 * hostname that is already present, or removing one that is already
	 * absent, is reported as a no-op and never contacts Cloudflare.
	 *
	 * ## OPTIONS
	 *
	 * <sitekey>
	 * : The Turnstile widget sitekey to update.
	 *
	 * [--add=<hostnames>]
	 * : Comma-separated hostnames to add.
	 *
	 * [--remove=<hostnames>]
	 * : Comma-separated hostnames to remove.
	 *
	 * [--apply]
	 * : Write the change to Cloudflare. Without this flag, reports what would change.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview adding example.net
	 *     wp datamachine cloudflare turnstile update-domains 0x4AAAAAAAExampleSiteKey --add=example.net
	 *
	 *     # Actually apply it
	 *     wp datamachine cloudflare turnstile update-domains 0x4AAAAAAAExampleSiteKey --add=example.net --apply
	 *
	 *     # Remove a stale hostname
	 *     wp datamachine cloudflare turnstile update-domains 0x4AAAAAAAExampleSiteKey --remove=old.example.com --apply
	 *
	 * @subcommand update-domains
	 * @when after_wp_load
	 */
	public function update_domains( array $args, array $assoc_args ): void {
		$sitekey = $args[0] ?? '';
		if ( '' === $sitekey ) {
			WP_CLI::error( 'A sitekey is required.' );
			return;
		}

		$input = array(
			'sitekey' => $sitekey,
			'add'     => $this->split_hostnames( (string) ( $assoc_args['add'] ?? '' ) ),
			'remove'  => $this->split_hostnames( (string) ( $assoc_args['remove'] ?? '' ) ),
			'apply'   => ! empty( $assoc_args['apply'] ),
		);

		$result = $this->execute_ability( 'datamachine/cloudflare-turnstile-update-widget-domains', $input );

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( ! empty( $result['no_op'] ) ) {
			WP_CLI::success( $result['message'] ?? 'No changes needed.' );
		} elseif ( ! empty( $result['dry_run'] ) ) {
			WP_CLI::log( WP_CLI::colorize( '%YDRY RUN%n ' . ( $result['message'] ?? '' ) ) );
		} else {
			WP_CLI::success( $result['message'] ?? 'Updated.' );
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Sitekey: ' . ( $result['sitekey'] ?? $sitekey ) );
		WP_CLI::log( 'Name:    ' . ( $result['name'] ?? '' ) );
		WP_CLI::log( 'Mode:    ' . ( $result['mode'] ?? '' ) );
		WP_CLI::log( 'Added:   ' . ( empty( $result['added'] ) ? '(none)' : implode( ', ', $result['added'] ) ) );
		WP_CLI::log( 'Removed: ' . ( empty( $result['removed'] ) ? '(none)' : implode( ', ', $result['removed'] ) ) );
		WP_CLI::log( 'Domains: ' . implode( ', ', $result['domains_after'] ?? array() ) );
	}

	/**
	 * Split a comma-separated CLI value into a trimmed hostname list.
	 *
	 * @param string $value Raw comma-separated value.
	 * @return string[]
	 */
	private function split_hostnames( string $value ): array {
		if ( '' === trim( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
	}

	/**
	 * Execute an ability and error out on failure.
	 *
	 * @param string               $slug  Ability slug.
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private function execute_ability( string $slug, array $input ): array {
		$ability = wp_get_ability( $slug );

		if ( ! $ability ) {
			WP_CLI::error( "Ability '{$slug}' not registered. Ensure Data Machine Business is active and WordPress 6.9+." );
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error.' );
		}

		return $result;
	}

	/**
	 * Flatten a widget row for table display.
	 *
	 * @param array<string,mixed> $item Widget row.
	 * @return array<string,mixed>
	 */
	private function flatten( array $item ): array {
		$flat = array();
		foreach ( $item as $key => $value ) {
			$flat[ $key ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : $value;
		}
		return $flat;
	}
}
