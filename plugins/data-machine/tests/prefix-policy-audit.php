<?php
/**
 * Enforces the Data Machine WordPress prefix policy in production PHP source.
 *
 * Run: php tests/prefix-policy-audit.php
 */

declare(strict_types=1);

const DATAMACHINE_PREFIX_AUDIT_LEGACY_PREFIX = 'dm_';

/**
 * @param list<array{0:int,1:string,2:int}|string> $tokens
 */
function datamachine_prefix_audit_next_significant_token( array $tokens, int $offset ): ?int {
	for ( $index = $offset; isset( $tokens[ $index ] ); $index++ ) {
		$token = $tokens[ $index ];
		if ( ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			return $index;
		}
	}

	return null;
}

/**
 * @param list<array{0:int,1:string,2:int}|string> $tokens
 * @return list<list<array{0:int,1:string,2:int}|string>>
 */
function datamachine_prefix_audit_call_arguments( array $tokens, int $open_parenthesis ): array {
	$arguments = array();
	$argument  = array();
	$depth     = 0;

	for ( $index = $open_parenthesis + 1; isset( $tokens[ $index ] ); $index++ ) {
		$token = $tokens[ $index ];
		$text  = is_array( $token ) ? $token[1] : $token;

		if ( '(' === $text || '[' === $text || '{' === $text ) {
			$depth++;
		} elseif ( ')' === $text || ']' === $text || '}' === $text ) {
			if ( 0 === $depth && ')' === $text ) {
				$arguments[] = $argument;
				return $arguments;
			}
			$depth--;
		} elseif ( ',' === $text && 0 === $depth ) {
			$arguments[] = $argument;
			$argument    = array();
			continue;
		}

		$argument[] = $token;
	}

	return array();
}

/**
 * @param list<array{0:int,1:string,2:int}|string> $tokens
 * @return array{value:string,line:int}|null
 */
function datamachine_prefix_audit_literal( array $tokens ): ?array {
	$significant = array_values(
		array_filter(
			$tokens,
			static fn( $token ): bool => ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true )
		)
	);

	if ( 1 !== count( $significant ) || ! is_array( $significant[0] ) || T_CONSTANT_ENCAPSED_STRING !== $significant[0][0] ) {
		return null;
	}

	$value = $significant[0][1];
	return array(
		'value' => substr( $value, 1, -1 ),
		'line'  => $significant[0][2],
	);
}

/**
 * @param list<array{0:int,1:string,2:int}|string> $tokens
 * @return list<array{value:string,line:int}>
 */
function datamachine_prefix_audit_literals( array $tokens ): array {
	$literals = array();
	foreach ( $tokens as $token ) {
		if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
			continue;
		}

		$literal = datamachine_prefix_audit_literal( array( $token ) );
		if ( null !== $literal ) {
			$literals[] = $literal;
		}
	}

	return $literals;
}

/**
 * @param list<string> $findings
 */
function datamachine_prefix_audit_add_finding( array &$findings, string $path, int $line, string $surface, string $value ): void {
	if ( str_starts_with( $value, DATAMACHINE_PREFIX_AUDIT_LEGACY_PREFIX ) ) {
		$findings[] = sprintf( '%s:%d [%s] %s', $path, $line, $surface, $value );
	}
}

/**
 * @return list<string>
 */
