<?php

namespace DataMachine\Engine\AI\System\Tasks\Traits;

/**
 * Shared trait for the `supportsUndo` method.
 *
 * Extracted by homeboy audit --fix from duplicate implementations.
 */
trait HasSupportsUndo {
	/**
	 * Alt text generation supports undo — restores previous alt text value.
	 *
	 * @return bool
	 * @since 0.33.0
	 */
	public function supportsUndo(): bool {
		return true;
	}
}
