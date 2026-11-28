<?php
/** Canonical ability result and REST presentation smoke. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( private string $code, private string $message, private $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
	public function __construct( public $data ) {}
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function rest_ensure_response( $value ) { return new WP_REST_Response( $value ); }
function __( string $message, string $domain = '' ): string { unset( $domain ); return $message; }
function wp_get_ability( string $slug ) { return $GLOBALS['presentation_abilities'][ $slug ] ?? null; }

require_once dirname( __DIR__ ) . '/inc/Core/AbilityResult.php';
require_once dirname( __DIR__ ) . '/inc/Api/RestResultSpec.php';
require_once dirname( __DIR__ ) . '/inc/Api/RestAbilityExecutor.php';

use DataMachine\Api\RestAbilityExecutor;
use DataMachine\Api\RestResultSpec;
use DataMachine\Core\AbilityResult;

$failures = 0;
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) { ++$failures; fwrite( STDERR, "FAIL: {$message}\n" ); }
};

$error = new WP_Error( 'forbidden', 'Denied.', array( 'status' => 403 ) );
$normalized = AbilityResult::normalize( $error );
$assert( false === $normalized['success'] && 'forbidden' === $normalized['wp_error_code'] && 403 === $normalized['wp_error_data']['status'], 'normalize presents WP_Error diagnostics' );
$assert( array( 'success' => true, 'data' => 'scalar' ) === AbilityResult::normalize( 'scalar' ), 'normalize wraps scalar success' );
$assert( array( 'success' => true, 'id' => 7 ) === AbilityResult::normalize( array( 'success' => true, 'id' => 7 ) ), 'normalize preserves array payload' );

$tool = AbilityResult::normalize_tool_envelope( array( 'value' => 7 ), 'demo', array( 'source' => 'test' ) );
$assert( true === $tool['success'] && 'demo' === $tool['tool_name'] && 7 === $tool['result']['value'] && 'test' === $tool['metadata']['source'], 'tool envelope fills canonical fields' );
$failed_tool = AbilityResult::normalize_tool_envelope( array( 'success' => false, 'error' => 'no' ), 'demo' );
$assert( ! isset( $failed_tool['result'] ), 'tool envelope does not recast failures as results' );

$collection = AbilityResult::collection_envelope(
	array( 'items' => array( array( 'id' => 7 ) ), 'per_page' => 20, 'filters_applied' => array( 'status' ) ),
	'items',
	array( 'data_key' => 'rows', 'data_extra' => array( 'scope' => 'mine' ), 'top_extra' => array( 'filters_applied' ) )
);
$assert( 1 === $collection['total'] && 7 === $collection['data']['rows'][0]['id'] && 'mine' === $collection['data']['scope'], 'collection envelope applies data and inferred total' );
$assert( $error === AbilityResult::rest_collection_response( $error, 'items' ), 'REST collection preserves canonical WP_Error' );
$rest_collection = AbilityResult::rest_collection_response( array( 'items' => array( 1, 2 ), 'total' => 8 ), 'items' );
$assert( $rest_collection instanceof WP_REST_Response && 8 === $rest_collection->data['total'], 'REST collection presents success response' );
$assert( array( array( 'id' => 1 ) ) === AbilityResult::cli_collection_payload( array( array( 'id' => 1 ) ), array(), 'items' ), 'CLI collection defaults to rows' );
$cli_envelope = AbilityResult::cli_collection_payload( array( array( 'id' => 1 ) ), array( 'filters_applied' => array( 'agent' ) ), 'items', true );
$assert( 1 === $cli_envelope['total'] && array( 'agent' ) === $cli_envelope['filters_applied'], 'CLI collection supports explicit envelope' );
$assert( $error === AbilityResult::rest_item_response( $error ), 'REST item preserves canonical WP_Error' );
$rest_item = AbilityResult::rest_item_response( array( 'id' => 7 ), array( 'id' => 7 ), array( 'message' => 'ok' ) );
$assert( $rest_item instanceof WP_REST_Response && 7 === $rest_item->data['data']['id'] && 'ok' === $rest_item->data['message'], 'REST item presents data and extras' );

$legacy = RestResultSpec::legacy_error( $error, 'rest_forbidden' );
$assert( 'rest_forbidden' === $legacy->get_error_code() && 'forbidden' === $legacy->get_error_data()['ability_error_code'], 'legacy REST code retains native ability code' );
$spec = RestResultSpec::item(
	static fn( array $result ): array => array( 'id' => $result['id'] ),
	static fn( array $result ): array => array( 'message' => $result['message'] )
);
$assert( $error === $spec->response( $error ), 'RestResultSpec preserves WP_Error' );
$spec_response = $spec->response( array( 'id' => 9, 'message' => 'mapped' ) );
$assert( 9 === $spec_response->data['data']['id'] && 'mapped' === $spec_response->data['message'], 'RestResultSpec maps successful result' );

$ability = new class {
	public function execute( array $input ) { return $input['fail'] ?? false ? new WP_Error( 'callback_failed', 'No.', array( 'status' => 409 ) ) : array( 'id' => 11, 'message' => 'executed' ); }
};
$GLOBALS['presentation_abilities']['datamachine/test'] = $ability;
$executed = RestAbilityExecutor::execute( 'datamachine/test', array(), $spec );
$assert( 11 === $executed->data['data']['id'], 'RestAbilityExecutor resolves and executes ability slug' );
$callback_error = RestAbilityExecutor::execute( $ability, array( 'fail' => true ), $spec );
$assert( $callback_error instanceof WP_Error && 409 === $callback_error->get_error_data()['status'], 'RestAbilityExecutor preserves callback WP_Error' );
$missing = RestAbilityExecutor::execute( 'datamachine/missing', array(), $spec );
$assert( $missing instanceof WP_Error && 'ability_not_found' === $missing->get_error_code(), 'RestAbilityExecutor returns canonical missing-ability WP_Error' );

if ( $failures ) { exit( 1 ); }
echo "Ability result presentation smoke passed.\n";
