<?php

use ChillEvents\BaseDataSource;
use ICal\ICal;

if (!class_exists('TinRoofScraper')) {
class TinRoofScraper extends BaseDataSource {
    public function get_info() {
        return [
            'name' => 'Charleston Tin Roof Scraper',
            'type' => 'scraper',
            'description' => 'Scrapes events from Charleston Tin Roof iCal feed.',
            'settings_fields' => []
        ];
    }

    public function get_events($settings = array()) {
        $url = 'https://tockify.com/api/feeds/ics/tinroofschedule';
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
            $excluded_keywords = ["Happy Hour", "Brunch", "Pop-Up", "Karaoke", "Comedy", "Open Mic"];
            foreach ($events as $event) {
                $includeEvent = true;
                foreach ($excluded_keywords as $keyword) {
                    if (strpos($event->summary, $keyword) !== false) {
                        $includeEvent = false;
                        break;
                    }
                }
                if ($includeEvent) {
                    $title = preg_replace('/\.?\s*Doors\s*@\s*\d+(:\d+)?\s*.*$/i', '', $event->summary);
                    $startDateTime = new \DateTime($event->dtstart);
                    $endDateTime = new \DateTime($event->dtend);
                    $startDateTime->modify('-4 hours');
                    $endDateTime->modify('-4 hours');
                    $formatted_events[] = $this->standardize_event_data([
                        'title'       => $title,
                        'description' => $event->description,
                        'start_date'  => $startDateTime->format('Y-m-d H:i:s'),
                        'end_date'    => $endDateTime->format('Y-m-d H:i:s'),
                        'venue_name'  => 'Charleston Tin Roof',
                        'address'     => '1117 Magnolia Road',
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