function datamachine_prefix_audit_file( string $path, string $root ): array {
	$source = file_get_contents( $path );
	if ( false === $source ) {
		throw new RuntimeException( "Unable to read {$path}" );
	}

	$tokens       = token_get_all( $source );
	$findings     = array();
	$relative_path = ltrim( substr( $path, strlen( $root ) ), '/' );
	$call_surfaces = array(
		// WordPress and Action Scheduler hook names are externally addressable identifiers.
		'add_action'                    => array( 'hook', 0 ),
		'add_filter'                    => array( 'hook', 0 ),
		'do_action'                     => array( 'hook', 0 ),
		'do_action_ref_array'           => array( 'hook', 0 ),
		'apply_filters'                 => array( 'hook', 0 ),
		'apply_filters_ref_array'       => array( 'hook', 0 ),
		'has_action'                    => array( 'hook', 0 ),
		'has_filter'                    => array( 'hook', 0 ),
		'remove_action'                 => array( 'hook', 0 ),
		'remove_filter'                 => array( 'hook', 0 ),
		'wp_schedule_event'             => array( 'hook', 2 ),
		'wp_schedule_single_event'      => array( 'hook', 1 ),
		'wp_next_scheduled'             => array( 'hook', 0 ),
		'wp_clear_scheduled_hook'       => array( 'hook', 0 ),
		'wp_unschedule_hook'            => array( 'hook', 0 ),
		'as_enqueue_async_action'       => array( 'hook', 0 ),
		'as_schedule_single_action'     => array( 'hook', 0 ),
		'as_schedule_recurring_action'  => array( 'hook', 0 ),
		'as_schedule_cron_action'       => array( 'hook', 0 ),
		'as_next_scheduled_action'      => array( 'hook', 0 ),
		'as_unschedule_action'          => array( 'hook', 0 ),
		'as_unschedule_all_actions'     => array( 'hook', 0 ),
		// Option and transient APIs persist plugin-owned keys outside the PHP process.
		'get_option'                    => array( 'option key', 0 ),
		'add_option'                    => array( 'option key', 0 ),
		'update_option'                 => array( 'option key', 0 ),
		'delete_option'                 => array( 'option key', 0 ),
		'get_site_option'               => array( 'option key', 0 ),
		'add_site_option'               => array( 'option key', 0 ),
		'update_site_option'            => array( 'option key', 0 ),
		'delete_site_option'            => array( 'option key', 0 ),
		'get_blog_option'               => array( 'option key', 1 ),
		'add_blog_option'               => array( 'option key', 1 ),
		'update_blog_option'            => array( 'option key', 1 ),
		'delete_blog_option'            => array( 'option key', 1 ),
		'register_setting'              => array( 'option key', 1 ),
		'get_transient'                 => array( 'transient key', 0 ),
		'set_transient'                 => array( 'transient key', 0 ),
		'delete_transient'              => array( 'transient key', 0 ),
		'get_site_transient'            => array( 'transient key', 0 ),
		'set_site_transient'            => array( 'transient key', 0 ),
		'delete_site_transient'         => array( 'transient key', 0 ),
		// Meta keys are storage identifiers; registration makes the key public too.
		'get_post_meta'                 => array( 'meta key', 1 ),
		'add_post_meta'                 => array( 'meta key', 1 ),
		'update_post_meta'              => array( 'meta key', 1 ),
		'delete_post_meta'              => array( 'meta key', 1 ),
		'get_term_meta'                 => array( 'meta key', 1 ),
		'add_term_meta'                 => array( 'meta key', 1 ),
		'update_term_meta'              => array( 'meta key', 1 ),
		'delete_term_meta'              => array( 'meta key', 1 ),
		'get_user_meta'                 => array( 'meta key', 1 ),
		'add_user_meta'                 => array( 'meta key', 1 ),
		'update_user_meta'              => array( 'meta key', 1 ),
		'delete_user_meta'              => array( 'meta key', 1 ),
		'get_comment_meta'              => array( 'meta key', 1 ),
		'add_comment_meta'              => array( 'meta key', 1 ),
		'update_comment_meta'           => array( 'meta key', 1 ),
		'delete_comment_meta'           => array( 'meta key', 1 ),
		'get_metadata'                  => array( 'meta key', 2 ),
		'add_metadata'                  => array( 'meta key', 2 ),
		'update_metadata'               => array( 'meta key', 2 ),
		'delete_metadata'               => array( 'meta key', 2 ),
		'register_meta'                 => array( 'meta key', 1 ),
		'register_post_meta'            => array( 'meta key', 1 ),
		'register_term_meta'            => array( 'meta key', 1 ),
		'register_user_meta'            => array( 'meta key', 1 ),
		'register_comment_meta'         => array( 'meta key', 1 ),
		'define'                        => array( 'constant value', 1 ),
		// Taxonomy slugs are global WordPress identifiers.
		'register_taxonomy'             => array( 'taxonomy slug', 0 ),
		'register_taxonomy_for_object_type' => array( 'taxonomy slug', 0 ),
	);

	foreach ( $tokens as $index => $token ) {
		if ( ! is_array( $token ) ) {
			continue;
		}

		if ( T_STRING === $token[0] ) {
			datamachine_prefix_audit_add_finding( $findings, $relative_path, $token[2], 'PHP symbol', $token[1] );
		} elseif ( T_VARIABLE === $token[0] ) {
			datamachine_prefix_audit_add_finding( $findings, $relative_path, $token[2], 'PHP variable', substr( $token[1], 1 ) );
		}

		if ( T_STRING !== $token[0] || ! isset( $call_surfaces[ strtolower( $token[1] ) ] ) ) {
			continue;
		}

		$open_parenthesis = datamachine_prefix_audit_next_significant_token( $tokens, $index + 1 );
		if ( null === $open_parenthesis || '(' !== $tokens[ $open_parenthesis ] ) {
			continue;
		}

		list( $surface, $argument_index ) = $call_surfaces[ strtolower( $token[1] ) ];
		$arguments                         = datamachine_prefix_audit_call_arguments( $tokens, $open_parenthesis );
		$literals = isset( $arguments[ $argument_index ] ) ? datamachine_prefix_audit_literals( $arguments[ $argument_index ] ) : array();
		foreach ( $literals as $literal ) {
			datamachine_prefix_audit_add_finding( $findings, $relative_path, $literal['line'], $surface, $literal['value'] );
		}
	}

	foreach ( $tokens as $index => $token ) {
		if ( ! is_array( $token ) || T_CONST !== $token[0] ) {
			continue;
		}

		for ( $constant_index = $index + 1; isset( $tokens[ $constant_index ] ) && ';' !== $tokens[ $constant_index ]; $constant_index++ ) {
			$constant_token = $tokens[ $constant_index ];
			if ( is_array( $constant_token ) && T_CONSTANT_ENCAPSED_STRING === $constant_token[0] ) {
				$literal = datamachine_prefix_audit_literal( array( $constant_token ) );
				if ( null !== $literal ) {
					datamachine_prefix_audit_add_finding( $findings, $relative_path, $literal['line'], 'constant value', $literal['value'] );
				}
			}
		}
	}

	foreach ( $tokens as $index => $token ) {
		if ( ! is_array( $token ) || T_VARIABLE !== $token[0] || '$wpdb' !== $token[1] ) {
			continue;
		}

		$operator = datamachine_prefix_audit_next_significant_token( $tokens, $index + 1 );
		$property = null === $operator ? null : datamachine_prefix_audit_next_significant_token( $tokens, $operator + 1 );
		$dot      = null === $property ? null : datamachine_prefix_audit_next_significant_token( $tokens, $property + 1 );
		$string   = null === $dot ? null : datamachine_prefix_audit_next_significant_token( $tokens, $dot + 1 );
		if ( null === $operator || null === $property || null === $dot || null === $string || T_OBJECT_OPERATOR !== $tokens[ $operator ][0] || ! is_array( $tokens[ $property ] ) || ! in_array( $tokens[ $property ][1], array( 'prefix', 'base_prefix' ), true ) || '.' !== $tokens[ $dot ] ) {
			continue;
		}

		$literal = datamachine_prefix_audit_literal( array( $tokens[ $string ] ) );
		if ( null !== $literal ) {
			datamachine_prefix_audit_add_finding( $findings, $relative_path, $literal['line'], 'database table', $literal['value'] );
		}
	}

	foreach ( $tokens as $token ) {
		if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
			continue;
		}

		$literal = datamachine_prefix_audit_literal( array( $token ) );
		if ( null !== $literal && 1 === preg_match( '/\b(?:CREATE|ALTER|DROP|TRUNCATE)\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?[`"\']?(dm_[A-Za-z0-9_]+)/i', $literal['value'], $match ) ) {
			datamachine_prefix_audit_add_finding( $findings, $relative_path, $literal['line'], 'database table', $match[1] );
		}
	}

	return array_values( array_unique( $findings ) );
}

