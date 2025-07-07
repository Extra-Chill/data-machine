<?php
/**
 * Database management for Chill Events
 *
 * @package ChillEvents
 * @since 1.0.0
 */

namespace ChillEvents;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database class
 * 
 * Handles custom database tables for:
 * - Import Modules configuration
 * - Import Logs and analytics
 * 
 * @since 1.0.0
 */
class Database {
    
    /**
     * Database version for upgrade tracking
     * 
     * @var string
     */
    const DB_VERSION = '1.0.2';
    
    /**
     * Initialize database functionality
     * 
     * @since 1.0.0
     */
    public function __construct() {
        // Database operations handled through static methods
    }
    
    /**
     * Create or upgrade database tables
     * 
     * @since 1.0.0
     */
    public static function create_tables() {
        global $wpdb;
        
        // Check if we need to create/upgrade tables
        $installed_version = get_option('chill_events_db_version', '0');
        
        if (version_compare($installed_version, self::DB_VERSION, '<')) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            
            // Create import modules table
            self::create_import_modules_table();
            
            // Create import logs table
            self::create_import_logs_table();
            
            // Update version
            update_option('chill_events_db_version', self::DB_VERSION);
            
            // Log successful creation
            error_log('Chill Events database tables created/upgraded to version ' . self::DB_VERSION);
        }
    }
    
    /**
     * Force recreation of all tables (for troubleshooting)
     * 
     * @since 1.0.1
     */
    public static function force_recreate_tables() {
        global $wpdb;
        
        // Drop existing tables first
        $tables = array(
            $wpdb->prefix . 'chill_import_logs',
            $wpdb->prefix . 'chill_import_modules'
        );
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
        
        // Reset version to force recreation
        delete_option('chill_events_db_version');
        
        // Recreate tables
        self::create_tables();
        
        error_log('Chill Events: Tables forcibly recreated');
    }
    
    /**
     * Check if required columns exist and add them if missing
     * 
     * @since 1.0.1
     */
    public static function verify_table_structure() {
        global $wpdb;
        
        $modules_table = $wpdb->prefix . 'chill_import_modules';
        
        // Track whether we found critical issues that still need attention
        $issues_detected = false;
        
        // First check if table exists at all
        if ($wpdb->get_var("SHOW TABLES LIKE '$modules_table'") !== $modules_table) {
            error_log('Chill Events: Modules table does not exist, creating tables');
            self::create_tables();
            
            // We attempted to repair – flag issue so calling code can prompt refresh
            return false;
        }
        
        // Get all existing columns
        $existing_columns = $wpdb->get_results("SHOW COLUMNS FROM $modules_table");
        $column_names = array();
        foreach ($existing_columns as $column) {
            $column_names[] = $column->Field;
        }
        
        // Required columns for proper operation
        $required_columns = array(
            'id', 'name', 'slug', 'data_source', 'data_source_settings', 
            'taxonomy_mappings', 'meta_field_mappings', 'status', 'priority', 
            'max_events', 'last_run', 'last_run_status', 'last_run_events_imported',
            'last_run_errors', 'total_runs', 'total_events_imported', 'total_errors',
            'created_at', 'updated_at'
        );
        
        $missing_columns = array_diff($required_columns, $column_names);
        
        // If we're missing core columns like data_source_settings, the table is fundamentally broken
        if (in_array('data_source_settings', $missing_columns) || in_array('taxonomy_mappings', $missing_columns)) {
            error_log('Chill Events: Table structure is fundamentally broken, forcing recreation');
            self::force_recreate_tables();
            return false;
        }
        
        // If we're only missing a few non-critical columns, try to add them individually
        if (!empty($missing_columns)) {
            $issues_detected = true; // We will return false at the end so UI can suggest reload
            error_log('Chill Events: Missing columns: ' . implode(', ', $missing_columns));
            
            // Add missing columns one by one where possible
            if (in_array('taxonomy_mappings', $missing_columns)) {
                $wpdb->query("ALTER TABLE $modules_table ADD COLUMN taxonomy_mappings longtext AFTER data_source_settings");
                error_log('Chill Events: Added missing taxonomy_mappings column');
            }
            
            if (in_array('meta_field_mappings', $missing_columns)) {
                $wpdb->query("ALTER TABLE $modules_table ADD COLUMN meta_field_mappings longtext AFTER taxonomy_mappings");
                error_log('Chill Events: Added missing meta_field_mappings column');
            }
        }
        
        // If we reach here and no outstanding issues remain, return true
        return !$issues_detected;
    }
    
    /**
     * Create import modules table
     * 
     * Stores configuration for each Import Module:
     * - Data source type and settings
     * - Taxonomy mappings
     * - Meta field mappings
     * - Schedule and status
     * 
     * @since 1.0.0
     */
    private static function create_import_modules_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'chill_import_modules';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(200) NOT NULL,
            data_source varchar(100) NOT NULL,
            data_source_settings longtext,
            taxonomy_mappings longtext,
            meta_field_mappings longtext,
            status varchar(20) NOT NULL DEFAULT 'active',
            priority int(11) NOT NULL DEFAULT 10,
            max_events int(11) NOT NULL DEFAULT 50,
            last_run datetime DEFAULT NULL,
            last_run_status varchar(50) DEFAULT NULL,
            last_run_events_imported int(11) DEFAULT 0,
            last_run_errors longtext,
            total_runs bigint(20) unsigned NOT NULL DEFAULT 0,
            total_events_imported bigint(20) unsigned NOT NULL DEFAULT 0,
            total_errors bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY status (status),
            KEY data_source (data_source),
            KEY last_run (last_run),
            KEY priority (priority)
        ) $charset_collate;";
        
        dbDelta($sql);
        
        // Check if table was created successfully
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
            error_log('Chill Events: Import modules table created successfully');
        } else {
            error_log('Chill Events: Failed to create import modules table');
        }
    }
    
    /**
     * Create import logs table
     * 
     * Stores detailed logs for each import run:
     * - Per-module execution details
     * - Success/failure tracking
     * - Performance metrics
     * - Error details
     * 
     * @since 1.0.0
     */
    private static function create_import_logs_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'chill_import_logs';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            module_id bigint(20) unsigned NOT NULL,
            run_type varchar(50) NOT NULL DEFAULT 'scheduled',
            status varchar(50) NOT NULL,
            events_imported int(11) NOT NULL DEFAULT 0,
            events_updated int(11) NOT NULL DEFAULT 0,
            events_skipped int(11) NOT NULL DEFAULT 0,
            execution_time decimal(10,4) NOT NULL DEFAULT 0,
            memory_usage bigint(20) unsigned NOT NULL DEFAULT 0,
            error_message longtext,
            error_details longtext,
            import_data longtext,
            started_at datetime NOT NULL,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY module_id (module_id),
            KEY status (status),
            KEY started_at (started_at),
            KEY run_type (run_type),
            KEY execution_time (execution_time),
            CONSTRAINT fk_import_logs_module_id 
                FOREIGN KEY (module_id) 
                REFERENCES {$wpdb->prefix}chill_import_modules (id) 
                ON DELETE CASCADE
        ) $charset_collate;";
        
        dbDelta($sql);
        
        // Check if table was created successfully
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
            error_log('Chill Events: Import logs table created successfully');
        } else {
            error_log('Chill Events: Failed to create import logs table');
        }
    }
    
    /**
     * Drop all custom tables (for uninstall)
     * 
     * @since 1.0.0
     */
    public static function drop_tables() {
        global $wpdb;
        
        $tables = array(
            $wpdb->prefix . 'chill_import_logs',
            $wpdb->prefix . 'chill_import_modules'
        );
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
        
        // Remove version option
        delete_option('chill_events_db_version');
        
        error_log('Chill Events: Custom database tables dropped');
    }
    
    /**
     * Get import modules table name
     * 
     * @return string Table name
     * @since 1.0.0
     */
    public static function get_modules_table() {
        global $wpdb;
        return $wpdb->prefix . 'chill_import_modules';
    }
    
    /**
     * Get import logs table name
     * 
     * @return string Table name
     * @since 1.0.0
     */
    public static function get_logs_table() {
        global $wpdb;
        return $wpdb->prefix . 'chill_import_logs';
    }
    
    /**
     * Get database version
     * 
     * @return string Database version
     * @since 1.0.0
     */
    public static function get_db_version() {
        return get_option('chill_events_db_version', '0');
    }
    
    /**
     * Check if tables exist and are up to date
     * 
     * @return bool True if tables exist and are current
     * @since 1.0.0
     */
    public static function tables_exist() {
        global $wpdb;
        
        $modules_table = self::get_modules_table();
        $logs_table = self::get_logs_table();
        
        $modules_exists = $wpdb->get_var("SHOW TABLES LIKE '$modules_table'") === $modules_table;
        $logs_exists = $wpdb->get_var("SHOW TABLES LIKE '$logs_table'") === $logs_table;
        $version_current = version_compare(self::get_db_version(), self::DB_VERSION, '>=');
        
        return $modules_exists && $logs_exists && $version_current;
    }
    
    /**
     * Get table statistics for debugging
     * 
     * @return array Table statistics
     * @since 1.0.0
     */
    public static function get_table_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Modules table stats
        $modules_table = self::get_modules_table();
        $stats['modules_table_exists'] = $wpdb->get_var("SHOW TABLES LIKE '$modules_table'") === $modules_table;
        $stats['modules_count'] = $stats['modules_table_exists'] ? 
            $wpdb->get_var("SELECT COUNT(*) FROM $modules_table") : 0;
        
        // Logs table stats
        $logs_table = self::get_logs_table();
        $stats['logs_table_exists'] = $wpdb->get_var("SHOW TABLES LIKE '$logs_table'") === $logs_table;
        $stats['logs_count'] = $stats['logs_table_exists'] ? 
            $wpdb->get_var("SELECT COUNT(*) FROM $logs_table") : 0;
        
        // Version info
        $stats['db_version'] = self::get_db_version();
        $stats['expected_version'] = self::DB_VERSION;
        $stats['version_current'] = version_compare($stats['db_version'], self::DB_VERSION, '>=');
        
        return $stats;
    }
} 