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
		// Build search criteria from config.
		$search_criteria = $this->buildSearchCriteria( $config );

		// Build ability input.
		$ability_input = array(
			'auth_ref'             => $config['auth_ref'] ?? 'email_imap:default',
			'folder'               => $config['folder'] ?? 'INBOX',
			'search_criteria'      => $search_criteria,
			'max_messages'         => (int) ( $config['max_messages'] ?? 10 ),
			'search'               => (string) ( $config['search'] ?? '' ),
			'exclude_keywords'     => (string) ( $config['exclude_keywords'] ?? '' ),
			'mark_as_read'         => ! empty( $config['mark_as_read'] ),
			'download_attachments' => ! empty( $config['download_attachments'] ),
		);

		// Delegate to ability.
		$ability         = new FetchEmailAbility();
		$trusted_context = array(
			'user_id'               => get_current_user_id(),
			'agent_id'              => $context->getAgentId(),
			'flow_id'               => $context->getFlowId(),
			'pipeline_id'           => $context->getPipelineId(),
			'flow_step_id'          => $context->getFlowStepId(),
			'job_id'                => $context->getJobId(),
			'principal_less_system' => null === $context->getAgentId() && 0 === get_current_user_id(),
			'legacy_default_auth'   => (string) ( $config['_legacy_default_auth'] ?? '' ),
		);
		$result          = $ability->executeWithContext( $ability_input, $trusted_context );

		if ( is_wp_error( $result ) ) {
			$context->log( 'error', 'Email fetch failed: ' . $result->get_error_message() );
			return array();
		}

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
	 * Starts with the base search criteria and augments it with the sender,
	 * subject and timeframe filters. PHP's c-client imap_search() rejects the
	 * IMAP OR keyword, so comma-separated sender/subject values produce one
	 * criteria string per combination; the ability merges and dedupes the UIDs.
	 *
	 * @param array $config Handler configuration.
	 * @return string|string[] One criteria string, or a list of OR'd alternatives.
	 */
	private function buildSearchCriteria( array $config ): string|array {
		$base     = (string) ( $config['search_criteria'] ?? 'UNSEEN' );
		$senders  = $this->splitFilterValues( (string) ( $config['from_filter'] ?? '' ) );
		$subjects = $this->splitFilterValues( (string) ( $config['subject_filter'] ?? '' ) );

		$since = '';
		if ( ! empty( $config['timeframe_limit'] ) && 'all_time' !== $config['timeframe_limit'] ) {
			$since_date = $this->timeframeToDate( (string) $config['timeframe_limit'] );
			if ( null !== $since_date ) {
				$since = ' SINCE "' . $since_date . '"';
			}
		}

		$searches = array();
		foreach ( ( empty( $senders ) ? array( '' ) : $senders ) as $sender ) {
			foreach ( ( empty( $subjects ) ? array( '' ) : $subjects ) as $subject ) {
				$search = $base;
				if ( '' !== $sender ) {
					$search .= ' FROM "' . $this->quoteImapString( $sender ) . '"';
				}
				if ( '' !== $subject ) {
					$search .= ' SUBJECT "' . $this->quoteImapString( $subject ) . '"';
				}
				$searches[] = $search . $since;
			}
		}

		return 1 === count( $searches ) ? $searches[0] : $searches;
	}

	/**
	 * Split a comma-separated filter value into trimmed, non-empty parts.
	 *
	 * @param string $value Raw filter value.
	 * @return string[]
	 */
	private function splitFilterValues( string $value ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), static fn( string $value ): bool => '' !== $value ) );
	}

	/**
	 * Escape a value for use inside an IMAP quoted string.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function quoteImapString( string $value ): string {
		return str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value );
	}

	/**
	 * Convert timeframe limit to IMAP date string.
	 *
	 * @param string $timeframe Timeframe identifier.
	 * @return string|null IMAP-formatted date or null.
	 */
	private function timeframeToDate( string $timeframe ): ?string {
		$timestamp = apply_filters( 'datamachine_timeframe_limit', null, $timeframe );
		if ( ! is_numeric( $timestamp ) ) {
			do_action( 'datamachine_log', 'warning', 'Unknown email timeframe_limit: ' . $timeframe );
			return null;
		}
		return gmdate( 'j-M-Y', (int) $timestamp );
	}
}
