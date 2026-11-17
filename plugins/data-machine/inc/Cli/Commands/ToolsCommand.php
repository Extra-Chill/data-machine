<?php
/**
 * WP-CLI agent tool configuration.
 *
 * @package DataMachine\Cli\Commands
 */

namespace DataMachine\Cli\Commands;

use DataMachine\Cli\BaseCommand;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * List agent tools and manage their credentials.
 */
class ToolsCommand extends BaseCommand {

	/**
	 * List registered agent tools.
	 *
	 * ## OPTIONS
	 *
	 * [--configurable]
	 * : Only include tools that require configuration.
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
	 *     wp datamachine tools list --configurable
	 *     wp datamachine tools list --format=json
	 *
	 * @subcommand list
	 */
	public function list_tools( array $args, array $assoc_args ): void {
		$result = $this->executeAbility( 'datamachine/get-settings', array() );
		if ( null === $result ) {
			return;
		}

		$rows = array();
		foreach ( $result['global_tools'] ?? array() as $id => $tool ) {
			$requires_config = ! empty( $tool['requires_configuration'] );
			if ( isset( $assoc_args['configurable'] ) && ! $requires_config ) {
				continue;
			}
			$rows[] = array(
				'id'              => $id,
				'label'           => $tool['label'] ?? $id,
				'requires_config' => $requires_config,
				'configured'      => ! empty( $tool['is_configured'] ),
			);
		}
		$this->displayRows( $rows, array( 'id', 'label', 'requires_config', 'configured' ), $assoc_args );
	}

	/**
	 * View or save tool configuration. Secrets are masked by default.
	 *
	 * ## OPTIONS
	 *
	 * <tool_id>
	 * : Tool identifier (e.g. exa_search).
	 *
	 * [--<field>=<value>]
	 * : Save a configuration field. Use - to read its value from STDIN.
	 *   Field names accept underscores or hyphens. All - fields use the same input.
	 *
	 * [--show-secrets]
	 * : Reveal full secret values.
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
	 *     wp datamachine tools config exa_search
	 *     echo "$KEY" | wp datamachine tools config exa_search --api_key=-
	 *     wp datamachine tools config exa_search --show-secrets --format=json
	 */
	public function config( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			$this->fail( 'Tool ID is required.' );
			return;
		}

		$tool_id = $args[0];
		// Raw values are requested only for --show-secrets. Otherwise the ability's
		// own masking applies, and the CLI also masks key/token-named fields.
		$result = $this->executeAbility(
			'datamachine/get-tool-config',
			array(
				'tool_id'      => $tool_id,
				'show_secrets' => isset( $assoc_args['show-secrets'] ),
			)
		);
		if ( null === $result ) {
			return;
		}

		$fields = $result['fields'] ?? array();
		$input  = array_diff_key( $assoc_args, array_flip( array( 'show-secrets', 'format' ) ) );
		$config = array();
		foreach ( $input as $key => $value ) {
			$name = array_key_exists( $key, $fields ) ? $key : str_replace( '-', '_', $key );
			if ( ! array_key_exists( $name, $fields ) ) {
				$this->fail( sprintf( 'Unknown configuration field: %s', $key ) );
				return;
			}
			$config[ $name ] = $value;
		}

		if ( ! empty( $config ) ) {
			$stdin = null;
			foreach ( $config as $name => $value ) {
				if ( '-' === $value ) {
					$stdin           = $stdin ?? rtrim( $this->readStdin(), "\r\n" );
					$config[ $name ] = $stdin;
				}
			}
			$saved = $this->executeAbility(
				'datamachine/save-tool-config',
				array( 'tool_id' => $tool_id, 'config_data' => $config )
			);
			if ( null !== $saved ) {
				if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
					$this->emit( wp_json_encode( $saved, JSON_PRETTY_PRINT ) );
				} else {
					$this->succeed( $saved['message'] ?? 'Tool configuration saved.' );
				}
			}
			return;
		}

		$rows = array();
		foreach ( $fields as $name => $field ) {
			$value = $result['config'][ $name ] ?? '';
			$is_set = '' !== $value && null !== $value;
			$secret = in_array( $field['type'] ?? '', array( 'password', 'secret' ), true );
			foreach ( array( 'secret', 'password', 'token', 'key' ) as $pattern ) {
				$secret = $secret || str_contains( strtolower( $name ), $pattern );
			}
			if ( $is_set && $secret && ! isset( $assoc_args['show-secrets'] ) ) {
				$value  = (string) $value;
				$length = strlen( $value );
				$value  = $length <= 4 ? str_repeat( '*', $length ) : str_repeat( '*', $length - 4 ) . substr( $value, -4 );
			}
			$rows[] = array(
				'name'     => $name,
				'label'    => $field['label'] ?? $name,
				'required' => ! empty( $field['required'] ),
				'status'   => $is_set ? 'set' : 'unset',
				'value'    => $value,
			);
		}
		$this->displayRows( $rows, array( 'name', 'label', 'required', 'status', 'value' ), $assoc_args );
	}

	/** Report a fatal CLI error. Overridable so tests need no global WP_CLI double. */
	protected function fail( string $message ): void {
		WP_CLI::error( $message );
	}

	/** Write a line of output. */
	protected function emit( string $message ): void {
		WP_CLI::log( $message );
	}

	/** Report success. */
	protected function succeed( string $message ): void {
		WP_CLI::success( $message );
	}

	/** Read STDIN separately so tests can supply credential input. */
	protected function readStdin(): string {
		$value = stream_get_contents( STDIN );
		if ( false === $value ) {
			$this->fail( 'Unable to read STDIN.' );
			return '';
		}
		return $value;
	}

	/** Execute the registered ability and surface its errors without saving directly. */
	protected function executeAbility( string $name, array $input ): ?array {
		$ability = wp_get_ability( $name );
		if ( ! $ability ) {
			$this->fail( sprintf( 'Ability not available: %s', $name ) );
			return null;
		}
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message() );
			return null;
		}
		if ( empty( $result['success'] ) ) {
			$this->fail( $result['error'] ?? 'Tool operation failed.' );
			return null;
		}
		return $result;
	}

	/** Keep empty JSON lists machine-readable too. */
	private function displayRows( array $rows, array $fields, array $assoc_args ): void {
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			$this->emit( wp_json_encode( $rows, JSON_PRETTY_PRINT ) );
			return;
		}
		$this->format_items( $rows, $fields, $assoc_args );
	}
}
