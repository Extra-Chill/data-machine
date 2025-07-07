<?php
use ChillEvents\BaseDataSource;

if (!class_exists('ForteJazzLoungeScraper')) {
class ForteJazzLoungeScraper extends BaseDataSource {
    public function get_info() {
        return [
            'name' => 'Forte Jazz Lounge Scraper',
            'type' => 'scraper',
            'description' => 'Scrapes events from Forte Jazz Lounge website.',
            'settings_fields' => []
        ];
    }

    public function get_events($settings = array()) {
        $url = 'https://forte-jazz-lounge.turntabletickets.com';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        $htmlContent = curl_exec($ch);
        curl_close($ch);
        if (!$htmlContent) {
            $this->log_error('Failed to fetch HTML content');
            return [];
        }
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        @$dom->loadHTML($htmlContent);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        $eventsNodes = $xpath->query("//div[contains(@class, 'details')]");
        if ($eventsNodes->length === 0) {
            $this->log_error('No event nodes found');
            return [];
        }
        $events = [];
        $currentYear = date('Y');
        foreach ($eventsNodes as $node) {
            $title = $xpath->evaluate("string(.//h3[contains(@class, 'font-heading')])", $node);
            $eventUrl = $xpath->evaluate("string(.//a/@href)", $node);
            $dateText = $xpath->evaluate("string(.//h4[@class='day-of-week'])", $node);
            if (empty($title) || empty($eventUrl) || empty($dateText)) {
                continue;
            }
            $dateText = str_replace(['Mon, ', 'Tue, ', 'Wed, ', 'Thu, ', 'Fri, ', 'Sat, ', 'Sun, '], '', $dateText);
            $date = date('Y-m-d', strtotime($dateText . ' ' . $currentYear));
            $startTimeNodes = $xpath->query(".//button[contains(@class, 'performance-btn')]", $node);
            if ($startTimeNodes->length === 0) {
                continue;
            }
            $startTimes = [];
            foreach ($startTimeNodes as $timeNode) {
                $startTimeText = trim($timeNode->nodeValue);
                if (preg_match('/(\d{1,2}:\d{2}\s?(AM|PM))/i', $startTimeText, $matches)) {
                    $startTimes[] = date('H:i:s', strtotime($matches[0]));
                }
            }
            if (empty($startTimes)) {
                continue;
            }
            $startDateTime = $date . ' ' . $startTimes[0];
            $endDateTime = isset($startTimes[1]) ? date('Y-m-d H:i:s', strtotime($date . ' ' . $startTimes[1]) + 3600 * 2) : date('Y-m-d H:i:s', strtotime($startDateTime) + 3600 * 2);
            $descriptionHtml = $xpath->evaluate("string(.//p[@id[contains(., 'description')]])", $node);
            $description = html_entity_decode(strip_tags($descriptionHtml, '<a><br>'));
            if (new \DateTime($startDateTime) < new \DateTime()) {
                continue;
            }
            $venueDetails = [
                'venue_name' => 'Forte Jazz Lounge',
                'address' => '477 King St',
                'location_name' => 'Charleston, SC',
                'ticket_url' => 'https://forte-jazz-lounge.turntabletickets.com' . $eventUrl
            ];
            $event = [
                'title' => trim($title),
                'start_date' => $startDateTime,
                'end_date' => $endDateTime,
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
