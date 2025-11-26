<?php
/**
 * Base class for event import handlers providing shared sanitization, 
 * venue metadata extraction, and coordinate parsing utilities.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers
 */

namespace DataMachineEvents\Steps\EventImport\Handlers;

use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;

if (!defined('ABSPATH')) {
    exit;
}

abstract class EventImportHandler extends FetchHandler {
    
    public function __construct(string $handler_type) {
        parent::__construct($handler_type);
    }

    protected function sanitizeText(string $text): string {
        return sanitize_text_field(trim($text));
    }

    protected function sanitizeUrl(string $url): string {
        $url = trim($url);
        
        if (!empty($url) && !preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    protected function cleanHtml(string $html): string {
        if (empty($html)) {
            return '';
        }
        return html_entity_decode(strip_tags($html, '<a><br><p>'));
    }

    /**
     * @return array{venueAddress: string, venueCity: string, venueState: string, venueZip: string, venueCountry: string, venuePhone: string, venueWebsite: string, venueCoordinates: string}
     */
    protected function extractVenueMetadata(array $event): array {
        return [
            'venueAddress' => $event['venueAddress'] ?? '',
            'venueCity' => $event['venueCity'] ?? '',
            'venueState' => $event['venueState'] ?? '',
            'venueZip' => $event['venueZip'] ?? '',
            'venueCountry' => $event['venueCountry'] ?? '',
            'venuePhone' => $event['venuePhone'] ?? '',
            'venueWebsite' => $event['venueWebsite'] ?? '',
            'venueCoordinates' => $event['venueCoordinates'] ?? ''
        ];
    }

    protected function stripVenueMetadataFromEvent(array &$event): void {
        unset(
            $event['venueAddress'],
            $event['venueCity'],
            $event['venueState'],
            $event['venueZip'],
            $event['venueCountry'],
            $event['venuePhone'],
            $event['venueWebsite'],
            $event['venueCoordinates']
        );
    }

    /**
     * @return array{lat: float, lng: float}|false
     */
    protected function parseCoordinates(string $location): array|false {
        $location = trim($location);
        $coords = explode(',', $location);
        
        if (count($coords) !== 2) {
            return false;
        }
        
        $lat = trim($coords[0]);
        $lng = trim($coords[1]);
        
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return false;
        }
        
        $lat = floatval($lat);
        $lng = floatval($lng);
        
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return false;
        }
        
        return [
            'lat' => $lat,
            'lng' => $lng
        ];
    }
}
