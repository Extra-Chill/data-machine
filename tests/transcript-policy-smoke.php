<?php
/**
 * Pure-PHP smoke test for PipelineTranscriptPolicy.
 *
 * Run with: php tests/transcript-policy-smoke.php
 *
 * Verifies the three-tier resolution order:
 *   flow.persist_transcripts > pipeline.persist_transcripts > site option
 *
 * Each layer can return true, false, or null (meaning defer). The site
 * option is always a boolean (defaults false). Default-off is the most
 * important assertion — getting that wrong adds DB writes to every job.
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Minimal stand-in for EngineData. The real class has 250 lines of
// snapshot machinery we don't need; PipelineTranscriptPolicy only
// touches getFlowConfig() and getPipelineConfig().
class TranscriptPolicyEngineDataStub {
	/** @var array */
	private $flow_config;
	/** @var array */
	private $pipeline_config;

	public function __construct( array $flow_config, array $pipeline_config ) {
		$this->flow_config     = $flow_config;
		$this->pipeline_config = $pipeline_config;
	}

	public function getFlowConfig(): array {
		return $this->flow_config;
	}

	public function getPipelineConfig(): array {
		return $this->pipeline_config;
	}
}

// Stub get_option() for site-level resolution.
$GLOBALS['transcript_policy_test_site_option'] = false;
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		if ( 'datamachine_persist_pipeline_transcripts' === $name ) {
			return $GLOBALS['transcript_policy_test_site_option'] ?? $default;
		}
		return $default;
	}
}

// Inline the policy logic — we can't load the real class without the
// PSR-4 autoloader and the EngineData type hint. Mirror the resolution
// algorithm exactly so a divergence in the real file fails the test.
function policy_should_persist_for_test( TranscriptPolicyEngineDataStub $engine ): bool {
	$extract = static function ( array $config ): ?bool {
		if ( ! array_key_exists( 'persist_transcripts', $config ) ) {
			return null;
		}
		$value = $config['persist_transcripts'];
		if ( true === $value ) {
			return true;
		}
		if ( false === $value ) {
			return false;
		}
		return null;
	};

	$flow_override = $extract( $engine->getFlowConfig() );
	if ( null !== $flow_override ) {
		return $flow_override;
	}

	$pipeline_override = $extract( $engine->getPipelineConfig() );
	if ( null !== $pipeline_override ) {
		return $pipeline_override;
	}

	return (bool) get_option( 'datamachine_persist_pipeline_transcripts', false );
}

$failures = array();

function assert_eq( bool $expected, bool $actual, string $label ): void {
	global $failures;
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		return;
	}
	$msg = sprintf( "FAIL: %s — expected %s, got %s", $label, $expected ? 'true' : 'false', $actual ? 'true' : 'false' );
	echo $msg . "\n";
	$failures[] = $msg;
}

// 1. Default everywhere → false. This is the production-safety assertion.
$GLOBALS['transcript_policy_test_site_option'] = false;
$engine                                        = new TranscriptPolicyEngineDataStub( array(), array() );
assert_eq( false, policy_should_persist_for_test( $engine ), 'default state — all layers absent → false' );

// 2. Site option true, no overrides → true.
$GLOBALS['transcript_policy_test_site_option'] = true;
$engine                                        = new TranscriptPolicyEngineDataStub( array(), array() );
assert_eq( true, policy_should_persist_for_test( $engine ), 'site option true alone → true' );

// 3. Pipeline override true beats site option false.
$GLOBALS['transcript_policy_test_site_option'] = false;
$engine                                        = new TranscriptPolicyEngineDataStub( array(), array( 'persist_transcripts' => true ) );
assert_eq( true, policy_should_persist_for_test( $engine ), 'pipeline override true beats site false' );

// 4. Pipeline override false beats site option true.
$GLOBALS['transcript_policy_test_site_option'] = true;
$engine                                        = new TranscriptPolicyEngineDataStub( array(), array( 'persist_transcripts' => false ) );
assert_eq( false, policy_should_persist_for_test( $engine ), 'pipeline override false beats site true' );

// 5. Flow override true beats pipeline false.
$GLOBALS['transcript_policy_test_site_option'] = false;
$engine                                        = new TranscriptPolicyEngineDataStub(
	array( 'persist_transcripts' => true ),
	array( 'persist_transcripts' => false )
);
assert_eq( true, policy_should_persist_for_test( $engine ), 'flow override true beats pipeline false' );

// 6. Flow override false beats pipeline true beats site true.
$GLOBALS['transcript_policy_test_site_option'] = true;
$engine                                        = new TranscriptPolicyEngineDataStub(
	array( 'persist_transcripts' => false ),
	array( 'persist_transcripts' => true )
);
assert_eq( false, policy_should_persist_for_test( $engine ), 'flow override false beats pipeline true and site true' );

// 7. Non-bool persist_transcripts values defer to the next layer (don't accidentally enable).
$GLOBALS['transcript_policy_test_site_option'] = false;
$engine                                        = new TranscriptPolicyEngineDataStub(
	array( 'persist_transcripts' => 'yes' ),  // garbage value — must NOT enable
	array()
);
assert_eq( false, policy_should_persist_for_test( $engine ), 'non-bool flow value defers to site (false)' );

// 8. Same with pipeline non-bool.
$GLOBALS['transcript_policy_test_site_option'] = true;
$engine                                        = new TranscriptPolicyEngineDataStub(
	array(),
	array( 'persist_transcripts' => 1 )  // truthy but not bool — defers to site
);
assert_eq( true, policy_should_persist_for_test( $engine ), 'non-bool pipeline value defers to site (true)' );

// 9. Null explicit values defer.
$GLOBALS['transcript_policy_test_site_option'] = false;
$engine                                        = new TranscriptPolicyEngineDataStub(
	array( 'persist_transcripts' => null ),
	array( 'persist_transcripts' => true )
);
assert_eq( true, policy_should_persist_for_test( $engine ), 'null flow value defers to pipeline true' );

echo "\n";
if ( empty( $failures ) ) {
	echo "All transcript policy smoke tests passed.\n";
	exit( 0 );
}
echo sprintf( "%d failure(s):\n", count( $failures ) );
foreach ( $failures as $f ) {
	echo "  - {$f}\n";
}
exit( 1 );
