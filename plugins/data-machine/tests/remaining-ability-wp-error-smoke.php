<?php
/**
 * Smoke coverage for remaining native ability callback failures.
 *
 * Run with: php tests/remaining-ability-wp-error-smoke.php
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private $data;

		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		if ( 'datamachine_source_inventory_page_callback' === $tag && isset( $GLOBALS['source_inventory_page_callback'] ) ) {
			return $GLOBALS['source_inventory_page_callback'];
		}
		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() { return true; }
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) { unset( $domain ); return $text; }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string { return (string) $value; }
}

if ( ! function_exists( 'datamachine_get_valid_log_levels' ) ) {
	function datamachine_get_valid_log_levels(): array { return array( 'info', 'warning', 'error' ); }
}

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	class WP_CLI_Command {}
}

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static function error( string $message ): void { throw new RuntimeException( $message ); }
	}
}

require_once __DIR__ . '/../inc/Abilities/StepTypeAbilities.php';
require_once __DIR__ . '/../inc/Abilities/SourceAggregateAbility.php';
require_once __DIR__ . '/../inc/Core/SourceAggregation/PageableSourceAggregator.php';
require_once __DIR__ . '/../inc/Core/SourceAggregation/SourceInventoryProfiler.php';
require_once __DIR__ . '/../inc/Abilities/SourceInventoryAbility.php';
require_once __DIR__ . '/../inc/Abilities/HandlerAbilities.php';
require_once __DIR__ . '/../inc/Abilities/LogAbilities.php';
require_once __DIR__ . '/../inc/Abilities/Media/ImageGenerationAbilities.php';
require_once __DIR__ . '/../inc/Abilities/Media/ImageTemplateAbilities.php';
require_once __DIR__ . '/../inc/Core/AbilityResult.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/BaseTool.php';
require_once __DIR__ . '/../inc/Cli/BaseCommand.php';
require_once __DIR__ . '/../inc/Cli/Commands/TrackedItemsCommand.php';

use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Abilities\LogAbilities;
use DataMachine\Abilities\Media\ImageGenerationAbilities;
use DataMachine\Abilities\Media\ImageTemplateAbilities;
use DataMachine\Abilities\SourceAggregateAbility;
use DataMachine\Abilities\SourceInventoryAbility;
use DataMachine\Abilities\StepTypeAbilities;
use DataMachine\Core\AbilityResult;
use DataMachine\Cli\Commands\TrackedItemsCommand;
use DataMachine\Engine\AI\Tools\BaseTool;

$failed = 0;
$assert = static function ( string $label, bool $condition ) use ( &$failed ): void {
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}

	++$failed;
	echo "FAIL: {$label}\n";
};

$step_types = ( new ReflectionClass( StepTypeAbilities::class ) )->newInstanceWithoutConstructor();
$step_error = $step_types->executeGetStepTypes( array( 'step_type_slug' => 123 ) );
$assert( 'invalid step type input returns WP_Error', is_wp_error( $step_error ) );
$assert( 'step type error has stable code', 'step_type_slug_invalid' === $step_error->get_error_code() );

$aggregate = ( new ReflectionClass( SourceAggregateAbility::class ) )->newInstanceWithoutConstructor();
$source_error = $aggregate->execute( array( 'source' => array( 'kind' => 'unsupported' ) ) );
$assert( 'missing source executor returns WP_Error', is_wp_error( $source_error ) );
$assert( 'source error has stable code', 'source_aggregate_executor_missing' === $source_error->get_error_code() );

$inventory       = ( new ReflectionClass( SourceInventoryAbility::class ) )->newInstanceWithoutConstructor();
$inventory_error = $inventory->execute( array( 'source' => array( 'kind' => 'unsupported' ), 'scan' => true ) );
$inventory_data  = $inventory_error->get_error_data();
$assert( 'missing inventory executor returns WP_Error', is_wp_error( $inventory_error ) );
$assert( 'missing inventory executor preserves computed profile', is_array( $inventory_data['profile'] ?? null ) && 'unsupported' === $inventory_data['profile']['source_kind'] );

$GLOBALS['source_inventory_page_callback'] = static fn(): WP_Error => new WP_Error( 'upstream_scan_failed', 'Sensitive upstream failure.' );
$scan_error                                 = $inventory->execute( array( 'source' => array( 'kind' => 'remote' ), 'scan' => true ) );
$scan_data                                  = $scan_error->get_error_data();
unset( $GLOBALS['source_inventory_page_callback'] );
$assert( 'inventory callback failure returns WP_Error', is_wp_error( $scan_error ) && 'source_inventory_scan_failed' === $scan_error->get_error_code() );
$assert( 'inventory callback failure preserves computed profile', is_array( $scan_data['profile'] ?? null ) && 'remote' === $scan_data['profile']['source_kind'] );
$assert( 'inventory callback failure preserves upstream error code only', 'upstream_scan_failed' === $scan_data['scan_error_code'] && ! str_contains( json_encode( $scan_data ), 'Sensitive upstream failure.' ) );

$normalized = AbilityResult::normalize( $source_error );
$assert( 'AbilityResult preserves migrated callback failure', false === $normalized['success'] );
$assert( 'AbilityResult preserves native error code', 'source_aggregate_executor_missing' === $normalized['wp_error_code'] );

$handlers = ( new ReflectionClass( HandlerAbilities::class ) )->newInstanceWithoutConstructor();
$handler_error = $handlers->executeGetHandlers( array( 'handler_slug' => 123 ) );
$assert( 'invalid handler input returns WP_Error', is_wp_error( $handler_error ) );
$assert( 'handler error has stable code', 'handler_slug_invalid' === $handler_error->get_error_code() );

$log_error = LogAbilities::write( array( 'level' => 'invalid', 'message' => 'smoke' ) );
$assert( 'invalid log level returns WP_Error', is_wp_error( $log_error ) );
$assert( 'log error has stable code', 'invalid_level' === $log_error->get_error_code() );

$image_error = ImageGenerationAbilities::generateImage( array() );
$assert( 'missing image prompt returns WP_Error', is_wp_error( $image_error ) );
$assert( 'image prompt error has stable code', 'image_prompt_required' === $image_error->get_error_code() );

$template_error = ImageTemplateAbilities::renderTemplate( array() );
$assert( 'missing template ID returns WP_Error', is_wp_error( $template_error ) );
$assert( 'template ID error has stable code', 'template_id_required' === $template_error->get_error_code() );

$tool = new class() extends BaseTool {
	public function initialize(): void { $this->registerConfigurationHandlers( 'smoke_tool' ); }
	protected function get_config_option_name(): string { return 'smoke_tool_config'; }
	protected function validate_and_build_config( array $config_data ): array { unset( $config_data ); return array( 'error' => 'Invalid smoke config.' ); }
};
$tool->initialize();
$tool_error = $tool->save_configuration( null, 'smoke_tool', array() );
$assert( 'first-party tool config producer returns WP_Error', is_wp_error( $tool_error ) );
$assert( 'tool validation error has stable code', 'tool_config_invalid' === $tool_error->get_error_code() );

$cli_error = null;
try {
	$command = new TrackedItemsCommand();
	$method  = new ReflectionMethod( TrackedItemsCommand::class, 'output_result' );
	$method->setAccessible( true );
	$method->invoke( $command, new WP_Error( 'tracked_item_not_found', 'Tracked item was not found.' ), 'table' );
} catch ( RuntimeException $exception ) {
	$cli_error = $exception->getMessage();
}
$assert( 'CLI boundary consumes WP_Error before array access', 'Tracked item was not found.' === $cli_error );

exit( $failed ? 1 : 0 );
