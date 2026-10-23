<?php
/**
 * Agent bundle slug accessor helper.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Shared slug accessor for bundle documents named by portable slug.
 */
trait AgentBundleSlugTrait {

	public function slug(): string {
		return $this->slug;
	}
}
