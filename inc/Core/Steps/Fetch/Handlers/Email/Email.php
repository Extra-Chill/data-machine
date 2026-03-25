<?php
/**
 * Email fetch handler.
 *
 * Fetches emails from an IMAP inbox as a pipeline fetch step. Delegates to
 * FetchEmailAbility for core IMAP operations. The handler resolves IMAP
 * credentials from the auth provider and enriches search criteria from
 * handler config.
 *
 * @package DataMachine\Core\Steps\Fetch\Handlers\Email
 */

namespace DataMachine\Core\Steps\Fetch\Handlers\Email;

use DataMachine\Abilities\Fetch\FetchEmailAbility;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Email extends FetchHandler {
	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'email' );

		self::registerHandler(
			'email',
			'fetch',
			self::class,
			'Email Inbox',
			'Fetch emails from an IMAP inbox',
			true,
			EmailAuth::class,
			EmailFetchSettings::class,
			null,
			'email_imap'
		);
	}

	/**
	 * Fetch emails from IMAP inbox.
	 *
	 * Resolves IMAP credentials from auth provider, builds search criteria
	 * from handler config, and delegates to FetchEmailAbility.
	 *
	 * @param array            $config  Handler configuration.
	 * @param ExecutionContext  $context Execution context.
	 * @return array Array with 'items' key containing fetched messages.
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		// Resolve IMAP credentials from auth provider.
		$auth = $this->getAuthProvider( 'email_imap' );

		if ( ! $auth || ! $auth->is_authenticated() ) {
			$context->log( 'error', 'Email Fetch: IMAP credentials not configured. Set up authentication in handler settings.' );
			return array();
		}

		// Build search criteria from config.
		$search_criteria = $this->buildSearchCriteria( $config );

		// Build ability input.
		$ability_input = array(
			'imap_host'            => $auth->getHost(),
			'imap_port'            => $auth->getPort(),
			'imap_encryption'      => $auth->getEncryption(),
			'imap_user'            => $auth->getUser(),
			'imap_password'        => $auth->getPassword(),
			'folder'               => $config['folder'] ?? 'INBOX',
			'search_criteria'      => $search_criteria,
			'max_messages'         => (int) ( $config['max_messages'] ?? 10 ),
			'mark_as_read'         => ! empty( $config['mark_as_read'] ),
			'download_attachments' => ! empty( $config['download_attachments'] ),
		);

		// Delegate to ability.
		$ability = new FetchEmailAbility();
		$result  = $ability->execute( $ability_input );

		// Relay ability logs.
		if ( ! empty( $result['logs'] ) && is_array( $result['logs'] ) ) {
			foreach ( $result['logs'] as $log_entry ) {
				$context->log(
					$log_entry['level'] ?? 'debug',
					$log_entry['message'] ?? '',
					$log_entry['data'] ?? array()
				);
			}
		}

		if ( ! $result['success'] || empty( $result['data'] ) ) {
			return array();
		}

		$items = $result['data']['items'] ?? array();
		if ( empty( $items ) ) {
			return array();
		}

		// Items already have item_identifier (message_id) set by ability.
		return array( 'items' => $items );
	}

	/**
	 * Build IMAP search criteria from handler config.
	 *
	 * Starts with the base search criteria and augments with
	 * from_filter and timeframe_limit settings.
	 *
	 * @param array $config Handler configuration.
	 * @return string IMAP search criteria string.
	 */
	private function buildSearchCriteria( array $config ): string {
		$criteria = $config['search_criteria'] ?? 'UNSEEN';

		// Augment with sender filter.
		if ( ! empty( $config['from_filter'] ) ) {
			$criteria .= ' FROM "' . $config['from_filter'] . '"';
		}

		// Augment with subject filter.
		if ( ! empty( $config['subject_filter'] ) ) {
			$criteria .= ' SUBJECT "' . $config['subject_filter'] . '"';
		}

		// Augment with timeframe filter.
		if ( ! empty( $config['timeframe_limit'] ) && 'all_time' !== $config['timeframe_limit'] ) {
			$since_date = $this->timeframeToDate( $config['timeframe_limit'] );
			if ( $since_date ) {
				$criteria .= ' SINCE "' . $since_date . '"';
			}
		}

		return $criteria;
	}

	/**
	 * Convert timeframe limit to IMAP date string.
	 *
	 * @param string $timeframe Timeframe identifier.
	 * @return string|null IMAP-formatted date or null.
	 */
	private function timeframeToDate( string $timeframe ): ?string {
		$days_map = array(
			'24_hours' => 1,
			'7_days'   => 7,
			'30_days'  => 30,
			'90_days'  => 90,
			'6_months' => 180,
			'1_year'   => 365,
		);

		$days = $days_map[ $timeframe ] ?? null;
		if ( null === $days ) {
			return null;
		}

		return gmdate( 'j-M-Y', strtotime( "-{$days} days" ) );
	}
}
