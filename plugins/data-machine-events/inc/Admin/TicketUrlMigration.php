<?php
/**
 * Ticket URL Migration
 *
 * One-time migration to populate _datamachine_ticket_url meta for existing events.
 * Displays an admin notice with a button to run the migration.
 *
 * @package DataMachineEvents
 * @subpackage Admin
 * @since 0.8.39
 */

namespace DataMachineEvents\Admin;

use DataMachineEvents\Core\Event_Post_Type;
use const DataMachineEvents\Core\EVENT_TICKET_URL_META_KEY;
use function DataMachineEvents\Core\datamachine_normalize_ticket_url;

if (!defined('ABSPATH')) {
    exit;
}

class TicketUrlMigration {

    const OPTION_KEY = 'datamachine_ticket_url_migration_complete';
    const NONCE_ACTION = 'datamachine_ticket_url_migration';

    public function __construct() {
        add_action('admin_notices', [$this, 'maybe_show_migration_notice']);
        add_action('admin_init', [$this, 'handle_migration_request']);
    }

    public function maybe_show_migration_notice(): void {
        if (get_option(self::OPTION_KEY)) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== Event_Post_Type::POST_TYPE) {
            return;
        }

        $events_count = $this->count_events_needing_migration();
        if ($events_count === 0) {
            update_option(self::OPTION_KEY, true);
            return;
        }

        $migrate_url = wp_nonce_url(
            admin_url('edit.php?post_type=' . Event_Post_Type::POST_TYPE . '&datamachine_migrate_ticket_urls=1'),
            self::NONCE_ACTION
        );

        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <strong>Data Machine Events:</strong>
                <?php
                printf(
                    esc_html__('Found %d events that need ticket URL indexing for improved duplicate detection.', 'datamachine-events'),
                    $events_count
                );
                ?>
            </p>
            <p>
                <a href="<?php echo esc_url($migrate_url); ?>" class="button button-primary">
                    <?php esc_html_e('Run Migration', 'datamachine-events'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    public function handle_migration_request(): void {
        if (!isset($_GET['datamachine_migrate_ticket_urls'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'datamachine-events'));
        }

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), self::NONCE_ACTION)) {
            wp_die(__('Security check failed.', 'datamachine-events'));
        }

        $migrated = $this->run_migration();

        update_option(self::OPTION_KEY, true);

        $redirect_url = add_query_arg([
            'post_type' => Event_Post_Type::POST_TYPE,
            'datamachine_migrated' => $migrated
        ], admin_url('edit.php'));

        wp_safe_redirect($redirect_url);
        exit;
    }

    private function count_events_needing_migration(): int {
        global $wpdb;

        $post_type = Event_Post_Type::POST_TYPE;

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                FROM {$wpdb->posts} p
                WHERE p.post_type = %s
                AND p.post_status IN ('publish', 'draft', 'pending')
                AND p.post_content LIKE %s
                AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} pm
                    WHERE pm.post_id = p.ID
                    AND pm.meta_key = %s
                )",
                $post_type,
                '%ticketUrl%',
                EVENT_TICKET_URL_META_KEY
            )
        );

        return (int) $count;
    }

    private function run_migration(): int {
        $args = [
            'post_type' => Event_Post_Type::POST_TYPE,
            'posts_per_page' => -1,
            'post_status' => ['publish', 'draft', 'pending'],
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => EVENT_TICKET_URL_META_KEY,
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ];

        $post_ids = get_posts($args);
        $migrated = 0;

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                continue;
            }

            $blocks = parse_blocks($post->post_content);

            foreach ($blocks as $block) {
                if ($block['blockName'] !== 'datamachine-events/event-details') {
                    continue;
                }

                $ticket_url = $block['attrs']['ticketUrl'] ?? '';
                if (!empty($ticket_url)) {
                    $normalized = datamachine_normalize_ticket_url($ticket_url);
                    update_post_meta($post_id, EVENT_TICKET_URL_META_KEY, $normalized);
                    $migrated++;
                }
                break;
            }
        }

        return $migrated;
    }
}
