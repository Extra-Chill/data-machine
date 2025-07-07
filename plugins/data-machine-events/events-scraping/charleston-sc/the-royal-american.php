<?php
use ChillEvents\BaseDataSource;

if (!class_exists('RoyalAmericanScraper')) {
class RoyalAmericanScraper extends BaseDataSource {
    public function get_info() {
        return [
            'name' => 'The Royal American Scraper',
            'type' => 'scraper',
            'description' => 'Scrapes events from The Royal American website.',
            'settings_fields' => []
        ];
    }

    public function get_events($settings = array()) {
        $url = 'http://www.theroyalamerican.com/schedule';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        $htmlContent = curl_exec($ch);
        curl_close($ch);
        if (!$htmlContent) {
            $this->log_error('Failed to fetch Royal American events.');
            return [];
        }
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        @$dom->loadHTML($htmlContent);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        $eventsNodes = $xpath->query("//article[contains(@class, 'eventlist-event')]");
        $events = [];
        $currentYear = date('Y');
        foreach ($eventsNodes as $node) {
            $eventUrl = $xpath->evaluate("string(.//a[@class='eventlist-title-link']/@href)", $node);
            $title = $xpath->evaluate("string(.//h1[@class='eventlist-title']/a)", $node);
            $month = trim($xpath->evaluate("string(.//div[@class='eventlist-datetag-startdate eventlist-datetag-startdate--month'])", $node));
            $day = trim($xpath->evaluate("string(.//div[@class='eventlist-datetag-startdate eventlist-datetag-startdate--day'])", $node));
            if (empty($month) || empty($day)) {
                $this->log_error('Skipping event due to missing date: ' . $title);
                continue;
            }
            $startDateText = "$month $day $currentYear";
            $startTimeText = trim($xpath->evaluate("string(.//time[@class='event-time-12hr'][1])", $node));
            if (empty($startTimeText)) {
                $this->log_error("Missing event time for: $title. Assigning default 8:00 PM.");
                $startTimeText = "8:00 PM";
            }
            $startDate = date('Y-m-d H:i:s', strtotime("$startDateText $startTimeText"));
            $endDate = date('Y-m-d H:i:s', strtotime($startDate) + 3600 * 5);
            $descriptionHtml = $xpath->evaluate("string(.//div[@class='eventlist-description'])", $node);
            $description = html_entity_decode(strip_tags($descriptionHtml, '<a><br>'));
            if (new \DateTime($startDate) < new \DateTime()) {
                $this->log_error("Skipping past event: $title on $startDate");
                continue;
            }
            $venueDetails = [
                'venue_name' => 'The Royal American',
                'address' => '970 Morrison Drive',
                'location_name' => 'Charleston, SC',
                'ticket_url' => 'http://www.theroyalamerican.com' . $eventUrl
            ];
            $event = [
                'title' => trim($title),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'description' => trim($description),
                'ticket_url' => $venueDetails['ticket_url'],
                'venue_name' => $venueDetails['venue_name'],
                'address' => $venueDetails['address'],
                'location_name' => $venueDetails['location_name']
            ];
            $events[] = $this->standardize_event_data($event);
        }
        return $events;
    }
}
}

