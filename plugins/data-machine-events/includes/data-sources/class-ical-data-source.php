<?php
/**
 * iCal/ICS Data Source for Chill Events
 *
 * @package ChillEvents
 * @since 1.0.0
 */

namespace ChillEvents;

use ICal\ICal;

if (!defined('ABSPATH')) {
    exit;
}

class IcalDataSource extends BaseDataSource {
    /**
     * Get info about this data source
     *
     * @return array
     */
    public function get_info() {
        return array(
            'name' => 'iCal/ICS Feed',
            'slug' => 'ical',
            'type' => 'ics',
            'description' => 'Import events from any public iCal (.ics) feed, including Google Calendar, Apple Calendar, and more.',
            'official' => false,
            'requires_config' => true,
            'settings_fields' => array(
                'ical_url' => array(
                    'type' => 'text',
                    'label' => 'iCal Feed URL',
                    'description' => 'Paste the public .ics feed URL (e.g., from Google Calendar, Eventbrite, etc.).',
                ),
                'date_range' => array(
                    'type' => 'select',
                    'label' => 'Date Range',
                    'options' => array(
                        '30' => 'Next 30 days',
                        '60' => 'Next 60 days',
                        '90' => 'Next 90 days',
                        '180' => 'Next 6 months',
                        '365' => 'Next year'
                    ),
                    'default' => '90',
                    'description' => 'How far ahead to import events.'
                )
            )
        );
    }

    /**
     * Get events from this data source
     *
     * @param array $settings Data source settings
     * @return array Array of standardized event data
     */
    public function get_events($settings = array()) {
        $ical_url = isset($settings['ical_url']) ? trim($settings['ical_url']) : '';
        if (empty($ical_url)) {
            $this->log_error('No iCal feed URL provided.', $settings);
            return array();
        }
        $date_range = isset($settings['date_range']) ? intval($settings['date_range']) : 90;
        try {
            $ical = new ICal($ical_url, array(
                'defaultSpan'                 => 2,     // Default value
                'defaultTimeZone'             => 'UTC',
                'defaultWeekStart'             => 'MO',  // Default value
                'disableCharacterReplacement'  => false,
                'skipRecurrence'               => false,
                'useTimeZoneWithRRules'        => false,
            ));
            $events = $ical->events();
            $now = time();
            $future = strtotime("+{$date_range} days");
            $standardized_events = array();
            foreach ($events as $event) {
                // Filter by date range
                $start_ts = isset($event->dtstart_array[2]) ? strtotime($event->dtstart_array[2]) : strtotime($event->dtstart);
                if ($start_ts < $now || $start_ts > $future) {
                    continue;
                }
                $standardized_events[] = $this->standardize_event_data($this->convert_ical_event($event));
            }
            return $standardized_events;
        } catch (\Exception $e) {
            $this->log_error('iCal parse error: ' . $e->getMessage(), $settings);
            return array();
        }
    }

    /**
     * Convert iCal event to standardized format
     *
     * @param object $event ICal event object
     * @return array
     */
    private function convert_ical_event($event) {
        $standardized = array();
        $standardized['id'] = isset($event->uid) ? $event->uid : md5($event->dtstart . $event->summary);
        $standardized['title'] = isset($event->summary) ? $event->summary : '';
        $standardized['description'] = isset($event->description) ? $event->description : '';
        $standardized['start_date'] = isset($event->dtstart_array[2]) ? $event->dtstart_array[2] : $event->dtstart;
        $standardized['end_date'] = isset($event->dtend_array[2]) ? $event->dtend_array[2] : $event->dtend;
        $standardized['venue_name'] = isset($event->location) ? $event->location : '';
        $standardized['location_name'] = isset($event->location) ? $event->location : '';
        $standardized['ticket_url'] = isset($event->url) ? $event->url : '';
        // iCal doesn't have artist/price/image fields
        return $standardized;
    }
} 