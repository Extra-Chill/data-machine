<?php
/**
 * Google Calendar utilities: calendar ID/URL handling
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\GoogleCalendar
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\GoogleCalendar;

if (!defined('ABSPATH')) {
    exit;
}

class GoogleCalendarUtils {

    /**
     * Detect if a value looks like a calendar URL (ICS or calendar.google.com UI link)
     *
     * @param string $value Candidate string
     * @return bool
     */
    public static function is_calendar_url_like(string $value): bool {
        $v = trim($value);
        if (empty($v)) {
            return false;
        }

        if (str_contains($v, 'calendar.google.com')) {
            return true;
        }

        if (preg_match('/^https?:\/\//i', $v)) {
            return true;
        }

        if (str_ends_with($v, '.ics')) {
            return true;
        }

        return false;
    }

    /**
     * Generate a Google public ICS URL from a calendar ID
     *
     * @param string $calendar_id Calendar identifier (email or group id)
     * @return string ICS feed URL
     */
    public static function generate_ics_url_from_calendar_id(string $calendar_id): string {
        $calendar_id = trim($calendar_id);
        $encoded = rawurlencode($calendar_id);
        return "https://calendar.google.com/calendar/ical/{$encoded}/public/basic.ics";
    }

    /**
     * Resolve config calendar_url from either calendar_url or calendar_id
     *
     * @param array $config Handler config array
     * @return string|null Resolved calendar URL or null
     */
    public static function resolve_calendar_url(array $config): ?string {
        $calendar_url = trim($config['calendar_url'] ?? '');
        $calendar_id = trim($config['calendar_id'] ?? '');

        if (!empty($calendar_url)) {
            return $calendar_url;
        }

        if (empty($calendar_id)) {
            return null;
        }

        // If the id looks like a URL, return it as-is
        if (self::is_calendar_url_like($calendar_id) && preg_match('/^https?:\/\//i', $calendar_id)) {
            return $calendar_id;
        }

        return self::generate_ics_url_from_calendar_id($calendar_id);
    }
}