$failures = 0;
$passes   = 0;
$assert   = static function ( bool $condition, string $message ) use ( &$failures, &$passes ): void {
	if ( $condition ) {
		$passes++;
		echo "PASS: {$message}\n";
		return;
	}

	$failures++;
	echo "FAIL: {$message}\n";
};

$root         = dirname( __DIR__ );
$fixture_root = __DIR__ . '/fixtures/prefix-policy-audit';
$legacy       = datamachine_prefix_audit_file( $fixture_root . '/legacy-surfaces.php', $root );
$valid        = datamachine_prefix_audit_file( $fixture_root . '/valid-surfaces.php', $root );

foreach ( array( 'PHP symbol', 'PHP variable', 'hook', 'option key', 'transient key', 'meta key', 'constant value', 'taxonomy slug', 'database table' ) as $surface ) {
	$assert( (bool) array_filter( $legacy, static fn( string $finding ): bool => str_contains( $finding, "[{$surface}]" ) ), "legacy {$surface} is detected" );
}
$assert( array() === $valid, 'prose, unrelated substrings, and valid datamachine_ values are ignored' );

$production_files = array( $root . '/data-machine.php', $root . '/uninstall.php' );
$iterator         = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/inc', FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	if ( $file->isFile() && 'php' === $file->getExtension() ) {
		$production_files[] = $file->getPathname();
	}
}

$findings = array();
foreach ( $production_files as $file ) {
	$findings = array_merge( $findings, datamachine_prefix_audit_file( $file, $root ) );
}

$assert( array() === $findings, 'production PHP source contains no dm_ prefix-policy violations' );
if ( array() !== $findings ) {
	echo implode( "\n", $findings ) . "\n";
}

printf( "Result: %d passed, %d failed\n", $passes, $failures );
exit( $failures > 0 ? 1 : 0 );
