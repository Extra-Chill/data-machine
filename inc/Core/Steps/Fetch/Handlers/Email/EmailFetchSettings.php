<?php
/**
 * Email Fetch Handler Settings
 *
 * Defines settings fields for the email fetch handler.
 * Extends base fetch handler settings with email-specific filtering.
 *
 * @package DataMachine\Core\Steps\Fetch\Handlers\Email
 */

namespace DataMachine\Core\Steps\Fetch\Handlers\Email;

use DataMachine\Core\Steps\Fetch\Handlers\FetchHandlerSettings;

defined( 'ABSPATH' ) || exit;

class EmailFetchSettings extends FetchHandlerSettings {

	/**
	 * Get settings fields for email fetch handler.
	 *
	 * @return array Associative array defining the settings fields.
	 */
	public static function get_fields(): array {
		$fields = array(
			'folder'               => array(
				'type'        => 'text',
				'label'       => __( 'Mail Folder', 'data-machine' ),
				'default'     => 'INBOX',
				'description' => __( 'IMAP folder to fetch from (e.g., INBOX, Sent, [Gmail]/All Mail).', 'data-machine' ),
			),
			'search_criteria'      => array(
				'type'        => 'text',
				'label'       => __( 'Search Criteria', 'data-machine' ),
				'default'     => 'UNSEEN',
				'description' => __( 'IMAP search string. Examples: UNSEEN, ALL, FLAGGED, FROM "user@example.com", SINCE "1-Mar-2026".', 'data-machine' ),
			),
			'from_filter'          => array(
				'type'        => 'text',
				'label'       => __( 'From Filter', 'data-machine' ),
				'description' => __( 'Only fetch emails from this sender address.', 'data-machine' ),
			),
			'subject_filter'       => array(
				'type'        => 'text',
				'label'       => __( 'Subject Filter', 'data-machine' ),
				'description' => __( 'Only fetch emails with this text in the subject.', 'data-machine' ),
			),
			'mark_as_read'         => array(
				'type'        => 'checkbox',
				'label'       => __( 'Mark as Read', 'data-machine' ),
				'default'     => false,
				'description' => __( 'Mark fetched messages as read (Seen) after processing.', 'data-machine' ),
			),
			'download_attachments' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Download Attachments', 'data-machine' ),
				'default'     => false,
				'description' => __( 'Download email attachments to local storage for pipeline processing.', 'data-machine' ),
			),
		);

		// Merge with common fetch handler fields (timeframe, keywords, max_items).
		return array_merge( $fields, parent::get_common_fields() );
	}
}
