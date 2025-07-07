<?php

require_once get_template_directory() . '/vendor/autoload.php';

use ICal\ICal;
use ChillEvents\BaseDataSource;

if (!class_exists('CommodoreScraper')) {
class CommodoreScraper extends BaseDataSource {
    public function get_info() {
        return [
            'name' => 'The Commodore Scraper',
            'type' => 'scraper',
            'description' => 'Scrapes events from The Commodore iCal feed.',
            'settings_fields' => []
        ];
    }

    public function get_events($settings = array()) {
        $url = 'https://tockify.com/api/feeds/ics/thecommodorechs';
        try {
            $ical = new ICal($url, array(
                'defaultSpan' => 2,
                'defaultTimeZone' => 'America/New_York',
                'defaultWeekStart' => 'MO',
                'skipRecurrence' => false,
                'useTimeZoneWithRRules' => true,
            ));
            $events = $ical->eventsFromRange('now');
            $formatted_events = [];
            foreach ($events as $event) {
                $startDateTime = new \DateTime($event->dtstart);
                $endDateTime = new \DateTime($event->dtend);
                $startDateTime->modify('-4 hours');
                $endDateTime->modify('-4 hours');
                $title = ucwords(strtolower($event->summary));
                if (!method_exists($this, 'event_already_exists') || !$this->event_already_exists($title, $startDateTime->format('Y-m-d H:i:s'))) {
                    $formatted_events[] = $this->standardize_event_data([
                        'title'       => $title,
                        'description' => $event->description,
                        'start_date'  => $startDateTime->format('Y-m-d H:i:s'),
                        'end_date'    => $endDateTime->format('Y-m-d H:i:s'),
                        'venue_name'  => 'The Commodore',
                        'address'     => '504 Meeting St Suite C',
                        'location_name' => 'Charleston, SC',
                        'ticket_url'  => $event->url
                    ]);
                }
            }
            return $formatted_events;
        } catch (\Exception $e) {
            $this->log_error('Failed to fetch or parse iCalendar feed: ' . $e->getMessage());
            return [];
        }
    }
}
}

