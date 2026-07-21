<?php
/**
 * Namespaced polyfills for pure-PHP smokes.
 *
 * When production (or bundled vendor) code inside a namespace calls a WordPress
 * core function unqualified — e.g. `wp_json_encode( ... )` inside
 * `namespace DataMachine\Engine\AI`, or `wp_has_ability_category( ... )` inside
 * `namespace AgentsAPI\AI\Auth` — PHP resolves the call to the CURRENT namespace
 * first and only falls back to the global function for INTERNAL functions. A
 * user-defined global stub is not an internal function, so there is no fallback
 * and the call fatals under a pure-PHP (no-WordPress) smoke run. Real WordPress
 * avoids this because its core functions are global and internal-like, so the
 * namespaced lookup falls through to them.
 *
 * Defining the stub inside the SAME namespace as the call site makes the
 * unqualified call resolve here. Each stub is function_exists-guarded so it stays
 * inert under the real-WordPress CI smoke harness (where WP core already defines
 * the global function), and the ability helpers delegate to the global smoke
 * stubs so registrations still flow into whatever tracking globals a smoke sets.
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Engine\AI {
	if ( ! function_exists( __NAMESPACE__ . '\\wp_json_encode' ) ) {
		function wp_json_encode( $data, $options = 0, $depth = 512 ) {
			return json_encode( $data, $options, $depth );
		}
	}
}

namespace DataMachine\Abilities\Handler {
	if ( ! function_exists( __NAMESPACE__ . '\\wp_json_encode' ) ) {
		function wp_json_encode( $data, $options = 0, $depth = 512 ) {
			return json_encode( $data, $options, $depth );
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_generate_uuid4' ) ) {
		function wp_generate_uuid4(): string {
			return '00000000-0000-4000-8000-000000000000';
		}
	}
}

namespace DataMachine\Abilities {
	if ( ! function_exists( __NAMESPACE__ . '\\doing_action' ) ) {
		function doing_action( string $hook ): bool {
			return false;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\did_action' ) ) {
		function did_action( string $hook ): int {
			return 0;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\add_action' ) ) {
		function add_action( string $hook, callable $callback ): void {
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\apply_filters' ) ) {
		function apply_filters( string $hook, $value, ...$args ) {
			if ( 'datamachine_handlers' === $hook ) {
				return $GLOBALS['datamachine_test_handlers'] ?? $value;
			}
			return $value;
		}
	}
}

// The Agents API registers ability categories and abilities from callbacks that
// live in this namespace and call the helpers unqualified.
namespace AgentsAPI\AI\Auth {
	if ( ! function_exists( __NAMESPACE__ . '\\wp_has_ability_category' ) ) {
		function wp_has_ability_category( string $slug ): bool {
			return function_exists( '\\wp_has_ability_category' ) ? \wp_has_ability_category( $slug ) : false;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_has_ability' ) ) {
		function wp_has_ability( string $name ): bool {
			return function_exists( '\\wp_has_ability' ) ? \wp_has_ability( $name ) : false;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_register_ability_category' ) ) {
		function wp_register_ability_category( string $slug, array $args ): void {
			if ( function_exists( '\\wp_register_ability_category' ) ) {
				\wp_register_ability_category( $slug, $args );
			}
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_register_ability' ) ) {
		function wp_register_ability( string $name, array $args ): void {
			if ( function_exists( '\\wp_register_ability' ) ) {
				\wp_register_ability( $name, $args );
			}
		}
	}
}

// The Agents API registers ability categories and abilities from callbacks that
// live in this namespace and call the helpers unqualified.
namespace AgentsAPI\AI\Approvals {
	if ( ! function_exists( __NAMESPACE__ . '\\wp_has_ability_category' ) ) {
		function wp_has_ability_category( string $slug ): bool {
			return function_exists( '\\wp_has_ability_category' ) ? \wp_has_ability_category( $slug ) : false;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_has_ability' ) ) {
		function wp_has_ability( string $name ): bool {
			return function_exists( '\\wp_has_ability' ) ? \wp_has_ability( $name ) : false;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_register_ability_category' ) ) {
		function wp_register_ability_category( string $slug, array $args ): void {
			if ( function_exists( '\\wp_register_ability_category' ) ) {
				\wp_register_ability_category( $slug, $args );
			}
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_register_ability' ) ) {
		function wp_register_ability( string $name, array $args ): void {
			if ( function_exists( '\\wp_register_ability' ) ) {
				\wp_register_ability( $name, $args );
			}
		}
	}
}

// The Agents API registers ability categories and abilities from callbacks that
// live in this namespace and call the helpers unqualified.
namespace AgentsAPI\AI\Channels {
	if ( ! function_exists( __NAMESPACE__ . '\\wp_has_ability_category' ) ) {
		function wp_has_ability_category( string $slug ): bool {
			return function_exists( '\\wp_has_ability_category' ) ? \wp_has_ability_category( $slug ) : false;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_has_ability' ) ) {
		function wp_has_ability( string $name ): bool {
			return function_exists( '\\wp_has_ability' ) ? \wp_has_ability( $name ) : false;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_register_ability_category' ) ) {
		function wp_register_ability_category( string $slug, array $args ): void {
			if ( function_exists( '\\wp_register_ability_category' ) ) {
				\wp_register_ability_category( $slug, $args );
			}
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_register_ability' ) ) {
		function wp_register_ability( string $name, array $args ): void {
			if ( function_exists( '\\wp_register_ability' ) ) {
				\wp_register_ability( $name, $args );
			}
		}
	}
}

// The Agents API registers ability categories and abilities from callbacks that
// live in this namespace and call the helpers unqualified.
namespace AgentsAPI\AI\Tasks {
	if ( ! function_exists( __NAMESPACE__ . '\\wp_has_ability_category' ) ) {
		function wp_has_ability_category( string $slug ): bool {
			return function_exists( '\\wp_has_ability_category' ) ? \wp_has_ability_category( $slug ) : false;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_has_ability' ) ) {
		function wp_has_ability( string $name ): bool {
			return function_exists( '\\wp_has_ability' ) ? \wp_has_ability( $name ) : false;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_register_ability_category' ) ) {
		function wp_register_ability_category( string $slug, array $args ): void {
			if ( function_exists( '\\wp_register_ability_category' ) ) {
				\wp_register_ability_category( $slug, $args );
			}
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_register_ability' ) ) {
		function wp_register_ability( string $name, array $args ): void {
			if ( function_exists( '\\wp_register_ability' ) ) {
				\wp_register_ability( $name, $args );
			}
		}
	}
}

// The Agents API registers ability categories and abilities from callbacks that
// live in this namespace and call the helpers unqualified.
namespace AgentsAPI\AI\Tools {
	if ( ! function_exists( __NAMESPACE__ . '\\wp_has_ability_category' ) ) {
		function wp_has_ability_category( string $slug ): bool {
			return function_exists( '\\wp_has_ability_category' ) ? \wp_has_ability_category( $slug ) : false;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_has_ability' ) ) {
		function wp_has_ability( string $name ): bool {
			return function_exists( '\\wp_has_ability' ) ? \wp_has_ability( $name ) : false;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_register_ability_category' ) ) {
		function wp_register_ability_category( string $slug, array $args ): void {
			if ( function_exists( '\\wp_register_ability_category' ) ) {
				\wp_register_ability_category( $slug, $args );
			}
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_register_ability' ) ) {
		function wp_register_ability( string $name, array $args ): void {
			if ( function_exists( '\\wp_register_ability' ) ) {
				\wp_register_ability( $name, $args );
			}
		}
	}
}

// The Agents API registers ability categories and abilities from callbacks that
// live in this namespace and call the helpers unqualified.
namespace AgentsAPI\AI\Workflows {
	if ( ! function_exists( __NAMESPACE__ . '\\wp_has_ability_category' ) ) {
		function wp_has_ability_category( string $slug ): bool {
			return function_exists( '\\wp_has_ability_category' ) ? \wp_has_ability_category( $slug ) : false;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_has_ability' ) ) {
		function wp_has_ability( string $name ): bool {
			return function_exists( '\\wp_has_ability' ) ? \wp_has_ability( $name ) : false;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_register_ability_category' ) ) {
		function wp_register_ability_category( string $slug, array $args ): void {
			if ( function_exists( '\\wp_register_ability_category' ) ) {
				\wp_register_ability_category( $slug, $args );
			}
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_register_ability' ) ) {
		function wp_register_ability( string $name, array $args ): void {
			if ( function_exists( '\\wp_register_ability' ) ) {
				\wp_register_ability( $name, $args );
			}
		}
	}
}

// The Agents API registers ability categories and abilities from callbacks that
// live in this namespace and call the helpers unqualified.
namespace AgentsAPI\Core\Database\Chat {
	if ( ! function_exists( __NAMESPACE__ . '\\wp_has_ability_category' ) ) {
		function wp_has_ability_category( string $slug ): bool {
			return function_exists( '\\wp_has_ability_category' ) ? \wp_has_ability_category( $slug ) : false;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_has_ability' ) ) {
		function wp_has_ability( string $name ): bool {
			return function_exists( '\\wp_has_ability' ) ? \wp_has_ability( $name ) : false;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_register_ability_category' ) ) {
		function wp_register_ability_category( string $slug, array $args ): void {
			if ( function_exists( '\\wp_register_ability_category' ) ) {
				\wp_register_ability_category( $slug, $args );
			}
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_register_ability' ) ) {
		function wp_register_ability( string $name, array $args ): void {
			if ( function_exists( '\\wp_register_ability' ) ) {
				\wp_register_ability( $name, $args );
			}
		}
	}
}

// The Agents API registers ability categories and abilities from callbacks that
// live in this namespace and call the helpers unqualified.
namespace AgentsAPI\Core\Workspace {
	if ( ! function_exists( __NAMESPACE__ . '\\wp_has_ability_category' ) ) {
		function wp_has_ability_category( string $slug ): bool {
			return function_exists( '\\wp_has_ability_category' ) ? \wp_has_ability_category( $slug ) : false;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_has_ability' ) ) {
		function wp_has_ability( string $name ): bool {
			return function_exists( '\\wp_has_ability' ) ? \wp_has_ability( $name ) : false;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_register_ability_category' ) ) {
		function wp_register_ability_category( string $slug, array $args ): void {
			if ( function_exists( '\\wp_register_ability_category' ) ) {
				\wp_register_ability_category( $slug, $args );
			}
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_register_ability' ) ) {
		function wp_register_ability( string $name, array $args ): void {
			if ( function_exists( '\\wp_register_ability' ) ) {
				\wp_register_ability( $name, $args );
			}
		}
	}
}
