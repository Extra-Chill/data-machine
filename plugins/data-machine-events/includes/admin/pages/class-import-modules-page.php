<?php
/**
 * Import Modules Admin Page for Chill Events
 *
 * Handles the main dashboard for managing import modules.
 *
 * @package ChillEvents
 * @author Chris Huber
 * @link https://chubes.net
 * @since 1.0.0
 */

namespace ChillEvents\Admin\Pages;

if (!class_exists('ChillEvents\\Admin\\Pages\\BaseAdminPage')) {
    require_once __DIR__ . '/class-base-admin-page.php';
}

if (!defined('ABSPATH')) {
    exit;
}

class ImportModulesPage extends BaseAdminPage {
    
    /**
     * Initialize page properties
     */
    protected function init() {
        $this->page_slug = 'chill-events';
        $this->page_title = __('Import Modules', 'chill-events');
        $this->menu_title = __('Import Modules', 'chill-events');
    }
    
    /**
     * Handle admin_init actions
     */
    public function admin_init() {
        // Handle form submissions and actions
        if (isset($_GET['action'])) {
            $this->handle_actions();
        }
    }
    
    /**
     * Enqueue page-specific scripts and styles
     */
    public function enqueue_scripts() {
        $version = filemtime(CHILL_EVENTS_PLUGIN_DIR . 'assets/js/admin.js');
        wp_enqueue_script(
            'chill-events-admin',
            CHILL_EVENTS_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            $version,
            true
        );
        
        // Pass data to JS
        wp_localize_script(
            'chill-events-admin',
            'ChillEventsAdmin',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce_load_module' => wp_create_nonce('load_module')
            )
        );
        
