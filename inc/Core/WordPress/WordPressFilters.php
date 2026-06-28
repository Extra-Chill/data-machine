<?php
/**
 * WordPress utilities service discovery and registration.
 *
 * Self-registration following established WordPress filter-based architecture.
 * Provides filter-based access to all WordPress handler utilities.
 *
 * @package DataMachine\Core\WordPress
 * @since 0.2.1
 */

namespace DataMachine\Core\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register WordPress utilities for filter-based service discovery
 */
function datamachine_register_wordpress_utilities() {
	// Legacy service discovery provided via filters was removed in favor of direct
	// instantiation and OOP usage. These helper classes are now initialized
	// directly by WordPress handler constructors and should not be discovered via
	// filters. We leave this file as a placeholder for compatibility but no
	// longer register the filters.
}

// Auto-execute registration
datamachine_register_wordpress_utilities();
