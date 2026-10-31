<?php
/**
 * Invoice CLI commands.
 *
 * @package DataMachineBusiness
 */

// phpcs:disable WordPress.Files.FileName -- PSR-4 autoloading matches the plugin's class layout.

namespace DataMachineBusiness\Cli\Commands;

defined( 'ABSPATH' ) || exit;

/** Create invoice previews or send invoice emails. */
class InvoiceCommand {

	/**
	 * Create an HTML email invoice.
	 *
	 * ## OPTIONS
	 *
	 * --data=<json>
	 * : Invoice input as a JSON object.
	 *
	 * [--send]
	 * : Send the invoice instead of only previewing it.
	 *
	 * [--preview-file=<path>]
	 * : Write the rendered HTML to this path (also supported with --send).
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function create( array $args, array $assoc_args ): void {
		$input = json_decode( $assoc_args['data'] ?? '', true );
		if ( ! is_array( $input ) || ! str_starts_with( ltrim( $assoc_args['data'] ?? '' ), '{' ) || JSON_ERROR_NONE !== json_last_error() ) {
			\WP_CLI::error( '--data must be a JSON object.' );
		}
		$input['send'] = isset( $assoc_args['send'] );
		$ability       = wp_get_ability( 'datamachine/create-invoice' );
		if ( ! $ability ) {
			\WP_CLI::error( 'The create-invoice ability is unavailable.' );
		}
		$html    = null;
		$capture = static function ( array $templates ) use ( &$html ): array {
			$renderer             = $templates['invoice'];
			$templates['invoice'] = static function ( array $context ) use ( $renderer, &$html ): string {
				$html = call_user_func( $renderer, $context );
				return $html;
			};
			return $templates;
		};
		add_filter( 'datamachine_email_templates', $capture, PHP_INT_MAX );
		try {
			$result = $ability->execute( $input );
		} finally {
			remove_filter( 'datamachine_email_templates', $capture, PHP_INT_MAX );
		}
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		$html = $result['html'] ?? $html;
		if ( isset( $assoc_args['preview-file'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Explicit CLI output path.
			if ( null === $html || false === file_put_contents( $assoc_args['preview-file'], $html ) ) {
				\WP_CLI::error( 'Unable to write invoice HTML. Check the send result before retrying delivery.' );
			}
		}
		\WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}