        $version = filemtime(CHILL_EVENTS_PLUGIN_DIR . 'assets/css/admin.css');
        wp_enqueue_style(
            'chill-events-admin',
            CHILL_EVENTS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            $version
        );
    }
    
    /**
     * Handle page actions
     */
    private function handle_actions() {
        $action = sanitize_text_field($_GET['action']);
        
        switch ($action) {
            case 'delete':
                $this->handle_delete_module();
                break;
            case 'run_now':
                $this->handle_run_module();
                break;
            case 'repair_database':
                $this->handle_repair_database();
                break;
        }
    }
    
    /**
     * Handle module deletion
     */
    private function handle_delete_module() {
        if (!isset($_GET['module_id'])) {
            return; // Not a Chill Events module delete action
        }
        $module_id = intval($_GET['module_id']);
        
        if (!wp_verify_nonce($_GET['_wpnonce'], 'delete_module_' . $module_id)) {
            wp_die(__('Security check failed.', 'chill-events'));
        }
        
        if (!$this->can_access()) {
            wp_die(__('You do not have sufficient permissions.', 'chill-events'));
        }
        
        global $wpdb;
        $table = \ChillEvents\Database::get_modules_table();
        $result = $wpdb->delete($table, array('id' => $module_id), array('%d'));
        
        if ($result !== false) {
            $this->redirect_with_message('deleted');
        } else {
            $this->redirect_with_message('error');
        }
    }
    
    /**
     * Handle running a module
     */
    private function handle_run_module() {
        // Deprecated: AJAX now handles Run Now. This method is no longer used.
        $this->redirect_with_message('saved');
    }
    
    /**
     * Handle database repair
     */
    private function handle_repair_database() {
        if (!wp_verify_nonce($_GET['_wpnonce'], 'repair_database')) {
            wp_die(__('Security check failed.', 'chill-events'));
        }
        
        if (!$this->can_access()) {
            wp_die(__('You do not have sufficient permissions.', 'chill-events'));
        }
        
        $database = new \ChillEvents\Database();
        $database->force_recreate_tables();
        
        $this->redirect_with_message('saved');
    }
    
    /**
     * Render the admin page
     */
    public function render() {
        if (!$this->can_access()) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'chill-events'));
        }
        
        $this->render_header();
        $this->render_notices();
        $this->render_content();
        $this->render_module_creation_modal();
        $this->render_footer();
    }
    
    /**
     * Render main page content
     */
    private function render_content() {
        // Check database health
        $database = new \ChillEvents\Database();
        if (!$database->verify_table_structure()) {
            $this->render_database_error();
            return;
        }
        
        ?>
        <div class="chill-events-dashboard">
            <div class="chill-header-actions">
                <button type="button" class="button button-primary" id="chill-create-module">
                    <?php _e('Create Import Module', 'chill-events'); ?>
                </button>
            </div>
            
            <div class="chill-modules-grid">
                <?php $this->render_modules(); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render database error notice
     */
    private function render_database_error() {
        $repair_url = wp_nonce_url(
            $this->get_page_url(array('action' => 'repair_database')),
            'repair_database'
        );
        
        ?>
        <div class="notice notice-error">
            <p><strong><?php _e('Database Issue Detected', 'chill-events'); ?></strong></p>
            <p><?php _e('The plugin database tables are missing or corrupted. Please repair the database to continue.', 'chill-events'); ?></p>
            <p>
                <a href="<?php echo esc_url($repair_url); ?>" class="button button-primary">
                    <?php _e('Repair Database', 'chill-events'); ?>
                </a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Render all import modules
     */
    private function render_modules() {
        global $wpdb;
        $table = \ChillEvents\Database::get_modules_table();
        $modules = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");
        
        if (empty($modules)) {
            // Admin notice style – keeps interface simple and avoids duplicate buttons
            echo '<div class="notice notice-info inline"><p>' .
                 esc_html__( 'No Import Modules yet. Click "Create Import Module" to set up your first automated import.', 'chill-events' ) .
                 '</p></div>';
            return;
        }
        
        foreach ($modules as $module) {
            $this->render_module_card($module);
        }
    }
    
    /**
     * Render individual module card
     */
    private function render_module_card($module) {
        $taxonomy_mappings = maybe_unserialize($module->taxonomy_mappings);
        $status_color = $module->status === 'active' ? '#46b450' : '#999';
        $status_icon = $module->status === 'active' ? '●' : '○';
        
        // Get data source info
        $data_sources = \ChillEvents\DataSourceManager::get_available_data_sources();
        $source_info = null;
        foreach ($data_sources as $source) {
            if ($source['class'] === $module->data_source) {
                $source_info = $source;
                break;
            }
        }
        $source_name = $source_info ? $source_info['name'] : $module->data_source;
        
        echo '<div class="chill-module-card">';
        echo '<div class="module-header">';
        echo '<h3><span class="status-indicator" style="color: ' . $status_color . ';">' . $status_icon . '</span> ' . esc_html($module->name) . '</h3>';
        echo '<div class="module-actions">';
        echo '<a href="#" class="button button-small chill-edit-module" data-module-id="' . $module->id . '">' . __('Edit', 'chill-events') . '</a>';
        if ($module->status === 'active') {
            echo '<button type="button" class="button button-small chill-run-module-now" data-module-id="' . $module->id . '">' . __('Run Now', 'chill-events') . '</button>';
        }
        echo '<a href="' . wp_nonce_url($this->get_page_url(array('action' => 'delete', 'module_id' => $module->id)), 'delete_module_' . $module->id) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . __('Are you sure you want to delete this module?', 'chill-events') . '\')">' . __('Delete', 'chill-events') . '</a>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="module-info">';
        echo '<p><strong>' . __('Data Source:', 'chill-events') . '</strong> ' . esc_html($source_name) . '</p>';
        
        if (!empty($taxonomy_mappings) && is_array($taxonomy_mappings)) {
            $term_labels = array();
            foreach ($taxonomy_mappings as $tax_name => $term_id) {
                if ($term_id === 'skip' || !$term_id) {
                    continue;
                }
                $term = get_term(intval($term_id), $tax_name);
                if ($term && !is_wp_error($term)) {
                    $term_labels[] = $term->name;
                }
            }
            if (!empty($term_labels)) {
                echo '<p><strong>' . __('Taxonomies:', 'chill-events') . '</strong> ' . esc_html(implode(', ', $term_labels)) . '</p>';
            }
        }
        
        echo '<p><strong>' . __('Status:', 'chill-events') . '</strong> ' . ucfirst($module->status) . '</p>';
        echo '<p><strong>' . __('Max Events:', 'chill-events') . '</strong> ' . $module->max_events . '</p>';
        
        if ($module->last_run) {
            echo '<p><strong>' . __('Last Run:', 'chill-events') . '</strong> ' . human_time_diff(strtotime($module->last_run)) . ' ago (' . $module->last_run_events_imported . ' events imported)</p>';
        } else {
            echo '<p><strong>' . __('Last Run:', 'chill-events') . '</strong> Never</p>';
        }
        
        echo '</div>';
        echo '</div>';
    }
    
    /**
     * Render the module creation/editing modal
     */
    private function render_module_creation_modal() {
        ?>
        <div id="chill-module-modal" class="chill-modal" style="display: none;">
            <div class="chill-modal-content">
                <div class="chill-modal-header">
                    <h2 id="chill-modal-title"><?php _e('Create Import Module', 'chill-events'); ?></h2>
                    <span class="chill-modal-close">&times;</span>
                </div>
                
                <div class="chill-modal-body">
                    <form id="chill-module-form">
                        <?php wp_nonce_field('chill_module_save', 'chill_module_nonce'); ?>
                        <input type="hidden" id="chill-module-id" name="module_id" value="">
                        
                        <!-- Step 1: Basic Info & Data Source -->
                        <div class="chill-step" id="step-1">
                            <h3><?php _e('Step 1: Basic Information', 'chill-events'); ?></h3>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="module-name"><?php _e('Module Name', 'chill-events'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <input type="text" id="module-name" name="module_name" class="regular-text" required>
                                        <p class="description"><?php _e('A descriptive name for this import module.', 'chill-events'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="data-source"><?php _e('Data Source', 'chill-events'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <select id="data-source" name="data_source" class="regular-text" required>
                                            <option value=""><?php _e('Select Data Source', 'chill-events'); ?></option>
                                            <?php
                                            $data_sources = \ChillEvents\DataSourceManager::get_available_data_sources();
                                            foreach ($data_sources as $source) {
                                                echo '<option value="' . esc_attr($source['class']) . '">' . esc_html($source['name']) . '</option>';
                                            }
                                            ?>
                                        </select>
                                        <p class="description"><?php _e('Choose where to import events from.', 'chill-events'); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <!-- Step 2: Data Source Settings -->
                        <div class="chill-step" id="step-2" style="display: none;">
                            <h3><?php _e('Step 2: Data Source Configuration', 'chill-events'); ?></h3>
                            <div id="data-source-settings">
                                <!-- Dynamic content loaded via JavaScript -->
                            </div>
                        </div>
                        
                        <!-- Step 3: Taxonomy Mapping -->
                        <div class="chill-step" id="step-3" style="display: none;">
                            <h3><?php _e('Step 3: Taxonomy Mapping', 'chill-events'); ?></h3>
                            <p><?php _e('Map import fields to your site\'s taxonomies. You can skip any field you don\'t want to map. Note: Venue information is automatically handled by the core system.', 'chill-events'); ?></p>
                            <div id="taxonomy-mappings">
                                <!-- Dynamic content loaded via JavaScript -->
                            </div>
                        </div>
                        
                        <!-- Step 4: Review & Save -->
                        <div class="chill-step" id="step-4" style="display: none;">
                            <h3><?php _e('Step 4: Review & Save', 'chill-events'); ?></h3>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="module-status"><?php _e('Status', 'chill-events'); ?></label>
                                    </th>
                                    <td>
                                        <select id="module-status" name="status" class="regular-text">
                                            <option value="active"><?php _e('Active', 'chill-events'); ?></option>
                                            <option value="inactive"><?php _e('Inactive', 'chill-events'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="max-events"><?php _e('Max Events per Run', 'chill-events'); ?></label>
                                    </th>
                                    <td>
                                        <input type="number" id="max-events" name="max_events" value="50" min="1" max="500" class="small-text">
                                        <p class="description"><?php _e('Maximum number of events to import in each run.', 'chill-events'); ?></p>
                                    </td>
                                </tr>
                            </table>
                            
                            <div id="module-review">
                                <!-- Review content will be populated via JavaScript -->
                            </div>
                        </div>
                    </form>
                </div>
                
                <div class="chill-modal-footer">
                    <button type="button" id="chill-prev-btn" class="button" style="display: none;"><?php _e('Previous', 'chill-events'); ?></button>
                    <button type="button" id="chill-next-btn" class="button button-primary"><?php _e('Next', 'chill-events'); ?></button>
                    <button type="button" id="chill-save-btn" class="button button-primary" style="display: none;"><?php _e('Save Module', 'chill-events'); ?></button>
                    <button type="button" id="chill-cancel-btn" class="button"><?php _e('Cancel', 'chill-events'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
} 