<?php
use ChillEvents\BaseDataSource;

if (!class_exists('BurgundyLoungeScraper')) {
class BurgundyLoungeScraper extends BaseDataSource {
    public function get_info() {
        return [
            'name' => 'The Burgundy Lounge Scraper',
            'type' => 'scraper',
            'description' => 'Scrapes events from The Burgundy Lounge website.',
            'settings_fields' => []
        ];
    }

    public function get_events($settings = array()) {
        $url = 'https://www.starlightchs.com/events';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        $htmlContent = curl_exec($ch);
        curl_close($ch);
        if (!$htmlContent) {
            $this->log_error('Failed to retrieve HTML content.');
            return [];
        }
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        @$dom->loadHTML($htmlContent);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        $paragraphs = $xpath->query("//div[contains(@class, 'u_1058095290')]//p");
        if (!$paragraphs || $paragraphs->length === 0) {
            $this->log_error('No paragraphs found with the specified class.');
            return [];
        }
        $events = [];
        $eventDetails = [];
        $currentDate = '';
        foreach ($paragraphs as $paragraph) {
            $text = trim($paragraph->textContent);
            if (empty($text)) {
                if (!empty($eventDetails)) {
                    $event = $this->processBurgundyLoungeDetails($eventDetails, $currentDate);
                    if ($event) {
                        $events[] = $this->standardize_event_data($event);
                    }
                    $eventDetails = [];
                }
                continue;
            }
            if (preg_match('/^[A-Za-z]+, \w+ \d+$/', $text)) {
                $currentDate = \DateTime::createFromFormat('l, F d Y', $text . ' ' . date('Y'))->format('Y-m-d');
            } else {
                $eventDetails[] = $text;
            }
        }
        if (!empty($eventDetails)) {
            $event = $this->processBurgundyLoungeDetails($eventDetails, $currentDate);
            if ($event) {
                $events[] = $this->standardize_event_data($event);
            }
        }
        return $events;
    }

    private function processBurgundyLoungeDetails($eventDetails, $currentDate) {
        $eventName = implode(' ', array_slice($eventDetails, 0, -1));
        $eventTimeText = end($eventDetails);
        if (!preg_match('/(\d+)-(\d+)([ap]m)?/', $eventTimeText, $matches)) {
            $this->log_error("Invalid time format: '{$eventTimeText}' for event: '{$eventName}'");
            return false;
        }
        $startTime = (int)$matches[1];
        $endTime = (int)$matches[2];
        $endPeriod = strtolower($matches[3] ?? 'pm');
        if ($startTime < 12) $startTime += 12;
        if ($endPeriod === 'p' && $endTime < 12) {
            $endTime += 12;
        } else if ($endPeriod === 'a' && $endTime === 12) {
            $endTime = 0;
        }
        $startDateFormatted = $currentDate . ' ' . str_pad($startTime, 2, '0', STR_PAD_LEFT) . ':00';
        $endDateFormatted = $currentDate . ' ' . str_pad($endTime, 2, '0', STR_PAD_LEFT) . ':00';
        if ($endTime <= $startTime) {
            $endDateFormatted = date('Y-m-d H:i:s', strtotime($endDateFormatted . ' +1 day'));
        }
        return [
            'title' => $eventName,
            'start_date' => $startDateFormatted,
            'end_date' => $endDateFormatted,
            'venue_name' => 'The Burgundy Lounge',
            'address' => '3245 Rivers Ave.',
            'location_name' => 'North Charleston, SC'
        ];
    }
}
}


