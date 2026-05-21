<?php
/**
 * Pure-PHP smoke test for Amazon affiliate tool registration and config adoption.
 *
 * Run with: php tests/amazon-affiliate-tool-smoke.php
 *
 * @package DataMachineBusiness\Tests
 */

namespace DataMachine\Engine\AI\Tools {
	abstract class BaseTool {
		protected string $config_tool_id = '';

		protected function registerTool( string $toolName, array|callable $toolDefinition, array $modes = array(), array $meta = array() ): void {
			\add_filter(
				'datamachine_tools',
				function ( $tools ) use ( $toolName, $toolDefinition, $modes, $meta ) {
					$tools[ $toolName ] = array(
						'_callable'       => $toolDefinition,
						'modes'           => $modes,
						'requires_opt_in' => ! empty( $meta['requires_opt_in'] ),
					);

					if ( ! empty( $meta['access_level'] ) ) {
						$tools[ $toolName ]['access_level'] = $meta['access_level'];
					}

					return $tools;
				}
			);
		}

		protected function registerConfigurationHandlers( string $tool_id ): void {
			$this->config_tool_id = $tool_id;
			\add_filter( 'datamachine_tool_configured', array( $this, 'check_configuration' ), 10, 2 );
			\add_filter( 'datamachine_get_tool_config', array( $this, 'get_configuration' ), 10, 2 );
			\add_filter( 'datamachine_get_tool_config_fields', array( $this, 'get_config_fields' ), 10, 2 );
			\add_filter( 'datamachine_save_tool_config', array( $this, 'save_configuration' ), 10, 3 );
		}

		public function save_configuration( $result, $tool_id, $config_data ) {
			if ( $this->config_tool_id !== $tool_id ) {
				return $result;
			}

			$validated = $this->validate_and_build_config( $config_data );
			if ( isset( $validated['error'] ) ) {
				return array( 'success' => false );
			}

			$this->before_config_save( $config_data );
			\update_site_option( $this->get_config_option_name(), $validated['config'] );

			return array( 'success' => true );
		}

		protected function get_config_option_name(): string {
			return '';
		}

		protected function validate_and_build_config( array $config_data ): array {
			return array( 'config' => $config_data );
		}

		protected function before_config_save( array $config_data ): void {}

		protected function buildErrorResponse( string $message, string $tool_name ): array {
			return array(
				'success'   => false,
				'error'     => $message,
				'tool_name' => $tool_name,
			);
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$test_filters = array();
	$test_options = array();
	$test_deleted_transients = array();

	function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		global $test_filters;
		$test_filters[ $tag ][ $priority ][] = $callback;
	}

	function apply_filters( string $tag, $value, ...$args ) {
		global $test_filters;
		if ( empty( $test_filters[ $tag ] ) ) {
			return $value;
		}

		ksort( $test_filters[ $tag ] );
		foreach ( $test_filters[ $tag ] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$value = $callback( $value, ...$args );
			}
		}

		return $value;
	}

	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}

	function sanitize_text_field( $value ): string {
		return trim( (string) $value );
	}

	function get_site_option( string $option, $default = false ) {
		global $test_options;
		return $test_options[ $option ] ?? $default;
	}

	function update_site_option( string $option, $value ): bool {
		global $test_options;
		$test_options[ $option ] = $value;
		return true;
	}

	function get_transient( string $transient ) {
		return false;
	}

	function set_transient( string $transient, $value, int $expiration = 0 ): bool {
		return true;
	}

	function delete_transient( string $transient ): bool {
		global $test_deleted_transients;
		$test_deleted_transients[] = $transient;
		return true;
	}

	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	function do_action( string $tag, ...$args ): void {}

	require_once __DIR__ . '/../inc/Tools/AmazonAffiliateLink.php';

	$failures = array();
	$passes   = 0;

	function assert_true_for_amazon_tool( bool $condition, string $name, array &$failures, int &$passes ): void {
		if ( $condition ) {
			$passes++;
			echo "  OK {$name}\n";
			return;
		}

		$failures[] = $name;
		echo "  FAIL {$name}\n";
	}

	echo "Amazon affiliate tool smoke\n";
	echo "---------------------------\n";

	new \DataMachineBusiness\Tools\AmazonAffiliateLink();

	$tools = apply_filters( 'datamachine_tools', array() );
	assert_true_for_amazon_tool( isset( $tools['amazon_affiliate_link'] ), 'tool registers with Data Machine registry', $failures, $passes );
	assert_true_for_amazon_tool( in_array( 'chat', $tools['amazon_affiliate_link']['modes'] ?? array(), true ), 'tool is chat-visible', $failures, $passes );
	assert_true_for_amazon_tool( in_array( 'pipeline', $tools['amazon_affiliate_link']['modes'] ?? array(), true ), 'tool is pipeline-visible', $failures, $passes );
	assert_true_for_amazon_tool( 'admin' === ( $tools['amazon_affiliate_link']['access_level'] ?? '' ), 'tool remains admin-only', $failures, $passes );

	$config = array(
		'client_id'     => 'credential-id',
		'client_secret' => 'credential-secret',
		'partner_tag'   => 'example-20',
		'marketplace'   => 'www.amazon.com',
	);

	$result = apply_filters( 'datamachine_save_tool_config', null, 'amazon_affiliate_link', $config );
	assert_true_for_amazon_tool( true === ( $result['success'] ?? false ), 'configuration saves successfully', $failures, $passes );
	assert_true_for_amazon_tool( $config === get_site_option( 'datamachine_amazon_config' ), 'configuration uses former core option key', $failures, $passes );
	assert_true_for_amazon_tool( in_array( 'datamachine_amazon_access_token', $test_deleted_transients, true ), 'configuration clears former core token transient', $failures, $passes );
	assert_true_for_amazon_tool( true === apply_filters( 'datamachine_tool_configured', false, 'amazon_affiliate_link' ), 'configuration status reads adopted option', $failures, $passes );

	$fields = apply_filters( 'datamachine_get_tool_config_fields', array(), 'amazon_affiliate_link' );
	assert_true_for_amazon_tool( isset( $fields['client_id'], $fields['client_secret'], $fields['partner_tag'], $fields['marketplace'] ), 'settings fields are registered', $failures, $passes );

	if ( ! empty( $failures ) ) {
		echo "\nFAILURES:\n";
		foreach ( $failures as $failure ) {
			echo " - {$failure}\n";
		}
		exit( 1 );
	}

	echo "\n{$passes} assertions passed.\n";
}
