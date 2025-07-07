<?php
/**
 * Event Meta Box functionality
 *
 * @package ChillEvents
 * @since 1.0.0
 */

namespace ChillEvents\Events;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Event Meta Box class
 * 
 * Handles the core event meta fields in the post editor:
 * - Start date/time (required)
 * - End date/time (optional)
 * 
 * @since 1.0.0
 */
class EventMetaBox {
    
    /**
     * Initialize meta box functionality
     * 
     * @since 1.0.0
     */
    public function __construct() {
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_box'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    /**
     * Add meta boxes to event post type
     * 
     * @since 1.0.0
     */
    public function add_meta_boxes() {
        add_meta_box(
            'chill_event_details',
            __('Event Details', 'chill-events'),
            array($this, 'render_meta_box'),
            'chill_events',
            'normal',
            'high'
        );
    }
    
    /**
     * Render the event details meta box
     * 
     * @param WP_Post $post Current post object
     * @since 1.0.0
     */
    public function render_meta_box($post) {
        // Add nonce for security
        wp_nonce_field('chill_event_meta_box', 'chill_event_meta_nonce');
        
        // Get current values
        $start_date = get_post_meta($post->ID, '_chill_event_start_date', true);
        $end_date = get_post_meta($post->ID, '_chill_event_end_date', true);
        
        // Get enabled meta fields from settings
        $settings = get_option('chill_events_settings', array());
        $ticket_url_enabled = !empty($settings['meta_ticket_url']);
        $artist_name_enabled = !empty($settings['meta_artist_name']);
        
        // Get additional meta values
        $ticket_url = get_post_meta($post->ID, '_chill_event_ticket_url', true);
        $artist_name = get_post_meta($post->ID, '_chill_event_artist_name', true);
        
        // Convert datetime to separate date and time for easier input
        $start_date_only = '';
        $start_time_only = '';
        $end_date_only = '';
        $end_time_only = '';
        
        if ($start_date) {
            $start_datetime = new \DateTime($start_date);
            $start_date_only = $start_datetime->format('Y-m-d');
            $start_time_only = $start_datetime->format('H:i');
        }
        
        if ($end_date) {
            $end_datetime = new \DateTime($end_date);
            $end_date_only = $end_datetime->format('Y-m-d');
            $end_time_only = $end_datetime->format('H:i');
        }
        
        ?>
        <div class="chill-events-meta-box">
            <table class="form-table">
                <!-- Core Date/Time Fields (Always Required) -->
                <tr>
                    <th scope="row">
                        <label for="chill_event_start_date"><?php _e('Start Date', 'chill-events'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <input type="date" 
                               id="chill_event_start_date" 
                               name="chill_event_start_date" 
                               value="<?php echo esc_attr($start_date_only); ?>" 
                               required 
                               class="regular-text" />
                        <input type="time" 
                               id="chill_event_start_time" 
                               name="chill_event_start_time" 
                               value="<?php echo esc_attr($start_time_only); ?>" 
                               class="regular-text" />
                        <p class="description"><?php _e('When does the event start? Date is required, time is optional.', 'chill-events'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="chill_event_end_date"><?php _e('End Date', 'chill-events'); ?></label>
                    </th>
                    <td>
                        <input type="date" 
                               id="chill_event_end_date" 
                               name="chill_event_end_date" 
                               value="<?php echo esc_attr($end_date_only); ?>" 
                               class="regular-text" />
                        <input type="time" 
                               id="chill_event_end_time" 
                               name="chill_event_end_time" 
                               value="<?php echo esc_attr($end_time_only); ?>" 
                               class="regular-text" />
                        <p class="description"><?php _e('When does the event end? Optional - leave blank for single-time events.', 'chill-events'); ?></p>
                    </td>
                </tr>
                
                <!-- Venue Information (Handled by Taxonomy) -->
                <tr>
                    <th scope="row">
                        <label><?php _e('Venue', 'chill-events'); ?></label>
                    </th>
                    <td>
                        <p class="description"><?php _e('Venue information is managed through the Venue taxonomy. Use the Venues section in the sidebar to assign venues to this event.', 'chill-events'); ?></p>
                    </td>
                </tr>
                
                <!-- Artist/Performer Field (If Enabled in Settings) -->
                <?php if ($artist_name_enabled) : ?>
                <tr>
                    <th scope="row">
                        <label for="chill_event_artist_name"><?php _e('Artist/Performer', 'chill-events'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                               id="chill_event_artist_name" 
                               name="chill_event_artist_name" 
                               value="<?php echo esc_attr($artist_name); ?>" 
                               class="regular-text" />
                        <p class="description"><?php _e('Name of the artist, performer, or act.', 'chill-events'); ?></p>
                    </td>
                </tr>
                <?php endif; ?>
                
                <!-- Ticket URL Field (If Enabled in Settings) -->
                <?php if ($ticket_url_enabled) : ?>
                <tr>
                    <th scope="row">
                        <label for="chill_event_ticket_url"><?php _e('Ticket URL', 'chill-events'); ?></label>
                    </th>
                    <td>
                        <input type="url" 
                               id="chill_event_ticket_url" 
                               name="chill_event_ticket_url" 
                               value="<?php echo esc_attr($ticket_url); ?>" 
                               class="regular-text" />
                        <p class="description"><?php _e('URL where visitors can purchase tickets.', 'chill-events'); ?></p>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
        <?php
    }
    
    /**
     * Save meta box data
     * 
     * @param int $post_id Post ID
     * @since 1.0.0
     */
    public function save_meta_box($post_id) {
        // Check if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Check post type
        if (get_post_type($post_id) !== 'chill_events') {
            return;
        }
        
        // Check nonce
        if (!isset($_POST['chill_event_meta_nonce']) || 
            !wp_verify_nonce($_POST['chill_event_meta_nonce'], 'chill_event_meta_box')) {
            return;
        }
        
        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Get settings for meta field enable/disable
        $settings = get_option('chill_events_settings', array());
        
        // Process start date/time
        $start_date = '';
        if (!empty($_POST['chill_event_start_date'])) {
            $date_part = sanitize_text_field($_POST['chill_event_start_date']);
            $time_part = !empty($_POST['chill_event_start_time']) ? sanitize_text_field($_POST['chill_event_start_time']) : '00:00';
            
            // Combine date and time
            $start_datetime = $date_part . ' ' . $time_part;
            
            // Validate datetime
            if ($this->validate_datetime($start_datetime)) {
                $start_date = $start_datetime;
            }
        }
        
        // Process end date/time  
        $end_date = '';
        if (!empty($_POST['chill_event_end_date'])) {
            $date_part = sanitize_text_field($_POST['chill_event_end_date']);
            $time_part = !empty($_POST['chill_event_end_time']) ? sanitize_text_field($_POST['chill_event_end_time']) : '23:59';
            
            // Combine date and time
            $end_datetime = $date_part . ' ' . $time_part;
            
            // Validate datetime
            if ($this->validate_datetime($end_datetime)) {
                $end_date = $end_datetime;
            }
        }
        
        // Save or delete meta
        if (!empty($start_date)) {
            update_post_meta($post_id, '_chill_event_start_date', $start_date);
        } else {
            delete_post_meta($post_id, '_chill_event_start_date');
        }
        
        if (!empty($end_date)) {
            update_post_meta($post_id, '_chill_event_end_date', $end_date);
        } else {
            delete_post_meta($post_id, '_chill_event_end_date');
        }
        
        // Venue information is now handled by taxonomy - no post meta needed
        
        // Save artist name (if enabled in settings)
        if (!empty($settings['meta_artist_name'])) {
            $artist_name = isset($_POST['chill_event_artist_name']) ? sanitize_text_field($_POST['chill_event_artist_name']) : '';
            if (!empty($artist_name)) {
                update_post_meta($post_id, '_chill_event_artist_name', $artist_name);
            } else {
                delete_post_meta($post_id, '_chill_event_artist_name');
            }
        }
        
        // Save ticket URL (if enabled in settings)
        if (!empty($settings['meta_ticket_url'])) {
            $ticket_url = isset($_POST['chill_event_ticket_url']) ? esc_url_raw($_POST['chill_event_ticket_url']) : '';
            if (!empty($ticket_url)) {
                update_post_meta($post_id, '_chill_event_ticket_url', $ticket_url);
            } else {
                delete_post_meta($post_id, '_chill_event_ticket_url');
            }
        }
    }
    
    /**
     * Validate datetime string
     * 
     * @param string $datetime Datetime string to validate
     * @return bool True if valid
     * @since 1.0.0
     */
    private function validate_datetime($datetime) {
        $d = \DateTime::createFromFormat('Y-m-d H:i', $datetime);
        return $d && $d->format('Y-m-d H:i') === $datetime;
    }
    
    /**
     * Enqueue admin scripts for meta box
     * 
     * @param string $hook Current admin page hook
     * @since 1.0.0
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on post edit screens
        if (!in_array($hook, array('post.php', 'post-new.php'))) {
            return;
        }
        
        // Only load for our post type
        global $post;
        if (!$post || get_post_type($post) !== 'chill_events') {
            return;
        }
        
        // Enqueue admin styles for meta box
        wp_add_inline_style('wp-admin', '
            .chill-events-meta-box .form-table th {
                width: 150px;
                padding-left: 0;
            }
            .chill-events-meta-box .required {
                color: #d63638;
            }
            .chill-events-meta-box input[type="date"],
            .chill-events-meta-box input[type="time"] {
                width: 150px;
                margin-right: 10px;
            }
            .chill-events-meta-box .description {
                margin-top: 5px;
                color: #646970;
                font-style: italic;
            }
        ');
    }
} 