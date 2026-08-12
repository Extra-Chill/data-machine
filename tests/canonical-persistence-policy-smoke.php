<?php
/** Data Machine canonical persistence policy coverage. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['dm_policy_filters'] = array();

function add_filter( string $tag, callable $callback ): void { $GLOBALS['dm_policy_filters'][ $tag ][] = $callback; }
function apply_filters( string $tag, mixed $value ): mixed { foreach ( $GLOBALS['dm_policy_filters'][ $tag ] ?? array() as $callback ) { $value = $callback( $value ); } return $value; }

require_once dirname( __DIR__ ) . '/inc/Core/Database/CanonicalPersistencePolicy.php';

use DataMachine\Core\Database\CanonicalPersistencePolicy;

CanonicalPersistencePolicy::register();
$policy = apply_filters(
	'markdown_db_table_persistence_policy',
	array(
		'other_table'      => false,
		'datamachine_jobs' => array( 'custom' => 'preserved' ),
		'datamachine_logs' => false,
	)
);
$overrides = CanonicalPersistencePolicy::filterTablePolicy(
	array(
		'datamachine_jobs' => false,
		'datamachine_logs' => array( 'partition_by' => 'external_id' ),
	)
);

$passed = false === $policy['other_table']
	&& 'preserved' === $policy['datamachine_jobs']['custom']
	&& 'job_id' === $policy['datamachine_jobs']['partition_by']
	&& false === $policy['datamachine_logs']
	&& false === $overrides['datamachine_jobs']
	&& 'external_id' === $overrides['datamachine_logs']['partition_by'];

echo ( $passed ? 'PASS' : 'FAIL' ) . ': high-churn tables declare stable partition identities without replacing existing policy.' . PHP_EOL;
exit( $passed ? 0 : 1 );
