<?php
/**
 * Universal Web Scraper Event Section Selector Rules
 *
 * Centralizes the XPath selector rules used to identify candidate event sections
 * in HTML pages. These rules are ordered by priority.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

if (!defined('ABSPATH')) {
    exit;
}

final class EventSectionSelectors {

    /**
     * @return array<int, array{xpath: string, enable_table_row_date_filter: bool}>
     */
    public static function get_rules(): array {
        $rules = [
            // Schema.org microdata (HIGHEST PRIORITY)
            [
                'xpath' => '//*[contains(@itemtype, "Event")]',
                'enable_table_row_date_filter' => false,
            ],

            // Base64-encoded Google Calendar widget events (Starlight Motor Inn, etc.)
            [
                'xpath' => '//*[@data-calendar-event]',
                'enable_table_row_date_filter' => false,
                'extract_base64_event' => true,
            ],

            // SeeTickets widget patterns (used by Resound Presents, etc.)
            [
                'xpath' => '//*[contains(@class, "seetickets-list-event-container")]',
                'enable_table_row_date_filter' => false,
            ],
            [
                'xpath' => '//*[contains(@class, "seetickets-calendar-event")]',
                'enable_table_row_date_filter' => false,
            ],

            // Turntable Tickets (Monks Jazz, etc.)
            [
                'xpath' => '//*[contains(concat(" ", normalize-space(@class), " "), " show-card ")]',
                'enable_table_row_date_filter' => false,
            ],

            // Brown Bear Calendar (Sahara Lounge, etc.)
            [
                'xpath' => '//*[contains(concat(" ", normalize-space(@class), " "), " CalEvent ")]',
                'enable_table_row_date_filter' => false,
            ],

            // Drupal Views patterns (Views module event listings)
            [
                'xpath' => '//*[contains(concat(" ", normalize-space(@class), " "), " views-row ")]',
                'enable_table_row_date_filter' => false,
            ],

            // Drupal node type patterns
            [
                'xpath' => '//*[contains(concat(" ", normalize-space(@class), " "), " node--type-event ")]',
                'enable_table_row_date_filter' => false,
            ],

            // Table-based event patterns (venue calendars often use tables)
            [
                'xpath' => '//tr[.//td[contains(@class, "event-date") or contains(@class, "event-name") or contains(@class, "event")]]',
                'enable_table_row_date_filter' => true,
            ],
            [
                'xpath' => '//table[contains(@class, "calendar") or contains(@class, "events") or contains(@class, "schedule")]//tbody//tr',
                'enable_table_row_date_filter' => true,
            ],
            [
                'xpath' => '//section[contains(@class, "calendar")]//table//tbody//tr',
                'enable_table_row_date_filter' => true,
            ],

            // Specific event listing patterns (HIGH PRIORITY)
            [
                'xpath' => '//*[contains(concat(" ", normalize-space(@class), " "), " recspec-events--event ")]',
                'enable_table_row_date_filter' => false,
            ],
            [
                'xpath' => '//*[contains(@class, "eventlist-event")]',
                'enable_table_row_date_filter' => false,
            ],
            [
                'xpath' => '//article[contains(@class, "eventlist-event")]',
                'enable_table_row_date_filter' => false,
            ],

            // Article elements with event-related classes
            [
                'xpath' => '//article[contains(@class, "event")]',
                'enable_table_row_date_filter' => false,
            ],
            [
                'xpath' => '//article[contains(@class, "show")]',
                'enable_table_row_date_filter' => false,
            ],
            [
                'xpath' => '//article[contains(@class, "concert")]',
                'enable_table_row_date_filter' => false,
            ],

            // Common event class patterns
            [
                'xpath' => '//*[contains(concat(" ", normalize-space(@class), " "), " full-event ")]',
                'enable_table_row_date_filter' => false,
            ],
            [
                'xpath' => '//*[contains(@class, "event-content-row")]',
                'enable_table_row_date_filter' => false,
            ],
            [
                'xpath' => '//*[contains(@class, "event-item")]',
                'enable_table_row_date_filter' => false,
            ],
            [
                'xpath' => '//*[contains(@class, "show-item")]',
                'enable_table_row_date_filter' => false,
            ],
            [
                'xpath' => '//*[contains(@class, "concert-item")]',
                'enable_table_row_date_filter' => false,
            ],
            [
                'xpath' => '//*[contains(@class, "calendar-event")]',
                'enable_table_row_date_filter' => false,
            ],
            [
                'xpath' => '//*[contains(@class, "event-card")]',
                'enable_table_row_date_filter' => false,
            ],
            [
                'xpath' => '//*[contains(@class, "event-entry")]',
                'enable_table_row_date_filter' => false,
            ],
            [
                'xpath' => '//*[contains(@class, "event-listing")]',
                'enable_table_row_date_filter' => false,
            ],

            // List items within event containers (lower priority - can match navigation)
            [
                'xpath' => '//*[contains(@class, "events")]//li',
                'enable_table_row_date_filter' => false,
            ],
            [
                'xpath' => '//*[contains(@class, "shows")]//li',
                'enable_table_row_date_filter' => false,
            ],
            [
                'xpath' => '//*[contains(@class, "calendar")]//li',
                'enable_table_row_date_filter' => false,
            ],
        ];

        /**
         * Filter universal web scraper selector rules.
         *
         * @param array<int, array{xpath: string, enable_table_row_date_filter: bool}> $rules
         */
        return apply_filters('datamachine_events_universal_web_scraper_selector_rules', $rules);
    }
}
