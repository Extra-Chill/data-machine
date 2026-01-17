<?php
/**
 * Migrate Handlers Command
 *
 * Migrates flows from deprecated handlers to their replacements.
 * Supports ICS Calendar, DoStuff Media API, and Eventbrite → Universal Web Scraper migrations.
 *
 * @package DataMachineEvents\Cli
 */

namespace DataMachineEvents\Cli;

if (!defined('ABSPATH')) {
    exit;
}

class MigrateHandlersCommand {
    public function __invoke(array $args, array $assoc_args): void {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        \WP_CLI::log('Handler Migration Tool');
        \WP_CLI::log('=======================');
        \WP_CLI::log('');

        $dry_run = isset($assoc_args['dry-run']);
        $handler = $assoc_args['handler'] ?? '';

        if (empty($handler)) {
            \WP_CLI::error('Missing required --handler parameter. Available: ics_calendar, dostuff_media_api, eventbrite');
            return;
        }

        switch ($handler) {
            case 'ics_calendar':
                $this->migrateIcsCalendar($dry_run);
                break;

            case 'dostuff_media_api':
                $this->migrateDoStuffMediaApi($dry_run);
                break;

            case 'eventbrite':
                $this->migrateEventbrite($dry_run);
                break;

            default:
                \WP_CLI::error("Unknown handler: {$handler}. Available: ics_calendar, dostuff_media_api, eventbrite");
                return;
        }
    }

    private function migrateIcsCalendar(bool $dry_run): void {
        \WP_CLI::log('Migrating ICS Calendar → Universal Web Scraper');
        \WP_CLI::log('');

        $flows = $this->getFlowsUsingHandler('ics_calendar');

        if (empty($flows)) {
            \WP_CLI::log('No flows found using ICS Calendar handler.');
            \WP_CLI::log('');
            \WP_CLI::success('Migration complete. Nothing to do.');
            return;
        }

        \WP_CLI::log(sprintf('Found %d flow(s) using ICS Calendar handler', count($flows)));
        \WP_CLI::log('');

        $migrated = 0;
        $failed = 0;

        foreach ($flows as $flow) {
            $flow_id = $flow->ID;
            $flow_title = $flow->post_title;

            $flow_steps = get_post_meta($flow_id, 'datamachine_flow_steps', true);
            $steps_updated = false;

            if (empty($flow_steps) || !is_array($flow_steps)) {
                \WP_CLI::warning("  Skip: {$flow_title} (no steps found)");
                $failed++;
                continue;
            }

            foreach ($flow_steps as $step_index => $step) {
                if ($step['handler'] === 'ics_calendar') {
                    if ($dry_run) {
                        \WP_CLI::log("  Would migrate: {$flow_title} (step {$step_index})");
                        $migrated++;
                        $steps_updated = true;
                        continue;
                    }

                    $migrated_config = $this->migrateIcsCalendarConfig($step['config'] ?? []);

                    $flow_steps[$step_index] = array_merge($step, [
                        'handler' => 'universal_web_scraper',
                        'config' => $migrated_config,
                    ]);

                    $steps_updated = true;
                    \WP_CLI::log("  Migrating: {$flow_title} (step {$step_index})");
                } elseif ($step['handler'] === 'dostuff_media_api') {
                    if ($dry_run) {
                        \WP_CLI::log("  Would migrate: {$flow_title} (step {$step_index})");
                        $migrated++;
                        $steps_updated = true;
                        continue;
                    }

                    $migrated_config = $this->migrateDoStuffMediaApiConfig($step['config'] ?? []);

                    $flow_steps[$step_index] = array_merge($step, [
                        'handler' => 'universal_web_scraper',
                        'config' => $migrated_config,
                    ]);

                    $steps_updated = true;
                    \WP_CLI::log("  Migrating: {$flow_title} (step {$step_index})");
                }
            }

            if ($steps_updated) {
                if (!$dry_run) {
                    update_post_meta($flow_id, 'datamachine_flow_steps', $flow_steps);

                    \WP_CLI::log("  ✓ Updated flow: {$flow_title}");
                    $migrated++;
                }
            } else {
                \WP_CLI::warning("  Skip: {$flow_title} (no ics_calendar, dostuff_media_api, or eventbrite steps found)");
                $failed++;
            }
        }

        \WP_CLI::log('');
        if ($dry_run) {
            \WP_CLI::log('DRY RUN - No changes made');
            \WP_CLI::log("Would migrate {$migrated} flow(s)");
        } else {
            \WP_CLI::success(sprintf('Migration complete: %d flow(s) migrated', $migrated));
        }

        if ($failed > 0) {
            \WP_CLI::warning(sprintf('%d flow(s) failed to migrate', $failed));
        }

        \WP_CLI::log('');
        \WP_CLI::log('Next steps:');
        \WP_CLI::log('1. Test your migrated flows to ensure events import correctly');
        \WP_CLI::log('2. Delete the ICS Calendar, DoStuff Media API, or Eventbrite handler files when migration is verified');
    }

