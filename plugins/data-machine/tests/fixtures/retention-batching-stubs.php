<?php
/**
 * Minimal namespaced stubs so RetentionCleanup loads in a pure-PHP test.
 *
 * Only BaseRepository::is_sqlite() is actually invoked on the Action
 * Scheduler cleanup path (inside the OPTIMIZE guard). The remaining imported
 * classes are referenced only as `use` names, which PHP resolves lazily, so
 * they never need to exist for the batching/per-hook logic under test.
 *
 * @package DataMachine\Tests
 */

declare( strict_types=1 );

namespace DataMachine\Core\Database {
	if ( ! class_exists( __NAMESPACE__ . '\\BaseRepository' ) ) {
		class BaseRepository {
			public static function is_sqlite(): bool {
				return false;
			}
		}
	}
}
