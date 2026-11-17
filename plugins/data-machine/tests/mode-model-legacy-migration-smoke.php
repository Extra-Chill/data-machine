<?php
/**
 * Smoke: legacy `chat` model overrides move to the neutral `agent` key.
 *
 * Covers datamachine_migrate_legacy_mode_models() across the per-site option,
 * the network site option, and agent_config rows.
 *
 * Run: php tests/mode-model-legacy-migration-smoke.php
 *
 * @package DataMachine\Tests
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DATAMACHINE_VERSION', '1.0-test' );

$GLOBALS['mm_options']      = array();
$GLOBALS['mm_site_options'] = array();
$GLOBALS['mm_agents']       = array();
$GLOBALS['mm_agent_writes'] = 0;
$GLOBALS['mm_cache_clears'] = 0;

function get_option( $key, $default = false ) { return $GLOBALS['mm_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['mm_options'][ $key ] = $value; return true; }
function get_site_option( $key, $default = false ) { return $GLOBALS['mm_site_options'][ $key ] ?? $default; }
function update_site_option( $key, $value ) { $GLOBALS['mm_site_options'][ $key ] = $value; return true; }
function add_action( ...$args ) {}

eval(
	'namespace DataMachine\Core\Database\Agents;
	class Agents {
		public function get_all( array $args = array() ): array { return array_values( $GLOBALS["mm_agents"] ); }
		public function update_agent( int $agent_id, array $data ): bool {
			++$GLOBALS["mm_agent_writes"];
			$GLOBALS["mm_agents"][ $agent_id ]["agent_config"] = $data["agent_config"];
			return true;
		}
	}'
);

eval(
	'namespace DataMachine\Core;
	class PluginSettings {
		public static function clearCache(): void { ++$GLOBALS["mm_cache_clears"]; }
	}'
);

require_once dirname( __DIR__ ) . '/inc/setup/schema.php';

$failures = 0;
$passes   = 0;
$assert   = static function ( bool $condition, string $message ) use ( &$failures, &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "  [PASS] {$message}\n";
		return;
	}
	++$failures;
	echo "  [FAIL] {$message}\n";
};

$legacy = array( 'provider' => 'anthropic', 'model' => 'claude-sonnet' );
$own    = array( 'provider' => 'openai', 'model' => 'gpt-5' );

$GLOBALS['mm_options']['datamachine_settings'] = array(
	'default_provider' => 'openai',
	'mode_models'      => array(
		'chat'     => $legacy,
		'pipeline' => $own,
	),
);
$GLOBALS['mm_site_options']['datamachine_network_settings'] = array(
	'mode_models' => array(
		'chat'  => $legacy,
		'agent' => $own,
	),
);
$GLOBALS['mm_agents'] = array(
	7 => array(
		'agent_id'     => 7,
		'agent_config' => array( 'mode_models' => array( 'chat' => $legacy ) ),
	),
	8 => array(
		'agent_id'     => 8,
		'agent_config' => array( 'mode_models' => array( 'system' => $own ) ),
	),
	9 => array(
		'agent_id'     => 9,
		'agent_config' => array(),
	),
);

datamachine_migrate_legacy_mode_models();

$site = $GLOBALS['mm_options']['datamachine_settings'];
$assert( $legacy === ( $site['mode_models']['agent'] ?? null ), 'site chat override is copied to agent' );
$assert( ! isset( $site['mode_models']['chat'] ), 'site chat key is removed' );
$assert( $own === ( $site['mode_models']['pipeline'] ?? null ), 'other site mode overrides are preserved' );
$assert( 'openai' === ( $site['default_provider'] ?? null ), 'unrelated site settings are preserved' );

$network = $GLOBALS['mm_site_options']['datamachine_network_settings'];
$assert( $own === ( $network['mode_models']['agent'] ?? null ), 'existing network agent override wins over legacy chat' );
$assert( ! isset( $network['mode_models']['chat'] ), 'network chat key is removed' );

$assert( $legacy === ( $GLOBALS['mm_agents'][7]['agent_config']['mode_models']['agent'] ?? null ), 'agent_config chat override is copied to agent' );
$assert( ! isset( $GLOBALS['mm_agents'][7]['agent_config']['mode_models']['chat'] ), 'agent_config chat key is removed' );
$assert( 1 === $GLOBALS['mm_agent_writes'], 'only agents with a legacy chat override are rewritten' );
$assert( $GLOBALS['mm_cache_clears'] >= 1, 'settings cache is cleared after migration' );

$before_site    = $GLOBALS['mm_options'];
$before_network = $GLOBALS['mm_site_options'];
datamachine_migrate_legacy_mode_models();
$assert( $before_site === $GLOBALS['mm_options'] && $before_network === $GLOBALS['mm_site_options'], 'rerun leaves migrated options unchanged' );
$assert( 1 === $GLOBALS['mm_agent_writes'], 'rerun does not rewrite agents' );

$assert( null === datamachine_migrate_legacy_mode_models_config( array( 'mode_models' => array( 'pipeline' => $own ) ) ), 'config without chat key reports no change' );
$empty_agent = datamachine_migrate_legacy_mode_models_config(
	array(
		'mode_models' => array(
			'chat'  => $legacy,
			'agent' => array( 'provider' => '', 'model' => '' ),
		),
	)
);
$assert( $legacy === ( $empty_agent['mode_models']['agent'] ?? null ), 'empty agent entry does not block the legacy copy' );

echo "\n";
if ( $failures > 0 ) {
	echo "FAILED: {$failures} of " . ( $failures + $passes ) . " assertions failed.\n";
	exit( 1 );
}
echo "Mode model legacy migration smoke passed ({$passes} assertions).\n";
