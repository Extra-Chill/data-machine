<?php
/**
 * Modal System Component Filter Registration
 *
 * "Plugins Within Plugins" Architecture Implementation
 *
 * This file provides CSS infrastructure for the modal system.
 * Individual components now pre-render their own modals in page templates
 * instead of loading content via AJAX.
 *
 * @package DataMachine
 * @subpackage Core\Admin\Modal
 * @since 0.1.0
 */

namespace DataMachine\Core\Admin\Modal;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register Modal System infrastructure filters
 *
 * Provides shared modal JavaScript utilities and CSS infrastructure.
 * Components pre-render their own modal content in page templates.
 *
 * @since 0.1.0
 */
function datamachine_register_modal_system_filters() {
	add_action( 'admin_enqueue_scripts', 'DataMachine\\Core\\Admin\\Modal\\enqueue_modal_manager_script' );
}

/**
 * Enqueue shared modal manager JavaScript
 *
 * Provides reusable modal utilities for Jobs, Settings, and other vanilla JS pages.
 *
 * @since 0.2.0
 */
function enqueue_modal_manager_script() {
	wp_enqueue_script(
		'datamachine-modal-manager',
		DATAMACHINE_URL . 'inc/Core/Admin/Modal/assets/js/modal-manager.js',
		array(),
		DATAMACHINE_VERSION,
		true
	);
}

// Auto-register when file loads - achieving complete self-containment
datamachine_register_modal_system_filters();