    private function migrateIcsCalendarConfig(array $ics_config): array {
        $scraper_config = [
            'source_url' => $ics_config['feed_url'] ?? '',
            'search' => $ics_config['search'] ?? '',
            'exclude_keywords' => $ics_config['exclude_keywords'] ?? '',
            'venue_name' => $ics_config['venue_name'] ?? '',
            'venue_address' => $ics_config['venue_address'] ?? '',
            'venue_city' => $ics_config['venue_city'] ?? '',
            'venue_state' => $ics_config['venue_state'] ?? '',
            'venue_zip' => $ics_config['venue_zip'] ?? '',
            'venue_country' => $ics_config['venue_country'] ?? '',
            'venue_phone' => $ics_config['venue_phone'] ?? '',
            'venue_website' => $ics_config['venue_website'] ?? '',
            'venue_coordinates' => $ics_config['venue_coordinates'] ?? '',
        ];

        return $scraper_config;
    }

    private function migrateDoStuffMediaApiConfig(array $dostuff_config): array {
        $scraper_config = [
            'source_url' => $dostuff_config['feed_url'] ?? '',
            'search' => $dostuff_config['search'] ?? '',
            'exclude_keywords' => $dostuff_config['exclude_keywords'] ?? '',
        ];
        
        return $scraper_config;
    }
    
    private function migrateDoStuffMediaApi(bool $dry_run): void {
        \WP_CLI::log('Migrating Do Stuff Media API → Universal Web Scraper');
        \WP_CLI::log('');
        
        $flows = $this->getFlowsUsingHandler('dostuff_media_api');
        
        if (empty($flows)) {
            \WP_CLI::log('No flows found using Do Stuff Media API handler.');
            \WP_CLI::log('');
            \WP_CLI::success('Migration complete. Nothing to do.');
            return;
        }
        
        \WP_CLI::log(sprintf('Found %d flow(s) using Do Stuff Media API handler', count($flows)));
        \WP_CLI::log('');
        
        $migrated = 0;
        $failed = 0;
        
        foreach ($flows as $flow) {
            $flow_id = $flow->ID;
            $flow_title = $flow->post_title;
            
            $flow_steps = get_post_meta($flow_id, 'datamachine_flow_steps', true);
            $steps_updated = false;
            
            if (empty($flow_steps) || !is_array($flow_steps)) {
                \WP_CLI::warning("  Skip: {$flow_title} (no steps found)");
                $failed++;
                continue;
            }
            
            foreach ($flow_steps as $step_index => $step) {
                if ($step['handler'] === 'dostuff_media_api') {
                    if ($dry_run) {
                        \WP_CLI::log("  Would migrate: {$flow_title} (step {$step_index})");
                        $migrated++;
                        $steps_updated = true;
                        continue;
                    }
                    
                    $migrated_config = $this->migrateDoStuffMediaApiConfig($step['config'] ?? []);
                    
                    $flow_steps[$step_index] = array_merge($step, [
                        'handler' => 'universal_web_scraper',
                        'config' => $migrated_config,
                    ]);
                    
                    $steps_updated = true;
                    \WP_CLI::log("  Migrating: {$flow_title} (step {$step_index})");
                }
            }
            
            if ($steps_updated) {
                if (!$dry_run) {
                    update_post_meta($flow_id, 'datamachine_flow_steps', $flow_steps);
                    
                    \WP_CLI::log("  ✓ Updated flow: {$flow_title}");
                    $migrated++;
                }
            } else {
                \WP_CLI::warning("  Skip: {$flow_title} (no dostuff_media_api steps found)");
                $failed++;
            }
        }
        
        \WP_CLI::log('');
        if ($dry_run) {
            \WP_CLI::log('DRY RUN - No changes made');
            \WP_CLI::log("Would migrate {$migrated} flow(s)");
        } else {
            \WP_CLI::success(sprintf('Migration complete: %d flow(s) migrated', $migrated));
        }
        
        if ($failed > 0) {
            \WP_CLI::warning(sprintf('%d flow(s) failed to migrate', $failed));
        }
        
        \WP_CLI::log('');
        \WP_CLI::log('Next steps:');
        \WP_CLI::log('1. Test your migrated flows to ensure events import correctly');
        \WP_CLI::log('2. Delete Do Stuff Media API handler files when migration is verified');
    }

