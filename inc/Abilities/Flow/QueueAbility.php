<?php
/**
 * Queue Ability
 *
 * Manages prompt queues for flows. Prompts are stored in flow_config
 * and processed sequentially by AI steps.
 *
 * @package DataMachine\Abilities\Flow
 * @since 0.16.0
 */

namespace DataMachine\Abilities\Flow;

use DataMachine\Core\Database\Flows\Flows as DB_Flows;
use DataMachine\Abilities\DuplicateCheck\DuplicateCheckAbility;

defined( 'ABSPATH' ) || exit;

class QueueAbility {

	use FlowHelpers;
}
