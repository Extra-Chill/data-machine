<?php
/**
 * Settings Page Template
 *
 * Template for the DM Events settings page in WordPress admin.
 *
 * @package DmEvents
 * @subpackage Templates\Admin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Get current settings
$settings = get_option('dm_events_settings', array(
    'include_in_archives' => false,
    'include_in_search' => true,
    'use_events_page' => true,
    'default_calendar_view' => 'month',
    'events_per_page' => 12,
    'main_events_page_url' => ''
));

// Handle settings updates
if (isset($_GET['settings-updated'])) {
    add_settings_error(
        'dm_events_messages',
        'dm_events_message',
        __('Settings Saved', 'dm-events'),
        'updated'
    );
}

settings_errors('dm_events_messages');
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <form action="options.php" method="post">
        <?php settings_fields('dm_events_settings_group'); ?>
        
        <!-- Archive & Display Settings -->
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php _e('Archive & Display Settings', 'dm-events'); ?></th>
                    <td>
                        <p class="description"><?php _e('Control how events appear in WordPress archives and search results.', 'dm-events'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Include in Site Archives', 'dm-events'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="dm_events_settings[include_in_archives]" 
                                   value="1" 
                                   <?php checked(isset($settings['include_in_archives']) ? $settings['include_in_archives'] : false, true); ?> />
                            <?php _e('Show events in category, tag, author, and date archives alongside blog posts', 'dm-events'); ?>
                        </label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Include in Search Results', 'dm-events'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="dm_events_settings[include_in_search]" 
                                   value="1" 
                                   <?php checked(isset($settings['include_in_search']) ? $settings['include_in_search'] : true, true); ?> />
                            <?php _e('Include events in WordPress search results', 'dm-events'); ?>
                        </label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Use Custom Events Page', 'dm-events'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="dm_events_settings[use_events_page]" 
                                   value="1" 
                                   <?php checked(isset($settings['use_events_page']) ? $settings['use_events_page'] : true, true); ?> />
                            <?php _e('Use a custom Events page with Calendar block instead of post type archive (recommended)', 'dm-events'); ?>
                        </label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Main Events Page URL', 'dm-events'); ?></th>
                    <td>
                        <input type="url" 
                               name="dm_events_settings[main_events_page_url]" 
                               value="<?php echo esc_attr(isset($settings['main_events_page_url']) ? $settings['main_events_page_url'] : ''); ?>" 
                               placeholder="https://yoursite.com/events/"
                               class="regular-text" />
                        <p class="description"><?php _e('Optional URL for the main events page. When set, a "Back to Events" link will appear on single event pages.', 'dm-events'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <!-- Calendar Display Defaults -->
        <h2><?php _e('Calendar Display Defaults', 'dm-events'); ?></h2>
        <p class="description"><?php _e('Default settings for Calendar blocks when first added to pages.', 'dm-events'); ?></p>
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php _e('Default Calendar View', 'dm-events'); ?></th>
                    <td>
                        <select name="dm_events_settings[default_calendar_view]">
                            <option value="month" <?php selected(isset($settings['default_calendar_view']) ? $settings['default_calendar_view'] : 'month', 'month'); ?>>
                                <?php _e('Month View', 'dm-events'); ?>
                            </option>
                            <option value="list" <?php selected(isset($settings['default_calendar_view']) ? $settings['default_calendar_view'] : 'month', 'list'); ?>>
                                <?php _e('List View', 'dm-events'); ?>
                            </option>
                            <option value="grid" <?php selected(isset($settings['default_calendar_view']) ? $settings['default_calendar_view'] : 'month', 'grid'); ?>>
                                <?php _e('Grid View', 'dm-events'); ?>
                            </option>
                        </select>
                        <p class="description"><?php _e('Default view for Calendar blocks', 'dm-events'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Events Per Page', 'dm-events'); ?></th>
                    <td>
                        <input type="number" 
                               name="dm_events_settings[events_per_page]" 
                               value="<?php echo esc_attr(isset($settings['events_per_page']) ? $settings['events_per_page'] : 12); ?>" 
                               min="1" 
                               max="100" />
                        <p class="description"><?php _e('Number of events to display per page in Calendar blocks', 'dm-events'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <?php submit_button(__('Save Settings', 'dm-events')); ?>
    </form>
    
    <!-- Events Page Setup Instructions -->
    <div class="dm-events-settings-info" style="margin-top: 30px; padding: 20px; background: #f9f9f9; border-left: 4px solid #0073aa;">
        <h3><?php _e('Events Page Setup', 'dm-events'); ?></h3>
        <p><?php _e('To create a custom Events page:', 'dm-events'); ?></p>
        <ol>
            <li><?php _e('Create a new page with the slug "events"', 'dm-events'); ?></li>
            <li><?php _e('Add the Event Calendar block to display events', 'dm-events'); ?></li>
            <li><?php _e('This page will automatically replace the default events archive', 'dm-events'); ?></li>
        </ol>
        <p><em><?php _e('The Calendar block provides filtering, multiple views, and responsive design.', 'dm-events'); ?></em></p>
    </div>
</div>