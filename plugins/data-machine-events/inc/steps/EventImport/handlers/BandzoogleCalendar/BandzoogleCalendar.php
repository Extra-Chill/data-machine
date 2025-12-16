<?php
/**
 * Bandzoogle Calendar Import
 *
 * Crawls Bandzoogle calendar month pages forward-only and imports single occurrences
 * from the /go/events popup HTML (title, date, time, notes, image).
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\BandzoogleCalendar
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\BandzoogleCalendar;

use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Steps\EventImport\EventEngineData;
use DataMachineEvents\Utilities\EventIdentifierGenerator;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if (!defined('ABSPATH')) {
    exit;
}

class BandzoogleCalendar extends EventImportHandler {

    use HandlerRegistrationTrait;

    const MAX_PAGES = 20;

    public function __construct() {
        parent::__construct('bandzoogle_calendar');

        self::registerHandler(
            'bandzoogle_calendar',
            'event_import',
            self::class,
            __('Bandzoogle Calendar', 'datamachine-events'),
            __('Import events from Bandzoogle calendar pages (forward month pagination)', 'datamachine-events'),
            false,
            null,
            BandzoogleCalendarSettings::class,
            null
        );
    }

    protected function executeFetch(int $pipeline_id, array $config, ?string $flow_step_id, int $flow_id, ?string $job_id): array {
        $calendar_url = trim($config['calendar_url'] ?? '');
        if (empty($calendar_url)) {
            $this->log('error', 'BandzoogleCalendar handler requires calendar_url configuration');
            return [];
        }

        $current_url = $this->sanitizeUrl($calendar_url);
        if (empty($current_url)) {
            $this->log('error', 'Invalid calendar_url for BandzoogleCalendar handler');
            return [];
        }

        $visited = [];
        $page = 1;

        while ($page <= self::MAX_PAGES) {
            $url_hash = md5($current_url);
            if (isset($visited[$url_hash])) {
                break;
            }
            $visited[$url_hash] = true;

            $calendar_html = $this->fetchHtml($current_url);
            if (empty($calendar_html)) {
                if ($page === 1) {
                    return [];
                }
                break;
            }

            $context = $this->parseMonthContext($calendar_html);
            if (empty($context)) {
                $context = $this->parseMonthContextFromUrl($current_url);
            }

            $occurrence_urls = $this->extractOccurrenceUrls($calendar_html, $current_url);

            foreach ($occurrence_urls as $occurrence_url) {
                $detail_html = $this->fetchHtml($occurrence_url);
                if (empty($detail_html)) {
                    continue;
                }

                $standardized_event = $this->parseOccurrenceHtml($detail_html, $occurrence_url, $context, $config);
                if (empty($standardized_event['title']) || empty($standardized_event['startDate'])) {
                    continue;
                }

                $search_text = $standardized_event['title'] . ' ' . ($standardized_event['description'] ?? '');

                if (!$this->applyKeywordSearch($search_text, $config['search'] ?? '')) {
                    continue;
                }

                if ($this->applyExcludeKeywords($search_text, $config['exclude_keywords'] ?? '')) {
                    continue;
                }

                $event_identifier = EventIdentifierGenerator::generate(
                    $standardized_event['title'],
                    $standardized_event['startDate'] ?? '',
                    $standardized_event['venue'] ?? ''
                );

                if ($this->isItemProcessed($event_identifier, $flow_step_id)) {
                    continue;
                }

                if ($this->isPastEvent($standardized_event['startDate'] ?? '')) {
                    continue;
                }

                $this->markItemProcessed($event_identifier, $flow_step_id, $job_id);

                $venue_metadata = $this->extractVenueMetadata($standardized_event);

                EventEngineData::storeVenueContext($job_id, $standardized_event, $venue_metadata);

                if (!empty($standardized_event['image'])) {
                    $this->storeImageInEngine($job_id, $standardized_event['image']);
                }

                $this->stripVenueMetadataFromEvent($standardized_event);
                unset($standardized_event['image']);

                $dataPacket = new DataPacket(
                    [
                        'title' => $standardized_event['title'],
                        'body' => wp_json_encode([
                            'event' => $standardized_event,
                            'venue_metadata' => $venue_metadata,
                            'import_source' => 'bandzoogle_calendar',
                        ], JSON_PRETTY_PRINT),
                    ],
                    [
                        'source_type' => 'bandzoogle_calendar',
                        'pipeline_id' => $pipeline_id,
                        'flow_id' => $flow_id,
                        'original_title' => $standardized_event['title'],
                        'event_identifier' => $event_identifier,
                        'import_timestamp' => time(),
                    ],
                    'event_import'
                );

                return [$dataPacket];
            }

            $next_url = $this->findNextMonthUrl($calendar_html, $current_url);
            if (empty($next_url)) {
                break;
            }

            $current_url = $next_url;
            $page++;
        }

        return [];
    }

    private function fetchHtml(string $url): string {
        $result = $this->httpGet($url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
            'browser_mode' => true,
        ]);

        if (!$result['success']) {
            return '';
        }

        if (($result['status_code'] ?? 0) !== 200) {
            return '';
        }

        return (string)($result['data'] ?? '');
    }

    /**
     * @return array{year:int, month:int}|null
     */
    private function parseMonthContext(string $html): ?array {
        if (!preg_match('#<span[^>]*class=["\']month-name["\'][^>]*>\s*([^<]+)\s*</span>#i', $html, $m)) {
            return null;
        }

        $label = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
        if (!preg_match('/^([A-Za-z]+)\s+(\d{4})$/', $label, $mm)) {
            return null;
        }

        $month_num = (int)date('n', strtotime($mm[1] . ' 1'));
        $year = (int)$mm[2];

        if ($month_num <= 0 || $year <= 0) {
            return null;
        }

        return [
            'year' => $year,
            'month' => $month_num,
        ];
    }

    /**
     * @return array{year:int, month:int}|null
     */
    private function parseMonthContextFromUrl(string $url): ?array {
        $path = (string)parse_url($url, PHP_URL_PATH);

        if (!preg_match('#/go/calendar/\d+/(\d{4})/(\d{1,2})#', $path, $m)) {
            return null;
        }

        $year = (int)$m[1];
        $month = (int)$m[2];

        if ($year <= 0 || $month < 1 || $month > 12) {
            return null;
        }

        return [
            'year' => $year,
            'month' => $month,
        ];
    }

    private function extractOccurrenceUrls(string $html, string $current_url): array {
        preg_match_all('#href=["\'](/go/events/\d+\?[^"\']*occurrence_id=\d+[^"\']*)["\']#i', $html, $matches);

        $urls = [];
        $host = parse_url($current_url, PHP_URL_HOST);
        $scheme = parse_url($current_url, PHP_URL_SCHEME) ?: 'https';

        foreach (($matches[1] ?? []) as $rel) {
            $rel = html_entity_decode($rel, ENT_QUOTES | ENT_HTML5);
            if (!str_contains($rel, 'popup=1')) {
                $rel .= (str_contains($rel, '?') ? '&' : '?') . 'popup=1';
            }

            $urls[] = $scheme . '://' . $host . $rel;
        }

        return array_values(array_unique($urls));
    }

    private function findNextMonthUrl(string $html, string $current_url): string {
        if (!preg_match('#<a[^>]*class=["\'][^"\']*\bnext\b[^"\']*["\'][^>]*href=["\']([^"\']+)["\']#i', $html, $m)) {
            return '';
        }

        $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        if (empty($href)) {
            return '';
        }

        if (strpos($href, '//') === 0) {
            return 'https:' . $href;
        }

        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $host = parse_url($current_url, PHP_URL_HOST);
        $scheme = parse_url($current_url, PHP_URL_SCHEME) ?: 'https';
        if (empty($host)) {
            return '';
        }

        if ($href[0] !== '/') {
            $href = '/' . $href;
        }

        return $scheme . '://' . $host . $href;
    }

    private function parseOccurrenceHtml(string $html, string $occurrence_url, ?array $context, array $config): array {
        $title = '';
        $source_url = $occurrence_url;
        $description = '';
        $image_url = '';
        $date_text = '';
        $time_text = '';

        if (preg_match('#<h2[^>]*class=["\'][^"\']*\bevent-title\b[^"\']*["\'][^>]*>\s*<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $m)) {
            $source_url = $this->sanitizeUrl(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
            $title = trim(wp_strip_all_tags(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5)));
        } elseif (preg_match('#<h2[^>]*class=["\'][^"\']*\bevent-title\b[^"\']*["\'][^>]*>(.*?)</h2>#is', $html, $m)) {
            $title = trim(wp_strip_all_tags(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)));
        }

        if (preg_match('#<time[^>]*class=["\']from["\'][^>]*>.*?<span[^>]*class=["\'][^"\']*\bdate\b[^"\']*["\'][^>]*>(.*?)</span>\s*@\s*<span[^>]*class=["\'][^"\']*\btime\b[^"\']*["\'][^>]*>(.*?)</span>#is', $html, $m)) {
            $date_text = trim(wp_strip_all_tags(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)));
            $time_text = trim(wp_strip_all_tags(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5)));
        } else {
            if (preg_match('#<span[^>]*class=["\'][^"\']*\bdate\b[^"\']*["\'][^>]*>(.*?)</span>#is', $html, $m)) {
                $date_text = trim(wp_strip_all_tags(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)));
            }

            if (preg_match('#<span[^>]*class=["\'][^"\']*\btime\b[^"\']*["\'][^>]*>(.*?)</span>#is', $html, $m)) {
                $time_text = trim(wp_strip_all_tags(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)));
            }
        }

        if (preg_match('#<div[^>]*class=["\'][^"\']*\bevent-notes\b[^"\']*["\'][^>]*>(.*?)</div>#is', $html, $m)) {
            $description = $this->cleanHtml($m[1]);
        }

        if (preg_match('#<div[^>]*class=["\'][^"\']*\bevent-image\b[^"\']*["\'][^>]*>.*?<img[^>]*src=["\']([^"\']+)["\']#is', $html, $m)) {
            $image_url = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
            if (strpos($image_url, '//') === 0) {
                $image_url = 'https:' . $image_url;
            }
            $image_url = $this->sanitizeUrl($image_url);
        }

        $start_date = $this->resolveDate($date_text, $context);
        $start_time = $this->parseTime($time_text);

        $event = [
            'title' => $this->sanitizeText($title),
            'description' => $description,
            'startDate' => $start_date,
            'endDate' => $start_date,
            'startTime' => $start_time,
            'endTime' => '',
            'venue' => $this->sanitizeText((string)($config['venue_name'] ?? '')),
            'venueAddress' => $this->sanitizeText((string)($config['venue_address'] ?? '')),
            'venueCity' => $this->sanitizeText((string)($config['venue_city'] ?? '')),
            'venueState' => $this->sanitizeText((string)($config['venue_state'] ?? '')),
            'venueZip' => $this->sanitizeText((string)($config['venue_zip'] ?? '')),
            'venueCountry' => $this->sanitizeText((string)($config['venue_country'] ?? '')),
            'venuePhone' => $this->sanitizeText((string)($config['venue_phone'] ?? '')),
            'venueWebsite' => $this->sanitizeUrl((string)($config['venue_website'] ?? '')),
            'ticketUrl' => $this->sanitizeUrl($source_url ?: $occurrence_url),
            'image' => $image_url,
            'price' => '',
            'priceCurrency' => '',
            'offerAvailability' => '',
            'validFrom' => '',
            'performer' => '',
            'organizer' => '',
            'organizerUrl' => '',
            'eventType' => 'Event',
            'eventStatus' => '',
            'source_url' => $source_url,
        ];

        return $event;
    }

    private function resolveDate(string $date_text, ?array $context): string {
        if (empty($date_text) || empty($context)) {
            return '';
        }

        if (!preg_match('/([A-Za-z]+)\s+(\d{1,2})$/', $date_text, $m)) {
            return '';
        }

        $month_name = $m[1];
        $day = (int)$m[2];
        $month = (int)date('n', strtotime($month_name . ' 1'));
        if ($month <= 0 || $day <= 0) {
            return '';
        }

        $year = (int)$context['year'];
        $context_month = (int)$context['month'];

        if ($month === 12 && $context_month === 1) {
            $year -= 1;
        } elseif ($month === 1 && $context_month === 12) {
            $year += 1;
        }

        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
    }

    private function parseTime(string $time_text): string {
        $time_text = trim($time_text);
        if (empty($time_text)) {
            return '';
        }

        $timestamp = strtotime($time_text);
        if (!$timestamp) {
            return '';
        }

        return date('H:i', $timestamp);
    }

    private function storeImageInEngine(?string $job_id, string $image_url): void {
        if (empty($job_id) || empty($image_url)) {
            return;
        }

        $job_id = (int)$job_id;
        if ($job_id <= 0 || !function_exists('datamachine_merge_engine_data')) {
            return;
        }

        datamachine_merge_engine_data($job_id, [
            'image_url' => $image_url,
        ]);
    }
}
