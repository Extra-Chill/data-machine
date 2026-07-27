<?php
/**
 * Smoke tests for concise Data Machine Socials AGENTS.md routing.
 *
 * Run with: php tests/agents-md-routing-smoke.php
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/inc/Cli/AgentsMdSection.php';

use DataMachineSocials\Cli\AgentsMdSection;

$failures = array();
$passes   = 0;
$assert   = static function ( bool $condition, string $message ) use ( &$failures, &$passes ): void {
	if ( $condition ) {
		++$passes;
		return;
	}
	$failures[] = $message;
};

$wp       = 'wp --allow-root --path=/srv/example/';
$rendered = AgentsMdSection::render( $wp );

$assert( str_starts_with( $rendered, '### Data Machine Socials CLI' ), 'section keeps the Socials heading' );
$assert( str_contains( $rendered, 'Data Machine Socials owns cross-platform social media' ), 'section states owner and scope' );
$assert( str_contains( $rendered, '**Default routing**' ), 'default routing is present' );
$assert( str_contains( $rendered, "`{$wp} datamachine-socials instagram status`" ), 'authentication and status route uses the resolved prefix' );
$assert( str_contains( $rendered, "`{$wp} datamachine-socials instagram posts`" ), 'read route is present' );
$assert( str_contains( $rendered, "`{$wp} datamachine-socials reddit search \"live music\"`" ), 'discovery route is present' );
$assert( str_contains( $rendered, "`{$wp} datamachine-socials instagram publish --help`" ), 'publish route is present' );
$assert( str_contains( $rendered, "`{$wp} datamachine-socials comments --help`" ), 'engagement route is present' );
$assert( str_contains( $rendered, "`{$wp} datamachine-socials shares list <post-id>`" ), 'share tracking route is present' );
$assert( str_contains( $rendered, '**Safety**' ), 'live mutation safety is present' );
$assert( str_contains( $rendered, "`{$wp} datamachine-socials --help`" ), 'root discovery route is present' );
$assert( str_contains( $rendered, "`{$wp} datamachine-socials <platform> --help`" ), 'platform discovery route is present' );
$assert( str_contains( $rendered, 'Live `--help` output is authoritative.' ), 'live help is declared authoritative' );
$assert( substr_count( $rendered, "\n" ) + 1 <= 20, 'section remains bounded to 20 lines' );

foreach ( array( 'instagram archive', 'pinterest board-pins', 'mastodon register-app', 'shares clear' ) as $exhaustive_command ) {
	$assert( ! str_contains( $rendered, "datamachine-socials {$exhaustive_command}" ), "exhaustive command {$exhaustive_command} is not injected" );
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "AGENTS.md Socials routing smoke failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "AGENTS.md Socials routing smoke passed ({$passes} assertions).\n";
