<?php
/**
 * Settings Page Template
 *
 * Template for the DM Events settings page in WordPress admin.
 *
 * @package DataMachineEvents
 * @subpackage Templates\Admin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use DataMachineEvents\Admin\Settings_Page;

// Get current settings using centralized defaults from Settings_Page
$settings = [
    'include_in_archives' => Settings_Page::get_setting('include_in_archives'),
    'include_in_search' => Settings_Page::get_setting('include_in_search'),
    'main_events_page_url' => Settings_Page::get_setting('main_events_page_url'),
    'map_display_type' => Settings_Page::get_setting('map_display_type'),
    'geonames_username' => Settings_Page::get_setting('geonames_username'),
];

// Handle settings updates
if (isset($_GET['settings-updated'])) {
    add_settings_error(
        'datamachine_events_messages',
        'datamachine_events_message',
        __('Settings Saved', 'datamachine-events'),
        'updated'
    );
}

settings_errors('datamachine_events_messages');
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <form action="options.php" method="post">
        <?php settings_fields('datamachine_events_settings_group'); ?>
        
        <!-- Archive & Display Settings -->
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php _e('Archive & Display Settings', 'datamachine-events'); ?></th>
                    <td>
                        <p class="description"><?php _e('Control how events appear in WordPress archives and search results.', 'datamachine-events'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Include in Site Archives', 'datamachine-events'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="datamachine_events_settings[include_in_archives]" 
                                   value="1" 
                                   <?php checked(isset($settings['include_in_archives']) ? $settings['include_in_archives'] : false, true); ?> />
                            <?php _e('Show events in category, tag, author, and date archives alongside blog posts', 'datamachine-events'); ?>
                        </label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Include in Search Results', 'datamachine-events'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="datamachine_events_settings[include_in_search]" 
                                   value="1" 
                                   <?php checked(isset($settings['include_in_search']) ? $settings['include_in_search'] : true, true); ?> />
                            <?php _e('Include events in WordPress search results', 'datamachine-events'); ?>
                        </label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Main Events Page URL', 'datamachine-events'); ?></th>
                    <td>
                        <input type="url" 
                               name="datamachine_events_settings[main_events_page_url]" 
                               value="<?php echo esc_attr(isset($settings['main_events_page_url']) ? $settings['main_events_page_url'] : ''); ?>" 
                               placeholder="https://yoursite.com/events/"
                               class="regular-text" />
                        <p class="description"><?php _e('URL for your custom events page with Calendar block. When set, this replaces the default events archive and adds "Back to Events" links on single event pages.', 'datamachine-events'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Map Display Settings -->
        <h2><?php _e('Map Display Settings', 'datamachine-events'); ?></h2>
        <p class="description"><?php _e('Configure venue map appearance for Event Details blocks.', 'datamachine-events'); ?></p>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php _e('Map Display Type', 'datamachine-events'); ?></th>
                    <td>
                        <label>
                            <input type="radio"
                                   name="datamachine_events_settings[map_display_type]"
                                   value="osm-standard"
                                   <?php checked(isset($settings['map_display_type']) ? $settings['map_display_type'] : 'osm-standard', 'osm-standard'); ?> />
                            <?php _e('OpenStreetMap Standard', 'datamachine-events'); ?>
                        </label>
                        <br><br>
                        <label>
                            <input type="radio"
                                   name="datamachine_events_settings[map_display_type]"
                                   value="carto-positron"
                                   <?php checked(isset($settings['map_display_type']) ? $settings['map_display_type'] : 'osm-standard', 'carto-positron'); ?> />
                            <?php _e('CartoDB Positron (Light)', 'datamachine-events'); ?>
                        </label>
                        <br><br>
                        <label>
                            <input type="radio"
                                   name="datamachine_events_settings[map_display_type]"
                                   value="carto-voyager"
                                   <?php checked(isset($settings['map_display_type']) ? $settings['map_display_type'] : 'osm-standard', 'carto-voyager'); ?> />
                            <?php _e('CartoDB Voyager', 'datamachine-events'); ?>
                        </label>
                        <br><br>
                        <label>
                            <input type="radio"
                                   name="datamachine_events_settings[map_display_type]"
                                   value="carto-dark"
                                   <?php checked(isset($settings['map_display_type']) ? $settings['map_display_type'] : 'osm-standard', 'carto-dark'); ?> />
                            <?php _e('CartoDB Dark Matter', 'datamachine-events'); ?>
                        </label>
                        <br><br>
                        <label>
                            <input type="radio"
                                   name="datamachine_events_settings[map_display_type]"
                                   value="humanitarian"
                                   <?php checked(isset($settings['map_display_type']) ? $settings['map_display_type'] : 'osm-standard', 'humanitarian'); ?> />
                            <?php _e('Humanitarian (High Contrast)', 'datamachine-events'); ?>
                        </label>
                        <p class="description">
                            <?php _e('<strong>OpenStreetMap Standard:</strong> Traditional street map (current default)<br>', 'datamachine-events'); ?>
                            <?php _e('<strong>CartoDB Positron:</strong> Light, minimal design for clean appearance<br>', 'datamachine-events'); ?>
                            <?php _e('<strong>CartoDB Voyager:</strong> Modern street map with balanced detail<br>', 'datamachine-events'); ?>
                            <?php _e('<strong>CartoDB Dark Matter:</strong> Dark theme for low-light viewing<br>', 'datamachine-events'); ?>
                            <?php _e('<strong>Humanitarian:</strong> High-contrast style optimized for accessibility', 'datamachine-events'); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Venue Timezone Settings -->
        <h2><?php _e('Venue Timezone Settings', 'datamachine-events'); ?></h2>
        <p class="description"><?php _e('Configure automatic timezone detection for venues using their coordinates.', 'datamachine-events'); ?></p>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php _e('GeoNames Username', 'datamachine-events'); ?></th>
                    <td>
                        <input type="text"
                               name="datamachine_events_settings[geonames_username]"
                               value="<?php echo esc_attr($settings['geonames_username'] ?? ''); ?>"
                               placeholder="your_geonames_username"
                               class="regular-text" />
                        <p class="description">
                            <?php printf(
                                __('Required for automatic timezone detection from venue coordinates. <a href="%s" target="_blank">Create a free GeoNames account</a> and enable web services in your account settings.', 'datamachine-events'),
                                'https://www.geonames.org/login'
                            ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Backfill Timezones', 'datamachine-events'); ?></th>
                    <td>
                        <div id="dm-backfill-stats" style="margin-bottom: 12px;"></div>
                        <button type="button" id="dm-backfill-button" class="button button-secondary">
                            <?php _e('Backfill Venue Timezones', 'datamachine-events'); ?>
                        </button>
                        <div id="dm-backfill-progress" style="display: none; margin-top: 10px;"></div>
                        <div id="dm-backfill-results"></div>
                        <p class="description" style="margin-top: 8px;">
                            <?php _e('Derive timezones for existing venues using their coordinates. New venues automatically get timezones during import.', 'datamachine-events'); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php submit_button(__('Save Settings', 'datamachine-events')); ?>
    </form>
    
    <!-- Events Page Setup Instructions -->
    <div class="datamachine-events-settings-info" style="margin-top: 30px; padding: 20px; background: #f9f9f9; border-left: 4px solid #0073aa;">
        <h3><?php _e('Events Page Setup', 'datamachine-events'); ?></h3>
        <p><?php _e('To create a custom Events page:', 'datamachine-events'); ?></p>
        <ol>
            <li><?php _e('Create a new page with the slug "events"', 'datamachine-events'); ?></li>
            <li><?php _e('Add the Event Calendar block to display events', 'datamachine-events'); ?></li>
            <li><?php _e('This page will automatically replace the default events archive', 'datamachine-events'); ?></li>
        </ol>
        <p><em><?php _e('The Calendar block provides filtering, multiple views, and responsive design.', 'datamachine-events'); ?></em></p>
    </div>
</div>