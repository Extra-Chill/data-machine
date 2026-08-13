<?php
/**
 * Pure-PHP contract smoke for schema-v1 coordinator subagent graphs (#3169).
 *
 * Run with: php tests/agent-bundle-subagent-graph-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $value ) { return (string) $value; }
}

require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleValidationException.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/PortableSlug.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Agents/AgentSubagentGraph.php';

use DataMachine\Engine\Agents\AgentSubagentGraph;
use DataMachine\Engine\Bundle\BundleValidationException;

$failures = 0;
function subagent_graph_assert( bool $condition, string $label ): void {
	global $failures;
	echo ( $condition ? 'PASS' : 'FAIL' ) . ": {$label}\n";
	if ( ! $condition ) { ++$failures; }
}
function subagent_graph_node( string $slug, array $edges = array() ): array {
	return array(
		'slug' => $slug, 'label' => ucfirst( $slug ), 'description' => $slug,
		'agent_config' => array( 'default_model' => 'generic-model' ),
		'memory' => array( 'SOUL.md' => "# {$slug}\n", 'MEMORY.md' => '' ),
		'tool_policy' => array( 'allow' => array( 'generic/tool' ) ),
		'skill_policy' => array( 'mode' => 'explicit' ),
		'skills' => array( 'skill.md' => "generic-skill\n" ), 'references' => array( 'reference.md' => "generic-ref\n" ), 'subagents' => $edges,
	);
}

$nodes = AgentSubagentGraph::normalize( array(
	subagent_graph_node( 'writer', array( 'researcher' ) ),
	subagent_graph_node( 'reviewer' ),
	subagent_graph_node( 'researcher' ),
), 'coordinator' );
subagent_graph_assert( array( 'researcher', 'reviewer', 'writer' ) === array_column( $nodes, 'slug' ), 'children have deterministic slug order' );
subagent_graph_assert( array( 'researcher' ) === $nodes[2]['subagents'], 'child edges preserve normalized exact target' );
subagent_graph_assert( '# writer' . "\n" === $nodes[2]['memory']['SOUL.md'], 'identity bytes round-trip' );
subagent_graph_assert( "generic-skill\n" === $nodes[2]['skills']['skill.md'] && "generic-ref\n" === $nodes[2]['references']['reference.md'], 'generic skills and references round-trip as bytes' );
subagent_graph_assert( array( 'mode' => 'explicit' ) === $nodes[2]['skill_policy'], 'child skill policy round-trips' );
subagent_graph_assert( array( 'writer' ) === AgentSubagentGraph::coordinator_edges( array( 'writer' ), $nodes, 'coordinator' ), 'coordinator edges resolve within bundled children' );

foreach ( array(
	array( subagent_graph_node( 'coordinator' ) ),
	array( subagent_graph_node( 'writer', array( 'missing' ) ) ),
	array( subagent_graph_node( 'writer', array( 'reviewer' ) ), subagent_graph_node( 'reviewer', array( 'writer' ) ) ),
	array( subagent_graph_node( 'writer', array( 'writer' ) ) ),
	array( subagent_graph_node( 'writer' ), subagent_graph_node( 'writer' ) ),
	array( subagent_graph_node( 'writer' ) ),
) as $index => $invalid ) {
	$rejected = false;
	try {
		$invalid_nodes = AgentSubagentGraph::normalize( $invalid, 'coordinator' );
		if ( 5 === $index ) {
			AgentSubagentGraph::coordinator_edges( array( 'missing' ), $invalid_nodes, 'coordinator' );
		}
	} catch ( BundleValidationException $e ) { $rejected = true; }
	subagent_graph_assert( $rejected, 'invalid graph is rejected before materialization' );
}

$rejected = false;
try {
	$invalid = subagent_graph_node( 'writer' );
	$invalid['skills'] = array( 'legacy-list' );
	AgentSubagentGraph::normalize( array( $invalid ), 'coordinator' );
} catch ( BundleValidationException $e ) { $rejected = true; }
subagent_graph_assert( $rejected, 'legacy list-form skills are rejected rather than normalized away' );

foreach ( array( 'Writer', '../writer' ) as $invalid_slug ) {
	$rejected = false;
	try {
		$invalid = subagent_graph_node( $invalid_slug );
		AgentSubagentGraph::normalize( array( $invalid ), 'coordinator' );
	} catch ( BundleValidationException $e ) { $rejected = true; }
	subagent_graph_assert( $rejected, 'noncanonical or traversal child slugs are rejected before path construction' );
}

$rejected = false;
try {
	$invalid = subagent_graph_node( 'writer' );
	$invalid['skills'] = array( '../escape.md' => 'escape' );
	AgentSubagentGraph::normalize( array( $invalid ), 'coordinator' );
} catch ( BundleValidationException $e ) { $rejected = true; }
subagent_graph_assert( $rejected, 'traversal skill artifact paths are rejected before package path construction' );

if ( $failures ) {
	exit( 1 );
}
echo "All subagent graph assertions passed.\n";