    private function migrateEventbriteConfig(array $eb_config): array {
        $scraper_config = [
            'source_url' => $eb_config['organizer_url'] ?? '',
            'search' => $eb_config['search'] ?? '',
            'exclude_keywords' => $eb_config['exclude_keywords'] ?? '',
        ];

        return $scraper_config;
    }

    private function migrateEventbrite(bool $dry_run): void {
        \WP_CLI::log('Migrating Eventbrite → Universal Web Scraper');
        \WP_CLI::log('');

        $flows = $this->getFlowsUsingHandler('eventbrite');

        if (empty($flows)) {
            \WP_CLI::log('No flows found using Eventbrite handler.');
            \WP_CLI::log('');
            \WP_CLI::success('Migration complete. Nothing to do.');
            return;
        }

        \WP_CLI::log(sprintf('Found %d flow(s) using Eventbrite handler', count($flows)));
        \WP_CLI::log('');

        $migrated = 0;
        $failed = 0;

        foreach ($flows as $flow) {
            $flow_id = $flow->ID;
            $flow_title = $flow->post_title;

            $flow_steps = get_post_meta($flow_id, 'datamachine_flow_steps', true);
            $steps_updated = false;

            if (empty($flow_steps) || !is_array($flow_steps)) {
                \WP_CLI::warning("  Skip: {$flow_title} (no steps found)");
                $failed++;
                continue;
            }

            foreach ($flow_steps as $step_index => $step) {
                if ($step['handler'] === 'eventbrite') {
                    if ($dry_run) {
                        \WP_CLI::log("  Would migrate: {$flow_title} (step {$step_index})");
                        $migrated++;
                        $steps_updated = true;
                        continue;
                    }

                    $migrated_config = $this->migrateEventbriteConfig($step['config'] ?? []);

                    $flow_steps[$step_index] = array_merge($step, [
                        'handler' => 'universal_web_scraper',
                        'config' => $migrated_config,
                    ]);

                    $steps_updated = true;
                    \WP_CLI::log("  Migrating: {$flow_title} (step {$step_index})");
                }
            }

            if ($steps_updated) {
                if (!$dry_run) {
                    update_post_meta($flow_id, 'datamachine_flow_steps', $flow_steps);

                    \WP_CLI::log("  ✓ Updated flow: {$flow_title}");
                    $migrated++;
                }
            } else {
                \WP_CLI::warning("  Skip: {$flow_title} (no eventbrite steps found)");
                $failed++;
            }
        }

        \WP_CLI::log('');
        if ($dry_run) {
            \WP_CLI::log('DRY RUN - No changes made');
            \WP_CLI::log("Would migrate {$migrated} flow(s)");
        } else {
            \WP_CLI::success(sprintf('Migration complete: %d flow(s) migrated', $migrated));
        }

        if ($failed > 0) {
            \WP_CLI::warning(sprintf('%d flow(s) failed to migrate', $failed));
        }

        \WP_CLI::log('');
        \WP_CLI::log('Next steps:');
        \WP_CLI::log('1. Test your migrated flows to ensure events import correctly');
        \WP_CLI::log('2. Delete the Eventbrite handler files when migration is verified');
    }

    private function getFlowsUsingHandler(string $handler_slug): array {
        global $wpdb;

        $post_type = 'datamachine_flow';
        $sql = $wpdb->prepare(
            "SELECT p.ID, p.post_title 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = %s
            AND pm.meta_key = 'datamachine_flow_steps'
            AND pm.meta_value LIKE %s",
            $post_type,
            '%' . $wpdb->esc_like($handler_slug) . '%'
        );

        $results = $wpdb->get_results($sql, OBJECT_K);

        if ($results === false) {
            return [];
        }

        return $results;
    }
}
