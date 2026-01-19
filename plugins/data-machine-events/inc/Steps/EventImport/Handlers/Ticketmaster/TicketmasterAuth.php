<?php
/**
 * Ticketmaster Authentication Provider
 *
 * Handles authentication configuration for Ticketmaster Discovery API integration
 * with Data Machine's centralized authentication system.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster
 * @since 1.0.0
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster;

use DataMachine\Core\OAuth\BaseAuthProvider;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TicketmasterAuth class
 *
 * Authentication provider for Ticketmaster Discovery API.
 * Integrates with Data Machine's unified authentication system.
 */
class TicketmasterAuth extends BaseAuthProvider {

	public function __construct() {
		parent::__construct( 'ticketmaster' );
	}

	/**
	 * Get configuration fields required for Ticketmaster authentication
	 *
	 * @return array Configuration field definitions
	 */
	public function get_config_fields(): array {
		return array(
			'api_key' => array(
				'label'       => __( 'API Key', 'datamachine-events' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Your Ticketmaster Discovery API Consumer Key from developer.ticketmaster.com', 'datamachine-events' ),
			),
		);
	}

	/**
	 * Check if Ticketmaster is authenticated (same as configured for API key auth)
	 *
	 * @return bool True if API key is present, false otherwise
	 */
	public function is_authenticated(): bool {
		$credentials = $this->get_account();
		return ! empty( $credentials['api_key'] );
	}

	/**
	 * Get account details for display (API key type doesn't have account info)
	 *
	 * @return array|null Account details or null
	 */
	public function get_account_details(): ?array {
		if ( ! $this->is_authenticated() ) {
			return null;
		}

		return array(
			'display_name' => __( 'Ticketmaster API', 'datamachine-events' ),
			'type'         => __( 'API Key Authentication', 'datamachine-events' ),
		);
	}
}
