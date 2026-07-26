<?php
/**
 * Smoke tests for the bounded delegated cross-post owner contract.
 *
 * Run with: php tests/delegated-cross-post-action-smoke.php
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['delegated_cross_post_filters'] = array();
	$GLOBALS['delegated_cross_post_jobs']    = array();

	class WP_Error {
		public function __construct( private string $code, private string $message ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	function add_filter( string $hook, callable $callback ): void {
		$GLOBALS['delegated_cross_post_filters'][ $hook ][] = $callback;
	}

	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['delegated_cross_post_filters'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}

	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}

	function get_post_status( int $post_id ): string|false {
		return 42 === $post_id ? 'publish' : false;
	}

	function get_permalink( int $post_id ): string|false {
		return 42 === $post_id ? 'https://example.test/canonical-post/' : false;
	}

	function get_post_type( int $post_id ): string|false {
		return isset( $GLOBALS['delegated_cross_post_assets'][ $post_id ] ) ? 'attachment' : false;
	}

	function get_post_mime_type( int $post_id ): string|false {
		return $GLOBALS['delegated_cross_post_assets'][ $post_id ]['mime'] ?? false;
	}

	function wp_get_attachment_url( int $post_id ): string|false {
		return $GLOBALS['delegated_cross_post_assets'][ $post_id ]['url'] ?? false;
	}

	function wp_parse_url( string $url ) {
		return parse_url( $url );
	}

	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}

	function sanitize_textarea_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}

	function sanitize_key( string $value ): string {
		return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) );
	}

	function absint( $value ): int {
		return abs( (int) $value );
	}

	function get_post_meta( int $post_id, string $key, bool $single = false ) {
		unset( $post_id, $key, $single );
		return array();
	}

	function update_post_meta( int $post_id, string $key, $value ): bool {
		unset( $post_id, $key, $value );
		return true;
	}

	function datamachine_resolve_system_agent_context(): array {
		return array( 'user_id' => 7, 'agent_id' => 9, 'triggering_user_id' => 0 );
	}

	function datamachine_merge_engine_data( int $job_id, array $data ): void {
		$GLOBALS['delegated_cross_post_jobs'][ $job_id ] = array_merge( $GLOBALS['delegated_cross_post_jobs'][ $job_id ] ?? array(), $data );
	}

	$GLOBALS['delegated_cross_post_assets'] = array(
		101 => array( 'url' => 'https://example.test/uploads/image-one.jpg', 'mime' => 'image/jpeg' ),
		102 => array( 'url' => 'https://example.test/uploads/image-two.jpg', 'mime' => 'image/jpeg' ),
		103 => array( 'url' => 'https://example.test/uploads/video.mp4', 'mime' => 'video/mp4' ),
	);
}

namespace DataMachine\Engine\AI\System\Tasks {
	abstract class SystemTask {
		abstract public function executeTask( int $jobId, array $params ): void;
		abstract public function getTaskType(): string;

		protected function completeJob( int $job_id, array $data ): void {
			$GLOBALS['delegated_cross_post_jobs'][ $job_id ] = array_merge( $GLOBALS['delegated_cross_post_jobs'][ $job_id ] ?? array(), $data, array( '_status' => 'completed' ) );
		}

		protected function failJob( int $job_id, string $message ): void {
			$GLOBALS['delegated_cross_post_jobs'][ $job_id ] = array_merge( $GLOBALS['delegated_cross_post_jobs'][ $job_id ] ?? array(), array( '_status' => 'failed', '_error' => $message ) );
		}
	}
}

namespace DataMachineSocials {
	class Publisher {
		public static function cross_post( array $params ): array {
			$results = array();
			foreach ( $params['platforms'] as $platform ) {
				$success   = 'twitter' !== $platform;
				$results[] = $success
					? array( 'platform' => $platform, 'success' => true, 'platform_post_id' => $platform . '-123' )
					: array( 'platform' => $platform, 'success' => false, 'error' => 'private provider token diagnostic' );
			}

			return array(
				'success' => false,
				'results' => $results,
				'errors'  => array( 'private provider token diagnostic' ),
			);
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Operations/DelegatedCrossPostAction.php';
	require_once dirname( __DIR__ ) . '/inc/Tasks/SocialCrossPostTask.php';

	use DataMachineSocials\Operations\DelegatedCrossPostAction;
	use DataMachineSocials\Tasks\SocialCrossPostTask;

	$failures = array();
	$passes   = 0;
	$assert   = static function ( bool $condition, string $message ) use ( &$failures, &$passes ): void {
		if ( $condition ) {
			++$passes;
			return;
		}
		$failures[] = $message;
	};

	$input = array(
		'post_id'      => 42,
		'source_url'   => 'https://example.test/canonical-post/',
		'caption'      => 'Approved canonical copy.',
		'content_hash' => hash( 'sha256', 'Approved canonical copy.' ),
		'channels'     => array( 'twitter', 'instagram' ),
		'media_kind'   => 'image',
		'asset_refs'   => array( array( 'attachment_id' => 101, 'role' => 'image' ) ),
	);

	echo "delegated-cross-post-action-smoke\n\n";

	DelegatedCrossPostAction::register();
	$actions = apply_filters( 'datamachine_delegated_operation_actions', array() );
	$action  = $actions[ DelegatedCrossPostAction::ACTION_ID ] ?? array();
	$assert( array( 'version', 'normalize_input', 'authorize', 'prepare', 'project' ) === array_keys( $action ), 'registers the exact Data Machine owner callback contract without unsafe retry' );
	$assert( is_callable( $action['normalize_input'] ?? null ) && is_callable( $action['authorize'] ?? null ) && is_callable( $action['prepare'] ?? null ) && is_callable( $action['project'] ?? null ), 'all required owner callbacks are callable' );

	$normalized = $action['normalize_input']( $input, array( 'phase' => 'submit' ) );
	$assert( ! is_wp_error( $normalized ), 'valid canonical input normalizes' );
	$assert( array( 'instagram', 'twitter' ) === ( $normalized['channels'] ?? null ), 'channels normalize deterministically' );
	$assert( array( array( 'url' => 'https://example.test/uploads/image-one.jpg' ) ) === ( $normalized['images'] ?? null ), 'registered attachment resolves to public publisher input' );

	$denied = $action['authorize']( array( 'phase' => 'submit', 'input' => $normalized, 'actor' => array( 'user_id' => 11 ) ) );
	$assert( is_wp_error( $denied ) && 'social_cross_post_forbidden' === $denied->get_error_code(), 'owner action denies every actor by default' );
	add_filter( 'datamachine_socials_delegated_cross_post_authorized', static fn( bool $allowed, array $context ): bool => in_array( (int) ( $context['actor']['user_id'] ?? 0 ), array( 11, 12 ), true ) );
	$assert( true === $action['authorize']( array( 'phase' => 'reconcile', 'input' => $normalized, 'actor' => array( 'user_id' => 11 ) ) ), 'owner authorization applies to reconciliation phases' );
	$assert( true === $action['authorize']( array( 'phase' => 'submit', 'input' => $normalized, 'actor' => array( 'user_id' => 12 ) ) ), 'a second authorized actor receives the same bounded action' );
	$assert( is_wp_error( $action['authorize']( array( 'phase' => 'submit', 'input' => $normalized, 'actor' => array( 'user_id' => 13 ) ) ) ), 'authorization grants no unrelated actor authority' );
	$second_normalized = $action['normalize_input']( $input, array( 'phase' => 'submit', 'actor' => array( 'user_id' => 12 ) ) );
	$assert( $normalized === $second_normalized, 'normalized fingerprint input is actor-neutral' );

	$prepared = $action['prepare']( $normalized, array( 'operation_ref' => 'dop_' . str_repeat( 'a', 64 ) ) );
	$second_prepared = $action['prepare']( $second_normalized, array( 'operation_ref' => 'dop_' . str_repeat( 'a', 64 ), 'actor' => array( 'user_id' => 12 ) ) );
	$settings = $prepared['workflow']['steps'][0]['flow_step_settings'] ?? array();
	$assert( 7 === ( $prepared['owner_user_id'] ?? null ) && 9 === ( $prepared['agent_id'] ?? null ), 'prepare binds a stable owner and registered agent' );
	$assert( 'system_task' === ( $prepared['workflow']['steps'][0]['step_type'] ?? null ) && 'social_cross_post' === ( $settings['task_type'] ?? null ), 'prepare composes the canonical system-task workflow' );
	$assert( 'dop_' . str_repeat( 'a', 64 ) === ( $settings['params']['delegated_operation_ref'] ?? null ), 'opaque operation identity reaches the idempotent owner task' );
	$assert( $prepared === $second_prepared, 'two authorized actors compose one frozen owner workflow' );
	$encoded_prepare = json_encode( $prepared );
	$assert( ! str_contains( $encoded_prepare, 'TaskScheduler' ) && ! str_contains( $encoded_prepare, 'execute-workflow' ), 'owner workflow exposes no scheduler or arbitrary ability launch' );

	$bad_hash                 = $input;
	$bad_hash['content_hash'] = str_repeat( '0', 64 );
	$hash_error               = $action['normalize_input']( $bad_hash, array() );
	$assert( is_wp_error( $hash_error ) && 'social_cross_post_content_hash_mismatch' === $hash_error->get_error_code(), 'caption approval hash is enforced before enqueue' );
	$uncanonical_caption                 = $input;
	$uncanonical_caption['caption']      = '<strong>Approved canonical copy.</strong>';
	$uncanonical_caption['content_hash'] = hash( 'sha256', $uncanonical_caption['caption'] );
	$assert( is_wp_error( $action['normalize_input']( $uncanonical_caption, array() ) ), 'caption must already match the exact canonical text Publisher will receive' );

	$unsupported             = $input;
	$unsupported['channels'] = array( 'unknown-network' );
	$channel_error           = $action['normalize_input']( $unsupported, array() );
	$assert( is_wp_error( $channel_error ) && 'social_cross_post_unsupported_channel' === $channel_error->get_error_code(), 'unsupported channels fail before enqueue' );

	$raw_asset                         = $input;
	$raw_asset['asset_refs'][0]['url'] = 'https://attacker.example/image.jpg';
	$asset_error                       = $action['normalize_input']( $raw_asset, array() );
	$assert( is_wp_error( $asset_error ) && 'social_cross_post_invalid_asset_ref' === $asset_error->get_error_code(), 'raw asset URLs cannot bypass registered attachment references' );

	$task = new SocialCrossPostTask();
	$task->executeTask(
		50,
		array_merge(
			$settings['params'],
			array( 'platforms' => array( 'instagram', 'twitter' ) )
		)
	);
	$packets = $GLOBALS['delegated_cross_post_jobs'][50]['output_data_packets'] ?? array();
	$assert( 'completed' === ( $GLOBALS['delegated_cross_post_jobs'][50]['_status'] ?? null ) && 2 === count( $packets ), 'partial delivery completes its child task with canonical result packets' );
	$assert( 'failed - delegated_cross_post_partial' === ( $GLOBALS['delegated_cross_post_jobs'][50]['job_status'] ?? null ) && false === $packets[1]['metadata']['success'], 'partial provider failure terminates the delegated run for explicit retry' );

	$packet_refs = array_map(
		static fn( array $packet ): array => array(
			'type'           => $packet['type'],
			'source_type'    => $packet['metadata']['source_type'],
			'source_id'      => $packet['metadata']['source_id'],
			'source_item_id' => $packet['metadata']['source_item_id'],
		),
		$packets
	);
	$projection = $action['project'](
		array(
			'schema_version' => 'datamachine.run_result.v1',
			'status'         => 'completed',
			'packet_refs'    => $packet_refs,
			'steps'          => array( array( 'packet_refs' => $packet_refs ) ),
			'diagnostics'    => array( 'token' => 'private provider token diagnostic' ),
		),
		array()
	);
	$assert( 'partial' === ( $projection['classification'] ?? null ) && 1 === ( $projection['effect_count'] ?? null ), 'partial result preserves successful effect count' );
	$assert( array( array( 'channel' => 'instagram', 'platform_post_id' => 'instagram-123' ) ) === ( $projection['share_refs'] ?? null ), 'projection preserves only safe share references' );
	$assert( array( array( 'channel' => 'twitter', 'code' => 'publish_failed' ) ) === ( $projection['error_codes'] ?? null ), 'provider failures collapse to bounded error codes' );
	$encoded_projection = json_encode( $projection );
	$assert( ! str_contains( $encoded_projection, 'private' ) && ! str_contains( $encoded_projection, 'token' ), 'projection redacts content, credentials, and provider diagnostics' );
	$assert( array() === $action['project']( array( 'schema_version' => 'datamachine.run_result.v1', 'status' => 'executing' ), array() ), 'active operation envelopes expose no premature result projection' );
	$task->executeTask( 52, array_merge( $settings['params'], array( 'platforms' => array( 'twitter' ) ) ) );
	$failed_packets = $GLOBALS['delegated_cross_post_jobs'][52]['output_data_packets'] ?? array();
	$failed_ref     = array(
		'type'           => $failed_packets[0]['type'] ?? '',
		'source_type'    => $failed_packets[0]['metadata']['source_type'] ?? '',
		'source_id'      => $failed_packets[0]['metadata']['source_id'] ?? '',
		'source_item_id' => $failed_packets[0]['metadata']['source_item_id'] ?? '',
	);
	$failed_projection = $action['project']( array( 'status' => 'failed', 'packet_refs' => array( $failed_ref ) ), array() );
	$assert( 'failed - delegated_cross_post_failed' === ( $GLOBALS['delegated_cross_post_jobs'][52]['job_status'] ?? null ) && false === ( $failed_packets[0]['metadata']['success'] ?? null ), 'total delegated failure terminates without scheduling an unsafe owner retry' );
	$assert( 'failure' === ( $failed_projection['classification'] ?? null ) && 0 === ( $failed_projection['effect_count'] ?? null ), 'total provider failure projects a bounded failure rather than success' );

	$empty             = $normalized;
	$empty['platforms'] = array();
	$task->executeTask( 51, $empty + array( 'delegated_operation_ref' => 'dop_' . str_repeat( 'b', 64 ) ) );
	$assert( 'completed_no_items' === ( $GLOBALS['delegated_cross_post_jobs'][51]['job_status'] ?? null ), 'empty channel work emits canonical no-op status' );

	if ( $failures ) {
		foreach ( $failures as $failure ) {
			echo "FAIL: {$failure}\n";
		}
		exit( 1 );
	}

	echo "All {$passes} delegated cross-post assertions passed.\n";
}
