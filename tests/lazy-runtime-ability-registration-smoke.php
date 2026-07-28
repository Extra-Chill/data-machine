<?php
/**
 * Regression test for full-runtime ability registration after lazy activation.
 *
 * Run with: php tests/lazy-runtime-ability-registration-smoke.php
 *
 * @package DataMachine\Tests
 */

declare( strict_types = 1 );

namespace {
	define( 'ABSPATH', sys_get_temp_dir() . '/datamachine-lazy-runtime-abilities/' );

	$GLOBALS['datamachine_test_state'] = (object) array(
		'actions'                 => array(),
		'categories'              => array( 'datamachine-agent', 'datamachine-content' ),
		'did_abilities_init'      => 0,
		'duplicate_registrations' => 0,
		'full_runtime_loads'      => 0,
		'registered'              => array(),
		'rejected_registrations'  => 0,
	);
	$GLOBALS['wp_current_filter'] = array();

	class WP_Ability {
		public function __construct( public string $name, public array $args ) {}

		public function execute( array $input = array() ): mixed {
			return ( $this->args['execute_callback'] )( $input );
		}
	}

	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}

	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['datamachine_test_state']->actions[ $hook ][] = $callback;
	}

	function add_filter( string $hook, callable $callback ): void {
		add_action( $hook, $callback );
	}

	function doing_action( string $hook = '' ): bool {
		return in_array( $hook, $GLOBALS['wp_current_filter'], true );
	}

	function did_action( string $hook ): int {
		return 'wp_abilities_api_init' === $hook
			? $GLOBALS['datamachine_test_state']->did_abilities_init
			: 0;
	}

	function wp_register_ability( string $name, array $args ): ?WP_Ability {
		$state = $GLOBALS['datamachine_test_state'];

		if ( ! doing_action( 'wp_abilities_api_init' ) || ! in_array( $args['category'] ?? '', $state->categories, true ) ) {
			++$state->rejected_registrations;
			return null;
		}

		if ( isset( $state->registered[ $name ] ) ) {
			++$state->duplicate_registrations;
			return null;
		}

		$state->registered[ $name ] = new WP_Ability( $name, $args );
		return $state->registered[ $name ];
	}

	require_once dirname( __DIR__ ) . '/inc/Abilities/AbilityRegistration.php';

	function datamachine_activate_full_runtime( string $reason = '' ): void {
		$state = $GLOBALS['datamachine_test_state'];
		++$state->full_runtime_loads;

		if ( 'ability:data-machine-events/upsert-event' !== $reason ) {
			return;
		}

		require_once dirname( __DIR__ ) . '/inc/Abilities/Content/UpsertPostAbility.php';
		require_once dirname( __DIR__ ) . '/inc/Abilities/DuplicateCheck/DuplicateCheckAbility.php';

		new \DataMachine\Abilities\DuplicateCheck\DuplicateCheckAbility();
		new \DataMachine\Abilities\Content\UpsertPostAbility();
	}

	$assertions = 0;
	$assert     = static function ( string $label, bool $condition ) use ( &$assertions ): void {
		++$assertions;
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$label}\n" );
			exit( 1 );
		}

		echo "ok - {$label}\n";
	};

	// Reproduce the request: a lightweight extension ability exists before the
	// one-shot lifecycle completes, then its execution opens Data Machine's full runtime.
	$GLOBALS['wp_current_filter'][] = 'wp_abilities_api_init';
	wp_register_ability(
		'data-machine-events/upsert-event',
		array(
			'label'               => 'Upsert Event',
			'description'         => 'Test extension ability that activates Data Machine lazily.',
			'category'            => 'datamachine-content',
			'execute_callback'    => \DataMachine\Abilities\AbilityRegistration::runtime_callback(
				static fn(): array => array( 'success' => true ),
				'data-machine-events/upsert-event'
			),
			'permission_callback' => '__return_true',
		)
	);
	array_pop( $GLOBALS['wp_current_filter'] );
	$GLOBALS['datamachine_test_state']->did_abilities_init = 1;

	$event_ability = $GLOBALS['datamachine_test_state']->registered['data-machine-events/upsert-event'];
	$event_ability->execute();
	$event_ability->execute();

	$registered = $GLOBALS['datamachine_test_state']->registered;
	$assert( 'late activation loads the full runtime once', 1 === $GLOBALS['datamachine_test_state']->full_runtime_loads );
	$assert( 'late activation registers datamachine/check-duplicate', isset( $registered['datamachine/check-duplicate'] ) );
	$assert( 'late activation registers datamachine/upsert-post', isset( $registered['datamachine/upsert-post'] ) );
	$assert( 'late registration preserves category-first validation', 0 === $GLOBALS['datamachine_test_state']->rejected_registrations );
	$assert( 'late registration does not duplicate existing abilities', 0 === $GLOBALS['datamachine_test_state']->duplicate_registrations );
	$assert( 'late registration does not replay the one-shot lifecycle', 1 === $GLOBALS['datamachine_test_state']->did_abilities_init );
	$assert( 'late registration restores the WordPress hook stack', array() === $GLOBALS['wp_current_filter'] );

	echo "All {$assertions} lazy-runtime ability assertions passed.\n";
}
