//! helpers — extracted from QueueAbility.php.


	public function __construct() {
		$this->initDatabases();

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbilities();
	}

	/**
	 * Register all queue-related abilities.
	 */
	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerQueueAdd();
			$this->registerQueueList();
			$this->registerQueueClear();
			$this->registerQueueRemove();
			$this->registerQueueUpdate();
			$this->registerQueueMove();
			$this->registerQueueSettings();
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Pop the first prompt from the queue (for engine use).
	 *
	 * @param int      $flow_id  Flow ID.
	 * @param DB_Flows $db_flows Database instance (avoids creating new instance each call).
	 * @return array|null The popped queue item or null if empty.
	 */
	public static function popFromQueue( int $flow_id, string $flow_step_id, ?DB_Flows $db_flows = null ): ?array {
		if ( null === $db_flows ) {
			$db_flows = new DB_Flows();
		}

		$flow = $db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return null;
		}

		$flow_config = $flow['flow_config'] ?? array();
		if ( ! isset( $flow_config[ $flow_step_id ] ) ) {
			return null;
		}

		$step_config  = $flow_config[ $flow_step_id ];
		$prompt_queue = $step_config['prompt_queue'] ?? array();

		if ( empty( $prompt_queue ) ) {
			return null;
		}

		$popped_item = array_shift( $prompt_queue );

		$flow_config[ $flow_step_id ]['prompt_queue'] = $prompt_queue;

		$db_flows->update_flow(
			$flow_id,
			array( 'flow_config' => $flow_config )
		);

		do_action(
			'datamachine_log',
			'info',
			'Prompt popped from queue',
			array(
				'flow_id'         => $flow_id,
				'remaining_count' => count( $prompt_queue ),
			)
		);

		return $popped_item;
	}
