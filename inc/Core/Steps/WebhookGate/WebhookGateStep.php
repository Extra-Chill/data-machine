<?php
/**
 * Webhook Gate Step - Pause pipeline until external webhook fires.
 *
 * Parks the pipeline in a "waiting" state and generates a unique webhook URL.
 * When the webhook receives a POST, the pipeline resumes from the next step
 * with the webhook payload injected as data packets.
 *
 * This is a handler-free step type. No handler_config or handler_slug needed.
 *
 * @package DataMachine\Core\Steps\WebhookGate
 * @since 0.25.0
 */

namespace DataMachine\Core\Steps\WebhookGate;

use DataMachine\Core\DataPacket;
use DataMachine\Core\JobStatus;
use DataMachine\Core\Steps\Step;
use DataMachine\Core\Steps\StepTypeRegistrationTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WebhookGateStep extends Step {

	use StepTypeRegistrationTrait;

	/**
	 * Initialize Webhook Gate step.
	 */
	public function __construct() {
		parent::__construct( 'webhook_gate' );

		self::registerStepType(
			slug: 'webhook_gate',
			label: 'Webhook Gate',
			description: 'Pause pipeline and wait for an external webhook before continuing',
			class: self::class,
			position: 70,
			usesHandler: false,
			hasPipelineConfig: false,
			consumeAllPackets: false,
			stepSettings: [
				'config_type' => 'inline',
				'modal_type'  => 'configure-step',
				'button_text' => 'Configure',
				'label'       => 'Webhook Gate Configuration',
			],
			showSettingsDisplay: false
		);

		self::registerStepSettings();
		self::registerWebhookEndpoint();
		self::registerTimeoutHandler();
	}

	/**
	 * Register Webhook Gate settings class for UI display.
	 */
	private static function registerStepSettings(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		add_filter(
			'datamachine_handler_settings',
			function ( $all_settings, $handler_slug = null ) {
				if ( null === $handler_slug || 'webhook_gate' === $handler_slug ) {
					$all_settings['webhook_gate'] = new WebhookGateSettings();
				}
				return $all_settings;
			},
			10,
			2
		);
	}

	/**
	 * Register the webhook gate timeout handler.
	 */
	private static function registerTimeoutHandler(): void {
		static $timeout_registered = false;
		if ( $timeout_registered ) {
			return;
		}
		$timeout_registered = true;

		add_action(
			'datamachine_webhook_gate_timeout',
			function ( $job_id, $token ) {
				$job_id = (int) $job_id;

				// Only fail the job if it's still waiting.
				$db_jobs = new \DataMachine\Core\Database\Jobs\Jobs();
				$job     = $db_jobs->get_job( $job_id );

				if ( ! $job || 'waiting' !== ( $job['status'] ?? '' ) ) {
					return; // Job already resumed or failed.
				}

				// Clean up the transient.
				delete_transient( 'dm_webhook_gate_' . $token );

				// Fail the job.
				do_action(
					'datamachine_fail_job',
					$job_id,
					'webhook_gate_timeout',
					[
						'flow_step_id'  => '',
						'error_message' => 'Webhook gate timed out waiting for inbound webhook.',
					]
				);

				do_action(
					'datamachine_log',
					'warning',
					"Webhook Gate: Timed out for job #{$job_id}",
					[ 'job_id' => $job_id, 'token' => $token ]
				);
			},
			10,
			2
		);
	}

	/**
	 * Register the inbound webhook REST endpoint.
	 */
	private static function registerWebhookEndpoint(): void {
		static $endpoint_registered = false;
		if ( $endpoint_registered ) {
			return;
		}
		$endpoint_registered = true;

		add_action( 'rest_api_init', function () {
			register_rest_route(
				'datamachine/v1',
				'/webhook/(?P<token>[a-f0-9]{64})',
				[
					'methods'             => 'POST',
					'callback'            => [ __CLASS__, 'handleInboundWebhook' ],
					'permission_callback' => '__return_true', // Auth is via the token itself
					'args'                => [
						'token' => [
							'required'          => true,
							'type'              => 'string',
							'validate_callback' => function ( $value ) {
								return (bool) preg_match( '/^[a-f0-9]{64}$/', $value );
							},
						],
					],
				]
			);
		} );
	}

	/**
	 * Validate Webhook Gate step configuration.
	 *
	 * @return bool
	 */
	protected function validateStepConfiguration(): bool {
		// No required configuration — timeout is optional.
		return true;
	}

	/**
	 * Execute Webhook Gate step logic.
	 *
	 * Generates a unique token, stores resume context in engine_data,
	 * sets job status to "waiting", and returns a success packet.
	 *
	 * The engine's status override mechanism will see "waiting" and
	 * park the job without scheduling the next step.
	 *
	 * @return array
	 */
	protected function executeStep(): array {
		$token          = bin2hex( random_bytes( 32 ) ); // 64-char hex token
		$handler_config = $this->getHandlerConfig();
		$timeout_hours  = (int) ( $handler_config['timeout_hours'] ?? 0 );

		// Determine the next step ID for resumption.
		$navigator      = new \DataMachine\Engine\StepNavigator();
		$payload        = [
			'job_id'       => $this->job_id,
			'flow_step_id' => $this->flow_step_id,
			'data'         => $this->dataPackets,
			'engine'       => $this->engine,
		];
		$next_step_id   = $navigator->get_next_flow_step_id( $this->flow_step_id, $payload );

		// Store webhook gate context in engine_data.
		datamachine_merge_engine_data(
			$this->job_id,
			[
				'webhook_gate' => [
					'token'            => $token,
					'flow_step_id'     => $this->flow_step_id,
					'next_flow_step_id' => $next_step_id,
					'created_at'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'timeout_hours'    => $timeout_hours,
					'status'           => 'waiting',
				],
			]
		);

		// Store the token → job_id mapping in a transient for fast lookup.
		// Expires based on timeout or defaults to 7 days.
		$expiry = $timeout_hours > 0 ? $timeout_hours * HOUR_IN_SECONDS : 7 * DAY_IN_SECONDS;
		set_transient( 'dm_webhook_gate_' . $token, $this->job_id, $expiry );

		// Schedule timeout action if configured.
		if ( $timeout_hours > 0 && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + ( $timeout_hours * HOUR_IN_SECONDS ),
				'datamachine_webhook_gate_timeout',
				[ 'job_id' => $this->job_id, 'token' => $token ],
				'data-machine'
			);
		}

		// Set the job status to "waiting" — the engine will see this override
		// and park the job without scheduling the next step.
		datamachine_merge_engine_data(
			$this->job_id,
			[ 'job_status' => JobStatus::WAITING ]
		);

		// Update the actual job status in the database.
		$db_jobs = new \DataMachine\Core\Database\Jobs\Jobs();
		$db_jobs->update_job_status( $this->job_id, JobStatus::WAITING );

		$webhook_url = rest_url( "datamachine/v1/webhook/{$token}" );

		do_action(
			'datamachine_log',
			'info',
			"Webhook Gate: Pipeline parked, waiting for webhook",
			[
				'job_id'       => $this->job_id,
				'flow_step_id' => $this->flow_step_id,
				'webhook_url'  => $webhook_url,
				'timeout_hours' => $timeout_hours,
			]
		);

		$result_packet = new DataPacket(
			[
				'title'       => 'Webhook Gate Active',
				'body'        => "Pipeline paused. Waiting for webhook at: {$webhook_url}",
				'webhook_url' => $webhook_url,
				'token'       => $token,
			],
			[
				'source_type'  => 'webhook_gate',
				'flow_step_id' => $this->flow_step_id,
				'success'      => true,
				'waiting'      => true,
			],
			'webhook_gate_waiting'
		);

		return $result_packet->addTo( $this->dataPackets );
	}

	/**
	 * Handle an inbound webhook POST that resumes a waiting pipeline.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handleInboundWebhook( \WP_REST_Request $request ) {
		$token  = $request->get_param( 'token' );
		$job_id = get_transient( 'dm_webhook_gate_' . $token );

		if ( ! $job_id ) {
			return new \WP_Error(
				'invalid_token',
				'Webhook token not found or expired.',
				[ 'status' => 404 ]
			);
		}

		$job_id = (int) $job_id;

		// Verify job is actually in waiting status.
		$db_jobs = new \DataMachine\Core\Database\Jobs\Jobs();
		$job     = $db_jobs->get_job( $job_id );

		if ( ! $job || 'waiting' !== ( $job['status'] ?? '' ) ) {
			return new \WP_Error(
				'job_not_waiting',
				'Job is not in waiting status.',
				[ 'status' => 409 ]
			);
		}

		// Get the webhook gate context from engine_data.
		$engine_data = datamachine_get_engine_data( $job_id );
		$gate_data   = $engine_data['webhook_gate'] ?? [];

		if ( empty( $gate_data['next_flow_step_id'] ) ) {
			return new \WP_Error(
				'no_next_step',
				'No next step configured for this webhook gate.',
				[ 'status' => 500 ]
			);
		}

		$next_step_id = $gate_data['next_flow_step_id'];

		// Build webhook payload as data packets.
		$webhook_body = $request->get_json_params();
		if ( empty( $webhook_body ) ) {
			$webhook_body = $request->get_body_params();
		}
		if ( empty( $webhook_body ) ) {
			$webhook_body = [];
		}

		$webhook_packet = new DataPacket(
			[
				'title' => 'Webhook Payload',
				'body'  => $webhook_body,
			],
			[
				'source_type'  => 'webhook_gate_inbound',
				'flow_step_id' => $gate_data['flow_step_id'] ?? '',
				'received_at'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'remote_ip'    => $request->get_header( 'x-forwarded-for' ) ?? $_SERVER['REMOTE_ADDR'] ?? '',
			],
			'webhook_payload'
		);

		$data_packets = $webhook_packet->addTo( [] );

		// Update engine_data: clear webhook_gate status, remove job_status override.
		datamachine_merge_engine_data(
			$job_id,
			[
				'webhook_gate' => array_merge( $gate_data, [
					'status'      => 'received',
					'received_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				] ),
				'job_status'   => null, // Clear the status override so engine proceeds normally.
			]
		);

		// Update job status back to processing.
		$db_jobs->update_job_status( $job_id, JobStatus::PROCESSING );

		// Clean up the transient.
		delete_transient( 'dm_webhook_gate_' . $token );

		// Resume the pipeline by scheduling the next step.
		do_action( 'datamachine_schedule_next_step', $job_id, $next_step_id, $data_packets );

		do_action(
			'datamachine_log',
			'info',
			"Webhook Gate: Pipeline resumed via inbound webhook",
			[
				'job_id'        => $job_id,
				'next_step_id'  => $next_step_id,
				'payload_keys'  => array_keys( $webhook_body ),
			]
		);

		return new \WP_REST_Response(
			[
				'success'       => true,
				'job_id'        => $job_id,
				'next_step_id'  => $next_step_id,
				'message'       => 'Pipeline resumed.',
			],
			200
		);
	}
}
