<?php
/**
 * Schema.org microdata extractor.
 *
 * Extracts event data from HTML pages using Schema.org microdata attributes
 * (itemtype, itemprop) for Event structured data.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if (!defined('ABSPATH')) {
    exit;
}

class MicrodataExtractor extends BaseExtractor {

    public function canExtract(string $html): bool {
        return strpos($html, 'itemtype="https://schema.org/Event"') !== false
            || strpos($html, 'itemtype="http://schema.org/Event"') !== false
            || strpos($html, "itemtype='https://schema.org/Event'") !== false
            || strpos($html, "itemtype='http://schema.org/Event'") !== false;
    }

    public function extract(string $html, string $source_url): array {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        $event_elements = $xpath->query("//*[@itemtype='https://schema.org/Event' or @itemtype='http://schema.org/Event']");

        if ($event_elements->length === 0) {
            return [];
        }

        $event = $this->parseEventElement($xpath, $event_elements->item(0));

        if (empty($event['title']) || empty($event['startDate'])) {
            return [];
        }

        return [$event];
    }

    public function getMethod(): string {
        return 'microdata';
    }

    /**
     * Parse event data from a Schema.org Event microdata element.
     *
     * @param \DOMXPath $xpath XPath query object
     * @param \DOMNode $event_element Event element node
     * @return array Parsed event data
     */
    private function parseEventElement(\DOMXPath $xpath, \DOMNode $event_element): array {
        $event = [];

        $this->parseBasicProperties($xpath, $event_element, $event);
        $this->parseDates($xpath, $event_element, $event);
        $this->parsePerformerAndOrganizer($xpath, $event_element, $event);
        $this->parseLocation($xpath, $event_element, $event);
        $this->parseOffers($xpath, $event_element, $event);
        $this->parseImage($xpath, $event_element, $event);

        return $event;
    }

    /**
     * Parse basic event properties (name, description).
     */
    private function parseBasicProperties(\DOMXPath $xpath, \DOMNode $event_element, array &$event): void {
        $name = $xpath->query(".//*[@itemprop='name']", $event_element);
        if ($name->length > 0) {
            $event['title'] = trim($name->item(0)->textContent);
        }

        $description = $xpath->query(".//*[@itemprop='description']", $event_element);
        if ($description->length > 0) {
            $event['description'] = trim($description->item(0)->textContent);
        }
    }

    /**
     * Parse start and end dates.
     *
     * Microdata dates typically include timezone offset (ISO 8601 format).
     */
    private function parseDates(\DOMXPath $xpath, \DOMNode $event_element, array &$event): void {
        $start_date = $xpath->query(".//*[@itemprop='startDate']", $event_element);
        if ($start_date->length > 0) {
            $datetime = $this->extractDatetime($start_date->item(0));
            if (!empty($datetime)) {
                $parsed = $this->parseDatetime($datetime);
                $event['startDate'] = $parsed['date'];
                $event['startTime'] = $parsed['time'] !== '00:00' ? $parsed['time'] : '';
            }
        }

        $end_date = $xpath->query(".//*[@itemprop='endDate']", $event_element);
        if ($end_date->length > 0) {
            $datetime = $this->extractDatetime($end_date->item(0));
            if (!empty($datetime)) {
                $parsed = $this->parseDatetime($datetime);
                $event['endDate'] = $parsed['date'];
                $event['endTime'] = $parsed['time'];
            }
        }
    }

    /**
     * Parse performer and organizer.
     */
    private function parsePerformerAndOrganizer(\DOMXPath $xpath, \DOMNode $event_element, array &$event): void {
        $performer = $xpath->query(".//*[@itemprop='performer']", $event_element);
        if ($performer->length > 0) {
            $performer_name = $xpath->query(".//*[@itemprop='name']", $performer->item(0));
            if ($performer_name->length > 0) {
                $event['performer'] = trim($performer_name->item(0)->textContent);
            } else {
                $event['performer'] = trim($performer->item(0)->textContent);
            }
        }

        $organizer = $xpath->query(".//*[@itemprop='organizer']", $event_element);
        if ($organizer->length > 0) {
            $organizer_name = $xpath->query(".//*[@itemprop='name']", $organizer->item(0));
            if ($organizer_name->length > 0) {
                $event['organizer'] = trim($organizer_name->item(0)->textContent);
            } else {
                $event['organizer'] = trim($organizer->item(0)->textContent);
            }
        }
    }

    /**
     * Parse location/venue data.
     */
    private function parseLocation(\DOMXPath $xpath, \DOMNode $event_element, array &$event): void {
        $location = $xpath->query(".//*[@itemprop='location']", $event_element);
        if ($location->length === 0) {
            return;
        }

        $location_element = $location->item(0);

        $venue_name = $xpath->query(".//*[@itemprop='name']", $location_element);
        if ($venue_name->length > 0) {
            $event['venue'] = trim($venue_name->item(0)->textContent);
        }

        $this->parseAddress($xpath, $location_element, $event);
        $this->parseLocationDetails($xpath, $location_element, $event);
        $this->parseGeo($xpath, $location_element, $event);
    }

    /**
     * Parse address components.
     */
    private function parseAddress(\DOMXPath $xpath, \DOMNode $location_element, array &$event): void {
        $address = $xpath->query(".//*[@itemprop='address']", $location_element);
        if ($address->length === 0) {
            return;
        }

        $address_element = $address->item(0);

        $street = $xpath->query(".//*[@itemprop='streetAddress']", $address_element);
        if ($street->length > 0) {
            $event['venueAddress'] = trim($street->item(0)->textContent);
        }

        $locality = $xpath->query(".//*[@itemprop='addressLocality']", $address_element);
        if ($locality->length > 0) {
            $event['venueCity'] = trim($locality->item(0)->textContent);
        }

        $region = $xpath->query(".//*[@itemprop='addressRegion']", $address_element);
        if ($region->length > 0) {
            $event['venueState'] = trim($region->item(0)->textContent);
        }

        $postal = $xpath->query(".//*[@itemprop='postalCode']", $address_element);
        if ($postal->length > 0) {
            $event['venueZip'] = trim($postal->item(0)->textContent);
        }

        $country = $xpath->query(".//*[@itemprop='addressCountry']", $address_element);
        if ($country->length > 0) {
            $event['venueCountry'] = trim($country->item(0)->textContent);
        }
    }

    /**
     * Parse phone and website from location.
     */
    private function parseLocationDetails(\DOMXPath $xpath, \DOMNode $location_element, array &$event): void {
        $telephone = $xpath->query(".//*[@itemprop='telephone']", $location_element);
        if ($telephone->length > 0) {
            $event['venuePhone'] = trim($telephone->item(0)->textContent);
        }

        $url = $xpath->query(".//*[@itemprop='url']", $location_element);
        if ($url->length > 0) {
            $website = $this->extractHrefOrContent($url->item(0));
            if (!empty($website)) {
                $event['venueWebsite'] = trim($website);
            }
        }
    }

    /**
     * Parse geo coordinates from location.
     */
    private function parseGeo(\DOMXPath $xpath, \DOMNode $location_element, array &$event): void {
        $geo = $xpath->query(".//*[@itemprop='geo']", $location_element);
        if ($geo->length === 0) {
            return;
        }

        $geo_element = $geo->item(0);
        $latitude = $xpath->query(".//*[@itemprop='latitude']", $geo_element);
        $longitude = $xpath->query(".//*[@itemprop='longitude']", $geo_element);

        if ($latitude->length > 0 && $longitude->length > 0) {
            $lat = trim($latitude->item(0)->textContent);
            $lng = trim($longitude->item(0)->textContent);
            $event['venueCoordinates'] = $lat . ',' . $lng;
        }
    }

    /**
     * Parse offers/pricing data.
     */
    private function parseOffers(\DOMXPath $xpath, \DOMNode $event_element, array &$event): void {
        $offers = $xpath->query(".//*[@itemprop='offers']", $event_element);
        if ($offers->length === 0) {
            return;
        }

        $offers_element = $offers->item(0);

        $price = $xpath->query(".//*[@itemprop='price']", $offers_element);
        if ($price->length > 0) {
            $event['price'] = trim($price->item(0)->textContent);
        }

        $ticket_url = $xpath->query(".//*[@itemprop='url']", $offers_element);
        if ($ticket_url->length > 0) {
            $url = $this->extractHrefOrContent($ticket_url->item(0));
            if (!empty($url)) {
                $event['ticketUrl'] = trim($url);
            }
        }
    }

    /**
     * Parse image data.
     */
    private function parseImage(\DOMXPath $xpath, \DOMNode $event_element, array &$event): void {
        $image = $xpath->query(".//*[@itemprop='image']", $event_element);
        if ($image->length === 0) {
            return;
        }

        $image_node = $image->item(0);
        $image_value = '';

        if ($image_node instanceof \DOMElement) {
            $image_value = $image_node->getAttribute('src')
                ?: $image_node->getAttribute('href')
                ?: $image_node->textContent;
        } elseif ($image_node) {
            $image_value = $image_node->textContent;
        }

        if (!empty($image_value)) {
            $event['imageUrl'] = trim($image_value);
        }
    }

    /**
     * Extract datetime from element (attribute or content).
     */
    private function extractDatetime(\DOMNode $node): string {
        if ($node instanceof \DOMElement) {
            return $node->getAttribute('datetime') ?: $node->textContent;
        }
        return $node->textContent;
    }

    /**
     * Extract href attribute or text content from element.
     */
    private function extractHrefOrContent(\DOMNode $node): string {
        if ($node instanceof \DOMElement) {
            return $node->getAttribute('href') ?: $node->textContent;
        }
        return $node->textContent;
    }
}
