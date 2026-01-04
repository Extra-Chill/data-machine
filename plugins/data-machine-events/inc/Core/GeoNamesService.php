<?php
/**
 * GeoNames API Service for timezone lookups from coordinates.
 *
 * Derives IANA timezone identifiers from latitude/longitude coordinates
 * using the GeoNames free web service API.
 *
 * @package DataMachineEvents\Core
 * @see https://www.geonames.org/export/web-services.html#timezone
 */

namespace DataMachineEvents\Core;

use DataMachine\Core\HttpClient;
use DataMachineEvents\Admin\Settings_Page;

if (!defined('ABSPATH')) {
    exit;
}

class GeoNamesService {

    private const API_ENDPOINT = 'https://secure.geonames.org/timezoneJSON';

    /**
     * Get timezone from coordinates using GeoNames API.
     *
     * @param string $coordinates Coordinates as "lat,lng" string
     * @return string|null IANA timezone identifier or null on failure
     */
    public static function getTimezoneFromCoordinates(string $coordinates): ?string {
        if (empty($coordinates)) {
            return null;
        }

        $username = self::getUsername();
        if (empty($username)) {
            return null;
        }

        $parts = explode(',', $coordinates);
        if (count($parts) !== 2) {
            return null;
        }

        $lat = trim($parts[0]);
        $lng = trim($parts[1]);

        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        $url = add_query_arg([
            'lat' => $lat,
            'lng' => $lng,
            'username' => $username,
        ], self::API_ENDPOINT);

        $result = HttpClient::get($url, [
            'timeout' => 10,
            'context' => 'GeoNames Timezone Lookup',
        ]);

        if (!$result['success']) {
            error_log('DM Events GeoNames Error: ' . ($result['error'] ?? 'Unknown error'));
            return null;
        }

        $data = json_decode($result['data'], true);

        if (empty($data) || !is_array($data)) {
            return null;
        }

        if (isset($data['status'])) {
            error_log('DM Events GeoNames API Error: ' . ($data['status']['message'] ?? 'Unknown'));
            return null;
        }

        if (!empty($data['timezoneId']) && DateTimeParser::isValidTimezone($data['timezoneId'])) {
            return $data['timezoneId'];
        }

        return null;
    }

    /**
     * Get GeoNames username from settings.
     *
     * @return string Username or empty string if not configured
     */
    public static function getUsername(): string {
        return Settings_Page::get_setting('geonames_username', '');
    }

    /**
     * Check if GeoNames service is configured.
     *
     * @return bool True if username is configured
     */
    public static function isConfigured(): bool {
        return !empty(self::getUsername());
    }

    /**
     * Validate GeoNames username by making a test API call.
     *
     * Uses coordinates for New York City as a test location.
     *
     * @param string $username Username to validate
     * @return array{valid: bool, error: string}
     */
    public static function validateUsername(string $username): array {
        if (empty($username)) {
            return [
                'valid' => false,
                'error' => 'Username is required',
            ];
        }

        $url = add_query_arg([
            'lat' => '40.7128',
            'lng' => '-74.0060',
            'username' => $username,
        ], self::API_ENDPOINT);

        $result = HttpClient::get($url, [
            'timeout' => 10,
            'context' => 'GeoNames Username Validation',
        ]);

        if (!$result['success']) {
            return [
                'valid' => false,
                'error' => $result['error'] ?? 'Connection failed',
            ];
        }

        $data = json_decode($result['data'], true);

        if (isset($data['status'])) {
            return [
                'valid' => false,
                'error' => $data['status']['message'] ?? 'API error',
            ];
        }

        if (!empty($data['timezoneId'])) {
            return [
                'valid' => true,
                'error' => '',
            ];
        }

        return [
            'valid' => false,
            'error' => 'Unexpected API response',
        ];
    }
}
