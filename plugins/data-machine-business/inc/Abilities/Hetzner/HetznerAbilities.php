<?php
/**
 * Hetzner Cloud abilities (read-only).
 *
 * Read-only visibility into the Hetzner Cloud project that hosts the
 * platform: servers, backup freshness, volumes, and server metrics
 * (issue #3945). Mutations (snapshots, resizes, enabling backups), the
 * Robot / Storage Box API, and running backups are intentionally out of
 * scope — this integration only observes.
 *
 * Credentials ({api_token}) live in the datamachine_hetzner_config network
 * option, never in code, and are resolvable through a filter so deployments
 * can inject secrets without persisting them. The token is sent only in the
 * Authorization header; it is never returned, echoed, or logged.
 *
 * Abilities:
 *  - datamachine/hetzner-list-servers   List servers (or get one by server_id).
 *  - datamachine/hetzner-backup-status  Backup freshness per server (age, stale flag, count, backup_window).
 *  - datamachine/hetzner-list-volumes   List volumes (size, attachment, location).
 *  - datamachine/hetzner-server-metrics CPU / disk / network metrics for a time window.
 *
 * @package DataMachineBusiness\Abilities\Hetzner
 */

namespace DataMachineBusiness\Abilities\Hetzner;

use DataMachine\Abilities\AbilityRegistration;
use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class HetznerAbilities {

	/**
	 * Network option holding {api_token}.
	 *
	 * @var string
	 */
	public const CONFIG_OPTION = 'datamachine_hetzner_config';

	/**
	 * Hetzner Cloud API base URL.
	 *
	 * @var string
	 */
	public const API_BASE = 'https://api.hetzner.cloud/v1';

	/**
	 * Default backup staleness threshold in hours. Hetzner takes one backup
	 * per day inside the server's backup window, so 26h allows for window
	 * jitter before a missed run is flagged.
	 *
	 * @var int
	 */
	public const DEFAULT_STALE_HOURS = 26;

	/**
	 * Hetzner's maximum page size.
	 *
	 * @var int
	 */
	public const PER_PAGE = 50;

	/**
	 * Safety cap on followed pages per collection request.
	 *
	 * @var int
	 */
	public const MAX_PAGES = 50;

	/**
	 * Metric types supported by /servers/{id}/metrics.
	 *
	 * @var string[]
	 */
	public const METRIC_TYPES = array( 'cpu', 'disk', 'network' );

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->register_abilities();
		self::$registered = true;
	}

	private function register_abilities(): void {
		AbilityRegistration::on_abilities_api_init(
			function (): void {
				$this->register_list_servers_ability();
				$this->register_backup_status_ability();
				$this->register_list_volumes_ability();
				$this->register_server_metrics_ability();
			}
		);
	}

	private function register_list_servers_ability(): void {
		wp_register_ability(
			'datamachine/hetzner-list-servers',
			array(
				'label'               => __( 'Hetzner: List Servers', 'data-machine-business' ),
				'description'         => __( 'List Hetzner Cloud servers (status, type, location, backup window, protection flags). Pass server_id to get one server. Read-only.', 'data-machine-business' ),
				'category'            => 'datamachine-system',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'server_id' => array(
							'type'        => 'integer',
							'description' => __( 'Optional Hetzner server ID. When set, only that server is returned.', 'data-machine-business' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'servers' => array( 'type' => 'array' ),
						'count'   => array( 'type' => 'integer' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( self::class, 'execute_list_servers' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);
	}

	private function register_backup_status_ability(): void {
		wp_register_ability(
			'datamachine/hetzner-backup-status',
			array(
				'label'               => __( 'Hetzner: Backup Status', 'data-machine-business' ),
				'description'         => __( 'Report Hetzner Cloud backup freshness per server: last backup time, age in hours, stale flag, backup count, backup window, and whether backups are disabled. Read-only.', 'data-machine-business' ),
				'category'            => 'datamachine-system',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'server_id'   => array(
							'type'        => 'integer',
							'description' => __( 'Optional Hetzner server ID. Defaults to every server in the project.', 'data-machine-business' ),
						),
						'stale_hours' => array(
							'type'        => 'number',
							'description' => __( 'Optional staleness threshold in hours (default 26, filterable via datamachine_hetzner_backup_stale_hours).', 'data-machine-business' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'healthy'     => array( 'type' => 'boolean' ),
						'stale_hours' => array( 'type' => 'number' ),
						'servers'     => array( 'type' => 'array' ),
						'issues'      => array( 'type' => 'array' ),
						'message'     => array( 'type' => 'string' ),
						'error'       => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( self::class, 'execute_backup_status' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);
	}

	private function register_list_volumes_ability(): void {
		wp_register_ability(
			'datamachine/hetzner-list-volumes',
			array(
				'label'               => __( 'Hetzner: List Volumes', 'data-machine-business' ),
				'description'         => __( 'List Hetzner Cloud volumes (size, server attachment, location, status). Read-only.', 'data-machine-business' ),
				'category'            => 'datamachine-system',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'volumes'       => array( 'type' => 'array' ),
						'count'         => array( 'type' => 'integer' ),
						'total_size_gb' => array( 'type' => 'number' ),
						'error'         => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( self::class, 'execute_list_volumes' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);
	}

	private function register_server_metrics_ability(): void {
		wp_register_ability(
			'datamachine/hetzner-server-metrics',
			array(
				'label'               => __( 'Hetzner: Server Metrics', 'data-machine-business' ),
				'description'         => __( 'Fetch Hetzner Cloud CPU, disk, and network metrics for a server over a time window, with per-series latest/avg/max summaries. Read-only.', 'data-machine-business' ),
				'category'            => 'datamachine-system',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'server_id' ),
					'properties' => array(
						'server_id' => array(
							'type'        => 'integer',
							'description' => __( 'Hetzner server ID.', 'data-machine-business' ),
						),
						'type'      => array(
							'type'        => 'string',
							'description' => __( 'Comma-separated metric types: cpu, disk, network (default all three).', 'data-machine-business' ),
						),
						'hours'     => array(
							'type'        => 'number',
							'description' => __( 'Window length in hours ending now (default 1). Ignored when start is set.', 'data-machine-business' ),
						),
						'start'     => array(
							'type'        => 'string',
							'description' => __( 'Window start (ISO 8601). Overrides hours.', 'data-machine-business' ),
						),
						'end'       => array(
							'type'        => 'string',
							'description' => __( 'Window end (ISO 8601, default now).', 'data-machine-business' ),
						),
						'step'      => array(
							'type'        => 'integer',
							'description' => __( 'Optional resolution in seconds.', 'data-machine-business' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'server_id'   => array( 'type' => 'integer' ),
						'start'       => array( 'type' => 'string' ),
						'end'         => array( 'type' => 'string' ),
						'step'        => array( 'type' => 'number' ),
						'summary'     => array( 'type' => 'object' ),
						'time_series' => array( 'type' => 'object' ),
						'error'       => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( self::class, 'execute_server_metrics' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);
	}

	// ------------------------------------------------------------------
	// Ability callbacks.
	// ------------------------------------------------------------------

	public static function execute_list_servers( array $input ): array {
		return self::list_servers( (int) ( $input['server_id'] ?? 0 ) );
	}

	public static function execute_backup_status( array $input ): array {
		$stale_hours = isset( $input['stale_hours'] ) && is_numeric( $input['stale_hours'] ) ? (float) $input['stale_hours'] : null;
		return self::backup_status( (int) ( $input['server_id'] ?? 0 ), $stale_hours );
	}

	public static function execute_list_volumes( array $input ): array {
		unset( $input );
		return self::list_volumes();
	}

	public static function execute_server_metrics( array $input ): array {
		return self::server_metrics(
			(int) ( $input['server_id'] ?? 0 ),
			(string) ( $input['type'] ?? '' ),
			isset( $input['hours'] ) && is_numeric( $input['hours'] ) ? (float) $input['hours'] : 1.0,
			(string) ( $input['start'] ?? '' ),
			(string) ( $input['end'] ?? '' ),
			(int) ( $input['step'] ?? 0 )
		);
	}

	// ------------------------------------------------------------------
	// Public operations.
	// ------------------------------------------------------------------

	/**
	 * List servers, or fetch one when $server_id is set.
	 *
	 * @param int $server_id Optional server ID.
	 * @return array<string,mixed>
	 */
	public static function list_servers( int $server_id = 0 ): array {
		$raw = self::fetch_servers( $server_id );
		if ( ! $raw['success'] ) {
			return $raw;
		}

		$servers = array_map( array( self::class, 'normalize_server' ), (array) ( $raw['servers'] ?? array() ) );

		return array(
			'success' => true,
			'servers' => $servers,
			'count'   => count( $servers ),
		);
	}

	/**
	 * Backup freshness for one server or every server in the project.
	 *
	 * @param int        $server_id   Optional server ID.
	 * @param float|null $stale_hours Optional threshold override.
	 * @return array<string,mixed>
	 */
	public static function backup_status( int $server_id = 0, ?float $stale_hours = null ): array {
		$threshold = self::stale_threshold( $stale_hours );

		$raw = self::fetch_servers( $server_id );
		if ( ! $raw['success'] ) {
			return $raw;
		}

		$now      = time();
		$statuses = array();
		foreach ( (array) ( $raw['servers'] ?? array() ) as $server ) {
			$server_key = (int) ( $server['id'] ?? 0 );
			$images     = self::api_get_all(
				'/images',
				array(
					'type'     => 'backup',
					'bound_to' => $server_key,
				),
				'images'
			);
			if ( ! $images['success'] ) {
				return $images;
			}

			$statuses[] = self::derive_backup_status( (array) $server, (array) ( $images['items'] ?? array() ), $now, $threshold );
		}

		return self::summarize_backup_statuses( $statuses, $threshold );
	}

	/**
	 * List every volume in the project.
	 *
	 * @return array<string,mixed>
	 */
	public static function list_volumes(): array {
		$raw = self::api_get_all( '/volumes', array(), 'volumes' );
		if ( ! $raw['success'] ) {
			return $raw;
		}

		$volumes = array_map( array( self::class, 'normalize_volume' ), (array) ( $raw['items'] ?? array() ) );
		$total   = 0.0;
		foreach ( $volumes as $volume ) {
			$total += (float) $volume['size_gb'];
		}

		return array(
			'success'       => true,
			'volumes'       => $volumes,
			'count'         => count( $volumes ),
			'total_size_gb' => $total,
		);
	}

	/**
	 * Server metrics for a time window.
	 *
	 * @param int    $server_id Server ID.
	 * @param string $types     Comma-separated metric types (empty = all).
	 * @param float  $hours     Window length in hours ending at $end (used when $start is empty).
	 * @param string $start     ISO 8601 window start.
	 * @param string $end       ISO 8601 window end (empty = now).
	 * @param int    $step      Optional resolution in seconds.
	 * @return array<string,mixed>
	 */
	public static function server_metrics( int $server_id, string $types = '', float $hours = 1.0, string $start = '', string $end = '', int $step = 0 ): array {
		if ( $server_id <= 0 ) {
			return self::error( 'server_id is required.', 'invalid_input' );
		}

		$requested = '' === trim( $types ) ? self::METRIC_TYPES : array_values( array_filter( array_map( 'trim', explode( ',', strtolower( $types ) ) ) ) );
		$invalid   = array_diff( $requested, self::METRIC_TYPES );
		if ( ! empty( $invalid ) || empty( $requested ) ) {
			return self::error( 'Invalid metric type(s): ' . implode( ', ', $invalid ) . '. Allowed: ' . implode( ', ', self::METRIC_TYPES ) . '.', 'invalid_input' );
		}

		$end_ts = '' === $end ? time() : strtotime( $end );
		if ( false === $end_ts ) {
			return self::error( 'end must be an ISO 8601 timestamp.', 'invalid_input' );
		}

		if ( '' !== $start ) {
			$start_ts = strtotime( $start );
			if ( false === $start_ts ) {
				return self::error( 'start must be an ISO 8601 timestamp.', 'invalid_input' );
			}
		} else {
			$hours    = $hours > 0 ? $hours : 1.0;
			$start_ts = (int) ( $end_ts - ( $hours * 3600 ) );
		}

		if ( $start_ts >= $end_ts ) {
			return self::error( 'start must be before end.', 'invalid_input' );
		}

		$query = array(
			'type'  => implode( ',', array_values( array_unique( $requested ) ) ),
			'start' => gmdate( 'Y-m-d\TH:i:s\Z', $start_ts ),
			'end'   => gmdate( 'Y-m-d\TH:i:s\Z', $end_ts ),
		);
		if ( $step > 0 ) {
			$query['step'] = $step;
		}

		$response = self::api_get( '/servers/' . $server_id . '/metrics', $query );
		if ( ! $response['success'] ) {
			return $response;
		}

		$metrics     = is_array( $response['body']['metrics'] ?? null ) ? $response['body']['metrics'] : array();
		$time_series = is_array( $metrics['time_series'] ?? null ) ? $metrics['time_series'] : array();

		return array(
			'success'     => true,
			'server_id'   => $server_id,
			'start'       => (string) ( $metrics['start'] ?? $query['start'] ),
			'end'         => (string) ( $metrics['end'] ?? $query['end'] ),
			'step'        => (float) ( $metrics['step'] ?? 0 ),
			'summary'     => self::summarize_time_series( $time_series ),
			'time_series' => $time_series,
		);
	}

	// ------------------------------------------------------------------
	// Pure derivation helpers (unit-testable without HTTP).
	// ------------------------------------------------------------------

	/**
	 * Derive backup freshness for one server from its backup images.
	 *
	 * @param array<string,mixed>             $server    Raw Hetzner server object.
	 * @param array<int,mixed>                $images    Raw Hetzner images with type=backup bound to the server.
	 * @param int                             $now       Current Unix timestamp.
	 * @param float                           $threshold Staleness threshold in hours.
	 * @return array<string,mixed>
	 */
	public static function derive_backup_status( array $server, array $images, int $now, float $threshold ): array {
		$backup_window    = isset( $server['backup_window'] ) && is_string( $server['backup_window'] ) && '' !== $server['backup_window'] ? $server['backup_window'] : null;
		$backups_disabled = null === $backup_window;

		$backups = array();
		$latest  = null;
		foreach ( $images as $image ) {
			if ( ! is_array( $image ) ) {
				continue;
			}

			$created_ts = isset( $image['created'] ) ? strtotime( (string) $image['created'] ) : false;
			$status     = (string) ( $image['status'] ?? '' );
			$backups[]  = array(
				'id'            => (int) ( $image['id'] ?? 0 ),
				'description'   => (string) ( $image['description'] ?? '' ),
				'created'       => false !== $created_ts ? gmdate( 'c', $created_ts ) : null,
				'status'        => $status,
				'image_size_gb' => isset( $image['image_size'] ) ? (float) $image['image_size'] : null,
				'disk_size_gb'  => isset( $image['disk_size'] ) ? (float) $image['disk_size'] : null,
			);

			// Only completed backups count toward freshness.
			if ( false !== $created_ts && 'available' === $status && ( null === $latest || $created_ts > $latest ) ) {
				$latest = $created_ts;
			}
		}

		usort(
			$backups,
			static fn( array $a, array $b ): int => strcmp( (string) $b['created'], (string) $a['created'] )
		);

		$age_hours = null === $latest ? null : round( max( 0, $now - $latest ) / 3600, 1 );
		$stale     = null === $age_hours || $age_hours > $threshold;

		if ( $backups_disabled ) {
			$state = 'disabled';
		} elseif ( null === $latest ) {
			$state = 'missing';
		} elseif ( $stale ) {
			$state = 'stale';
		} else {
			$state = 'ok';
		}

		return array(
			'server_id'        => (int) ( $server['id'] ?? 0 ),
			'server_name'      => (string) ( $server['name'] ?? '' ),
			'backup_window'    => $backup_window,
			'backups_disabled' => $backups_disabled,
			'last_backup_at'   => null === $latest ? null : gmdate( 'c', $latest ),
			'backup_age_hours' => $age_hours,
			'stale'            => $stale,
			'count'            => count( $backups ),
			'state'            => $state,
			'backups'          => $backups,
		);
	}

	/**
	 * Roll per-server backup statuses into one health-style result.
	 *
	 * @param array<int,array<string,mixed>> $statuses  Output of derive_backup_status().
	 * @param float                           $threshold Staleness threshold in hours.
	 * @return array<string,mixed>
	 */
	public static function summarize_backup_statuses( array $statuses, float $threshold ): array {
		$issues = array();
		foreach ( $statuses as $status ) {
			$label = '' !== $status['server_name'] ? $status['server_name'] : '#' . $status['server_id'];
			switch ( $status['state'] ) {
				case 'disabled':
					$issues[] = sprintf( 'Hetzner backups are disabled for %s (no backup_window).', $label );
					break;
				case 'missing':
					$issues[] = sprintf( 'Hetzner server %s has no completed backups.', $label );
					break;
				case 'stale':
					$issues[] = sprintf( 'Hetzner backup for %s is %sh old (threshold %sh).', $label, self::format_number( (float) $status['backup_age_hours'] ), self::format_number( $threshold ) );
					break;
			}
		}

		$healthy = empty( $issues );

		return array(
			'success'     => true,
			'healthy'     => $healthy,
			'stale_hours' => $threshold,
			'servers'     => $statuses,
			'issues'      => $issues,
			'message'     => $healthy
				? sprintf( '%d server(s) backed up within %sh.', count( $statuses ), self::format_number( $threshold ) )
				: implode( ' ', $issues ),
		);
	}

	/**
	 * Normalize a raw server object to the public row contract.
	 *
	 * @param array<string,mixed> $server Raw Hetzner server.
	 * @return array<string,mixed>
	 */
	public static function normalize_server( array $server ): array {
		$backup_window = isset( $server['backup_window'] ) && is_string( $server['backup_window'] ) && '' !== $server['backup_window'] ? $server['backup_window'] : null;

		return array(
			'id'                 => (int) ( $server['id'] ?? 0 ),
			'name'               => (string) ( $server['name'] ?? '' ),
			'status'             => (string) ( $server['status'] ?? '' ),
			'server_type'        => (string) ( $server['server_type']['name'] ?? '' ),
			'cores'              => isset( $server['server_type']['cores'] ) ? (int) $server['server_type']['cores'] : null,
			'memory_gb'          => isset( $server['server_type']['memory'] ) ? (float) $server['server_type']['memory'] : null,
			'disk_gb'            => isset( $server['server_type']['disk'] ) ? (float) $server['server_type']['disk'] : null,
			'location'           => (string) ( $server['datacenter']['location']['name'] ?? '' ),
			'datacenter'         => (string) ( $server['datacenter']['name'] ?? '' ),
			'public_ipv4'        => (string) ( $server['public_net']['ipv4']['ip'] ?? '' ),
			'backup_window'      => $backup_window,
			'backups_enabled'    => null !== $backup_window,
			'delete_protection'  => ! empty( $server['protection']['delete'] ),
			'rebuild_protection' => ! empty( $server['protection']['rebuild'] ),
			'volumes'            => array_values( array_map( 'intval', is_array( $server['volumes'] ?? null ) ? $server['volumes'] : array() ) ),
			'created'            => (string) ( $server['created'] ?? '' ),
		);
	}

	/**
	 * Normalize a raw volume object to the public row contract.
	 *
	 * @param array<string,mixed> $volume Raw Hetzner volume.
	 * @return array<string,mixed>
	 */
	public static function normalize_volume( array $volume ): array {
		return array(
			'id'                => (int) ( $volume['id'] ?? 0 ),
			'name'              => (string) ( $volume['name'] ?? '' ),
			'size_gb'           => (float) ( $volume['size'] ?? 0 ),
			'server_id'         => isset( $volume['server'] ) ? (int) $volume['server'] : null,
			'location'          => (string) ( $volume['location']['name'] ?? '' ),
			'status'            => (string) ( $volume['status'] ?? '' ),
			'format'            => isset( $volume['format'] ) ? (string) $volume['format'] : null,
			'linux_device'      => (string) ( $volume['linux_device'] ?? '' ),
			'delete_protection' => ! empty( $volume['protection']['delete'] ),
			'created'           => (string) ( $volume['created'] ?? '' ),
		);
	}

	/**
	 * Per-series latest/avg/max summary for Hetzner metric time series.
	 *
	 * @param array<string,mixed> $time_series Hetzner `metrics.time_series`.
	 * @return array<string,array<string,mixed>>
	 */
	public static function summarize_time_series( array $time_series ): array {
		$summary = array();
		foreach ( $time_series as $name => $series ) {
			$values = array();
			foreach ( (array) ( $series['values'] ?? array() ) as $point ) {
				if ( is_array( $point ) && isset( $point[1] ) && is_numeric( $point[1] ) ) {
					$values[] = (float) $point[1];
				}
			}

			$summary[ (string) $name ] = array(
				'points' => count( $values ),
				'latest' => empty( $values ) ? null : end( $values ),
				'avg'    => empty( $values ) ? null : round( array_sum( $values ) / count( $values ), 4 ),
				'max'    => empty( $values ) ? null : max( $values ),
			);
		}

		return $summary;
	}

	// ------------------------------------------------------------------
	// Configuration.
	// ------------------------------------------------------------------

	public static function is_configured(): bool {
		$config = self::get_config();
		return ! empty( $config['api_token'] ) && is_string( $config['api_token'] );
	}

	/**
	 * Stored configuration, filterable so deployments can inject secrets
	 * without persisting them in the network option.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_config(): array {
		$config = get_site_option( self::CONFIG_OPTION, array() );
		if ( is_string( $config ) ) {
			$decoded = json_decode( $config, true );
			$config  = is_array( $decoded ) ? $decoded : array();
		}
		$config = is_array( $config ) ? $config : array();

		/**
		 * Filter the Hetzner Cloud connection configuration.
		 *
		 * @param array $config Keys: api_token.
		 */
		/** @var mixed $filtered Third-party filters may return anything. */
		$filtered = apply_filters( 'datamachine_hetzner_config', $config );

		return is_array( $filtered ) ? $filtered : array();
	}

	/**
	 * Resolve the staleness threshold in hours.
	 *
	 * @param float|null $override Explicit override (e.g. ability input).
	 * @return float
	 */
	public static function stale_threshold( ?float $override = null ): float {
		/**
		 * Filter the Hetzner backup staleness threshold in hours.
		 *
		 * @param float $hours Default 26.
		 */
		$threshold = (float) apply_filters( 'datamachine_hetzner_backup_stale_hours', (float) self::DEFAULT_STALE_HOURS );
		if ( null !== $override && $override > 0 ) {
			$threshold = $override;
		}

		return $threshold > 0 ? $threshold : (float) self::DEFAULT_STALE_HOURS;
	}

	// ------------------------------------------------------------------
	// HTTP layer.
	// ------------------------------------------------------------------

	/**
	 * Fetch raw server objects (one by ID, or all with pagination).
	 *
	 * @param int $server_id Optional server ID.
	 * @return array<string,mixed> {success:bool, servers?:array, error?:string}
	 */
	private static function fetch_servers( int $server_id ): array {
		if ( $server_id > 0 ) {
			$response = self::api_get( '/servers/' . $server_id );
			if ( ! $response['success'] ) {
				return $response;
			}

			$server = $response['body']['server'] ?? null;
			return array(
				'success' => true,
				'servers' => is_array( $server ) ? array( $server ) : array(),
			);
		}

		$all = self::api_get_all( '/servers', array(), 'servers' );
		if ( ! $all['success'] ) {
			return $all;
		}

		return array(
			'success' => true,
			'servers' => (array) ( $all['items'] ?? array() ),
		);
	}

	/**
	 * GET a paginated collection, following meta.pagination.next_page.
	 *
	 * @param string               $path           API path.
	 * @param array<string,mixed> $query          Query args.
	 * @param string               $collection_key Response key holding the items (e.g. "servers").
	 * @return array<string,mixed> {success:bool, items?:array, pages?:int, error?:string}
	 */
	private static function api_get_all( string $path, array $query, string $collection_key ): array {
		$items = array();
		$page  = 1;
		$pages = 0;

		while ( null !== $page && $pages < self::MAX_PAGES ) {
			$response = self::api_get(
				$path,
				array_merge(
					$query,
					array(
						'page'     => $page,
						'per_page' => self::PER_PAGE,
					)
				)
			);
			if ( ! $response['success'] ) {
				return $response;
			}

			++$pages;
			$batch = $response['body'][ $collection_key ] ?? array();
			if ( is_array( $batch ) ) {
				foreach ( $batch as $item ) {
					if ( is_array( $item ) ) {
						$items[] = $item;
					}
				}
			}

			$next = $response['body']['meta']['pagination']['next_page'] ?? null;
			$page = is_numeric( $next ) && (int) $next > $page ? (int) $next : null;
		}

		return array(
			'success' => true,
			'items'   => $items,
			'pages'   => $pages,
		);
	}

	/**
	 * Perform one GET against the Hetzner Cloud API and normalize the result.
	 *
	 * Uses wp_remote_get so the transport is mockable via pre_http_request.
	 * The bearer token only ever appears in the Authorization header; error
	 * messages are scrubbed of it before being returned.
	 *
	 * @param string               $path  API path (leading slash).
	 * @param array<string,mixed> $query Query args.
	 * @return array<string,mixed> {success:bool, body?:array, status_code?:int, error?:string, error_code?:string}
	 */
	private static function api_get( string $path, array $query = array() ): array {
		$config = self::get_config();
		$token  = is_string( $config['api_token'] ?? null ) ? trim( $config['api_token'] ) : '';

		if ( '' === $token ) {
			return self::error(
				'Hetzner is not configured. Set the "' . self::CONFIG_OPTION . '" network option with a read-only api_token.',
				'not_configured'
			);
		}

		$url = self::API_BASE . $path;
		if ( ! empty( $query ) ) {
			// Hetzner expects literal commas in `type=cpu,disk,network`.
			$url .= '?' . str_replace( '%2C', ',', http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::error(
				self::redact( 'Hetzner API request failed: ' . $response->get_error_message(), $token ),
				'http_request_failed'
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$reset_header    = wp_remote_retrieve_header( $response, 'ratelimit-reset' );
			$reset_header    = is_array( $reset_header ) ? (string) reset( $reset_header ) : (string) $reset_header;
			$mapped          = self::map_api_error( $status, is_array( $decoded ) ? $decoded : null, $reset_header );
			$mapped['error'] = self::redact( (string) $mapped['error'], $token );
			return $mapped;
		}

		if ( ! is_array( $decoded ) ) {
			return self::error( 'Hetzner API returned an unparseable response.', 'invalid_response', $status );
		}

		return array(
			'success'     => true,
			'body'        => $decoded,
			'status_code' => $status,
		);
	}

	/**
	 * Map a non-2xx Hetzner response to a normalized error result.
	 *
	 * Hetzner error bodies look like {"error":{"code":"...","message":"..."}}.
	 *
	 * @param int                      $status          HTTP status.
	 * @param array<string,mixed>|null $decoded         Decoded body.
	 * @param string                   $ratelimit_reset RateLimit-Reset header (Unix timestamp) when present.
	 * @return array<string,mixed> {success:false, error:string, error_code:string, status_code:int}
	 */
	public static function map_api_error( int $status, ?array $decoded, string $ratelimit_reset = '' ): array {
		$api_code    = is_string( $decoded['error']['code'] ?? null ) ? $decoded['error']['code'] : '';
		$api_message = is_string( $decoded['error']['message'] ?? null ) ? $decoded['error']['message'] : '';
		$detail      = '' !== $api_message ? ': ' . $api_message : '';

		switch ( $status ) {
			case 401:
				$message = 'Hetzner API rejected the token (401 unauthorized)' . $detail . '. Check the api_token in the "' . self::CONFIG_OPTION . '" network option.';
				$code    = '' !== $api_code ? $api_code : 'unauthorized';
				break;
			case 403:
				$message = 'Hetzner API denied access (403 forbidden)' . $detail . '. The token may lack permission for this project resource.';
				$code    = '' !== $api_code ? $api_code : 'forbidden';
				break;
			case 429:
				$retry = '';
				if ( is_numeric( $ratelimit_reset ) && (int) $ratelimit_reset > 0 ) {
					$retry = ' Rate limit resets at ' . gmdate( 'c', (int) $ratelimit_reset ) . '.';
				}
				$message = 'Hetzner API rate limit exceeded (429)' . $detail . '.' . $retry;
				$code    = '' !== $api_code ? $api_code : 'rate_limit_exceeded';
				break;
			default:
				$message = 'Hetzner API error (HTTP ' . $status . ')' . $detail . '.';
				$code    = '' !== $api_code ? $api_code : 'http_' . $status;
		}

		return self::error( $message, $code, $status );
	}

	/**
	 * Build a normalized error result.
	 *
	 * @return array<string,mixed> {success:false, error:string, error_code:string, status_code?:int}
	 */
	private static function error( string $message, string $code, int $status = 0 ): array {
		$error = array(
			'success'    => false,
			'error'      => $message,
			'error_code' => $code,
		);
		if ( $status > 0 ) {
			$error['status_code'] = $status;
		}
		return $error;
	}

	/**
	 * Remove the API token from a message, defensively.
	 */
	private static function redact( string $message, string $token ): string {
		return '' === $token ? $message : str_replace( $token, '[redacted]', $message );
	}

	private static function format_number( float $value ): string {
		return rtrim( rtrim( number_format( $value, 1, '.', '' ), '0' ), '.' );
	}
}
