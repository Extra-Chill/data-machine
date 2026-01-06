<?php
/**
 * Base class for event import handlers providing shared sanitization, 
 * venue metadata extraction, and coordinate parsing utilities.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers
 */

namespace DataMachineEvents\Steps\EventImport\Handlers;

use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachineEvents\Core\DateTimeParser;
use DataMachineEvents\Core\VenueParameterProvider;
use DataMachineEvents\Steps\EventImport\EventEngineData;

if (!defined('ABSPATH')) {
    exit;
}

abstract class EventImportHandler extends FetchHandler {

    protected function getGlobalExcludedTitleKeywords(): array {
        return [
            'closed',
        ];
    }

    public function shouldSkipEventTitle(string $title): bool {
        if (empty($title)) {
            return false;
        }

        foreach ($this->getGlobalExcludedTitleKeywords() as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword === '') {
                continue;
            }

            if (mb_stripos($title, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

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
     * @return array Venue metadata extracted from event data
     */
    public function extractVenueMetadata(array $event): array {
        return VenueParameterProvider::extractFromEventData($event);
    }

    public function stripVenueMetadataFromEvent(array &$event): void {
        VenueParameterProvider::stripFromEventData($event);
    }

    /**
     * Check if event start date is in the past
     *
     * @param string $start_date Event start date (Y-m-d format expected)
     * @return bool True if event is in the past, false otherwise
     */
    public function isPastEvent(string $start_date): bool {
        if (empty($start_date)) {
            return false;
        }
        return strtotime($start_date) < strtotime('today');
    }

    /**
     * Public wrapper for keyword search (for use by processors).
     */
    public function applyKeywordSearch(string $text, string $search): bool {
        return parent::applyKeywordSearch($text, $search);
    }

    /**
     * Public wrapper for exclude keywords (for use by processors).
     */
    public function applyExcludeKeywords(string $text, string $exclude_keywords): bool {
        return parent::applyExcludeKeywords($text, $exclude_keywords);
    }

    /**
     * Public wrapper for processed item check (for use by processors).
     */
    public function isItemProcessed(string $item_id, ?string $flow_step_id): bool {
        return parent::isItemProcessed($item_id, $flow_step_id);
    }

    /**
     * Public wrapper for marking item processed (for use by processors).
     *
     * Also stores item context in engine data for the skip_item tool.
     * This enables the AI to skip items that don't meet criteria while
     * still marking them as processed so they won't be refetched.
     *
     * @since 0.8.31
     */
    public function markItemProcessed(string $item_id, ?string $flow_step_id, ?string $job_id): void {
        parent::markItemProcessed($item_id, $flow_step_id, $job_id);

        // Store item context for skip_item tool
        if ($job_id) {
            EventEngineData::storeItemContext((int) $job_id, $item_id, $this->handler_type);
        }
    }

    /**
     * Parse UTC datetime and convert to target timezone.
     *
     * @param string $datetime UTC datetime string
     * @param string $timezone Target IANA timezone
     * @return array{date: string, time: string, timezone: string}
     */
    protected function parseDateTimeUtc(string $datetime, string $timezone): array {
        return DateTimeParser::parseUtc($datetime, $timezone);
    }

    /**
     * Parse local datetime already in venue timezone.
     *
     * @param string $date Date string
     * @param string $time Time string
     * @param string $timezone IANA timezone identifier
     * @return array{date: string, time: string, timezone: string}
     */
    protected function parseDateTimeLocal(string $date, string $time, string $timezone): array {
        return DateTimeParser::parseLocal($date, $time, $timezone);
    }

    /**
     * Parse ISO 8601 datetime with embedded timezone.
     *
     * @param string $datetime ISO 8601 string
     * @return array{date: string, time: string, timezone: string}
     */
    protected function parseDateTimeIso(string $datetime): array {
        return DateTimeParser::parseIso($datetime);
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
