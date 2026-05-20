<?php
/**
 * Pure-PHP smoke test for taxonomy ability cleanup and CLI contracts.
 *
 * Run with: php tests/taxonomy-ability-cleanup-smoke.php
 *
 * @package DataMachine\Tests
 */

$failed = 0;
$total  = 0;

/**
 * Assert helper.
 *
 * @param string $name      Test case name.
 * @param bool   $condition Pass/fail.
 */
function assert_taxonomy_ability_cleanup( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		return;
	}
	echo "  FAIL: {$name}\n";
	++$failed;
}

$root_dir         = dirname( __DIR__ );
$taxonomy_command = (string) file_get_contents( $root_dir . '/inc/Cli/Commands/TaxonomyCommand.php' );

echo "=== Taxonomy Ability Cleanup Smoke ===\n";

echo "\n[abilities:1] Concrete taxonomy abilities use shared scaffolding\n";
foreach ( array(
	'CreateTaxonomyTermAbility',
	'DeleteTaxonomyTermAbility',
	'GetTaxonomyTermsAbility',
	'UpdateTaxonomyTermAbility',
) as $class ) {
	$contents = (string) file_get_contents( $root_dir . "/inc/Abilities/Taxonomy/{$class}.php" );
	assert_taxonomy_ability_cleanup( "{$class} extends AbstractTaxonomyAbility", false !== strpos( $contents, "class {$class} extends AbstractTaxonomyAbility" ) );
	assert_taxonomy_ability_cleanup( "{$class} has no duplicated registerAbility method", false === strpos( $contents, 'function registerAbility' ) );
}

echo "\n[cli:1] Taxonomy update/delete pass ability contract inputs\n";
assert_taxonomy_ability_cleanup( 'update documents --taxonomy option', false !== strpos( $taxonomy_command, 'wp datamachine taxonomy update 5 --taxonomy=category' ) );
assert_taxonomy_ability_cleanup( 'delete documents --taxonomy option', false !== strpos( $taxonomy_command, 'wp datamachine taxonomy delete 5 --taxonomy=category' ) );
assert_taxonomy_ability_cleanup( 'update passes term input', false !== strpos( $taxonomy_command, "'term'     => (string) \$term_id" ) );
assert_taxonomy_ability_cleanup( 'delete passes taxonomy input', false !== strpos( $taxonomy_command, "'taxonomy' => \$taxonomy" ) );

echo "\n";
if ( 0 === $failed ) {
	echo "=== taxonomy-ability-cleanup-smoke: ALL PASS ({$total}) ===\n";
	exit( 0 );
}

echo "=== taxonomy-ability-cleanup-smoke: {$failed} FAIL of {$total} ===\n";
exit( 1 );
