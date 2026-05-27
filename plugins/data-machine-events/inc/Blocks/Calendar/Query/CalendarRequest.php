<?php
/**
 * Calendar Request Value Object
 *
 * Single source of truth for the calendar request param shape.
 * Owns sanitization, defaults, and the wire-format-to-abilities-format
 * rename (`lat` → `geo_lat`, `lng` → `geo_lng`, `radius` → `geo_radius`,
 * `radius_unit` → `geo_radius_unit`).
 *
 * Two named constructors handle the two input sources:
 * - {@see fromQueryArgs()} for `$_GET` (block render path).
 * - {@see fromRestRequest()} for {@see \WP_REST_Request} (REST controller path).
 *
 * One serializer ({@see toAbilitiesArgs()}) emits the canonical args dict
 * consumed by `CalendarAbilities::executeGetCalendarPage()`, including the
 * `include_html`, `include_gaps`, and `progressive` flags that were
 * previously hardcoded in two places.
 *
 * Adding a new calendar request param means editing exactly this file.
 *
 * @package DataMachineEvents\Blocks\Calendar\Query
 * @since   0.10.0
 */

namespace DataMachineEvents\Blocks\Calendar\Query;

use WP_REST_Request;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable calendar request value object.
 */
final class CalendarRequest {

	/**
	 * Default geo radius when none provided (matches the legacy default in
	 * both render.php and the REST controller).
	 */
	private const DEFAULT_GEO_RADIUS = 25;

	/**
	 * Default geo radius unit when none provided.
	 */
	private const DEFAULT_GEO_RADIUS_UNIT = 'mi';

	/**
	 * Default page when none provided.
	 */
	private const DEFAULT_PAGED = 1;

	private int $paged;
	private bool $past;
	private string $event_search;
	private string $date_start;
	private string $date_end;
	private string $scope;

	/**
	 * Visible month for the month-grid display mode, in `YYYY-MM` form.
	 *
	 * Empty string when not in month-grid mode (or when grid mode is
	 * driven without an explicit month — defaults to the current month
	 * at the ability layer).
	 *
	 * When set, the ability scopes events to the full month range
	 * (including past dates) and ignores `paged` / `past` pagination
	 * boundaries — the month IS the page.
	 *
	 * See Extra-Chill/data-machine-events#318.
	 */
	private string $month;

	/** @var array<string,int[]> Sanitized taxonomy filter map. */
	private array $tax_filter;

	private string $archive_taxonomy;
	private int $archive_term_id;
	private string $geo_lat;
	private string $geo_lng;
	private int $geo_radius;
	private string $geo_radius_unit;

	/**
	 * Response format selector. Empty string = legacy HTML-string envelope.
	 * `'data'` = structured data-only envelope (phase 1 of refactor #298).
	 *
	 * Only REST callers populate this. The block render path (fromQueryArgs)
	 * always leaves it empty because server-render only ever produces HTML.
	 */
	private string $format;

	/**
	 * @param array<string,int[]> $tax_filter
	 */
	private function __construct(
		int $paged,
		bool $past,
		string $event_search,
		string $date_start,
		string $date_end,
		string $scope,
		array $tax_filter,
		string $archive_taxonomy,
		int $archive_term_id,
		string $geo_lat,
		string $geo_lng,
		int $geo_radius,
		string $geo_radius_unit,
		string $format = '',
		string $month = ''
	) {
		$this->paged            = $paged;
		$this->past             = $past;
		$this->event_search     = $event_search;
		$this->date_start       = $date_start;
		$this->date_end         = $date_end;
		$this->scope            = $scope;
		$this->tax_filter       = $tax_filter;
		$this->archive_taxonomy = $archive_taxonomy;
		$this->archive_term_id  = $archive_term_id;
		$this->geo_lat          = $geo_lat;
		$this->geo_lng          = $geo_lng;
		$this->geo_radius       = $geo_radius;
		$this->geo_radius_unit  = $geo_radius_unit;
		$this->format           = $format;
		$this->month            = $month;
	}

	/**
	 * Build a request from raw `$_GET` (or any associative array of query args)
	 * plus the optional archive term context resolved from `is_tax()`.
	 *
	 * Sanitization is applied to every input regardless of source, because
	 * `$_GET` is untrusted and the legacy render.php pipeline always
	 * sanitized inline.
	 *
	 * @param array<string,mixed> $get          Typically `$_GET`. Values are unslashed before sanitization.
	 * @param WP_Term|null        $archive_term Optional taxonomy archive context.
	 */
	public static function fromQueryArgs( array $get, ?WP_Term $archive_term = null ): self {
		$paged        = isset( $get['paged'] ) ? max( 1, (int) absint( $get['paged'] ) ) : self::DEFAULT_PAGED;
		$past         = isset( $get['past'] ) && '1' === (string) $get['past'];
		$event_search = isset( $get['event_search'] ) ? sanitize_text_field( wp_unslash( $get['event_search'] ) ) : '';
		$date_start   = isset( $get['date_start'] ) ? sanitize_text_field( wp_unslash( $get['date_start'] ) ) : '';
		$date_end     = isset( $get['date_end'] ) ? sanitize_text_field( wp_unslash( $get['date_end'] ) ) : '';
		$scope        = isset( $get['scope'] ) ? sanitize_key( wp_unslash( $get['scope'] ) ) : '';

		$geo_lat         = isset( $get['lat'] ) ? sanitize_text_field( wp_unslash( $get['lat'] ) ) : '';
		$geo_lng         = isset( $get['lng'] ) ? sanitize_text_field( wp_unslash( $get['lng'] ) ) : '';
		$geo_radius      = isset( $get['radius'] ) ? absint( $get['radius'] ) : self::DEFAULT_GEO_RADIUS;
		$geo_radius_unit = isset( $get['radius_unit'] ) ? sanitize_key( wp_unslash( $get['radius_unit'] ) ) : self::DEFAULT_GEO_RADIUS_UNIT;

		$tax_filter_raw = isset( $get['tax_filter'] ) ? wp_unslash( $get['tax_filter'] ) : array();
		$tax_filter     = self::sanitize_tax_filter( $tax_filter_raw );

		$archive_taxonomy = '';
		$archive_term_id  = 0;
		if ( $archive_term instanceof WP_Term ) {
			$archive_taxonomy = sanitize_key( $archive_term->taxonomy );
			$archive_term_id  = absint( $archive_term->term_id );
		}

		$month = isset( $get['month'] ) ? self::sanitize_month( wp_unslash( $get['month'] ) ) : '';

		return new self(
			$paged,
			$past,
			$event_search,
			$date_start,
			$date_end,
			$scope,
			$tax_filter,
			$archive_taxonomy,
			$archive_term_id,
			$geo_lat,
			$geo_lng,
			$geo_radius,
			$geo_radius_unit,
			'', // format is REST-only.
			$month
		);
	}

	/**
	 * Build a request from a {@see WP_REST_Request}.
	 *
	 * Sanitization is applied here even though `Routes.php` declares an
	 * `args` schema, because (a) the geo params currently lack a schema and
	 * (b) defense-in-depth — the value object is the contract, not the route
	 * registration.
	 */
	public static function fromRestRequest( WP_REST_Request $request ): self {
		$paged_raw    = $request->get_param( 'paged' );
		$paged        = null === $paged_raw ? self::DEFAULT_PAGED : max( 1, (int) absint( $paged_raw ) );
		$past         = '1' === (string) $request->get_param( 'past' );
		$event_search = sanitize_text_field( (string) ( $request->get_param( 'event_search' ) ?? '' ) );
		$date_start   = sanitize_text_field( (string) ( $request->get_param( 'date_start' ) ?? '' ) );
		$date_end     = sanitize_text_field( (string) ( $request->get_param( 'date_end' ) ?? '' ) );
		$scope        = sanitize_key( (string) ( $request->get_param( 'scope' ) ?? '' ) );

		$geo_lat         = sanitize_text_field( (string) ( $request->get_param( 'lat' ) ?? '' ) );
		$geo_lng         = sanitize_text_field( (string) ( $request->get_param( 'lng' ) ?? '' ) );
		$radius_raw      = $request->get_param( 'radius' );
		$geo_radius      = null === $radius_raw ? self::DEFAULT_GEO_RADIUS : absint( $radius_raw );
		$radius_unit_raw = $request->get_param( 'radius_unit' );
		$geo_radius_unit = null === $radius_unit_raw || '' === $radius_unit_raw
			? self::DEFAULT_GEO_RADIUS_UNIT
			: sanitize_key( (string) $radius_unit_raw );

		$tax_filter = self::sanitize_tax_filter( $request->get_param( 'tax_filter' ) );

		$archive_taxonomy = sanitize_key( (string) ( $request->get_param( 'archive_taxonomy' ) ?? '' ) );
		$archive_term_id  = absint( $request->get_param( 'archive_term_id' ) ?? 0 );

		// Phase 1 of refactor #298: `format=data` opts into the structured
		// data-only envelope. Any other value (including the absent param)
		// falls back to the legacy HTML-string envelope. We whitelist `'data'`
		// explicitly so future format names (`v2`, `summary`, etc.) can be
		// added without silently activating.
		$format_raw = sanitize_key( (string) ( $request->get_param( 'format' ) ?? '' ) );
		$format     = ( 'data' === $format_raw ) ? 'data' : '';

		$month = self::sanitize_month( (string) ( $request->get_param( 'month' ) ?? '' ) );

		return new self(
			$paged,
			$past,
			$event_search,
			$date_start,
			$date_end,
			$scope,
			$tax_filter,
			$archive_taxonomy,
			$archive_term_id,
			$geo_lat,
			$geo_lng,
			$geo_radius,
			$geo_radius_unit,
			$format,
			$month
		);
	}

	/**
	 * Serialize to the args dict consumed by
	 * `CalendarAbilities::executeGetCalendarPage()`.
	 *
	 * - `include_html` follows the response format: the HTML envelope path
	 *   needs server-rendered strings, the data envelope path does not.
	 *   Skipping HTML rendering on the data path saves the `ob_start()`
	 *   template runs in CalendarAbilities::renderHtml().
	 * - `progressive` is also gated on the HTML path. Progressive rendering
	 *   is a server-render concern (only emit the first day's events as
	 *   real DOM, defer the rest as shells) — irrelevant once the client
	 *   owns rendering from data.
	 * - `include_gaps` stays true regardless: gap detection is a data
	 *   transformation, the data envelope exposes the `gaps_detected` map
	 *   so the client can render time-gap separators itself.
	 *
	 * @return array<string,mixed>
	 */
	public function toAbilitiesArgs(): array {
		$is_data_format = ( 'data' === $this->format );

		return array(
			'paged'            => $this->paged,
			'past'             => $this->past,
			'event_search'     => $this->event_search,
			'date_start'       => $this->date_start,
			'date_end'         => $this->date_end,
			'scope'            => $this->scope,
			'tax_filter'       => $this->tax_filter,
			'archive_taxonomy' => $this->archive_taxonomy,
			'archive_term_id'  => $this->archive_term_id,
			'geo_lat'          => $this->geo_lat,
			'geo_lng'          => $this->geo_lng,
			'geo_radius'       => $this->geo_radius,
			'geo_radius_unit'  => $this->geo_radius_unit,
			'include_html'     => ! $is_data_format,
			'include_gaps'     => true,
			'progressive'      => ! $is_data_format,
			'month'            => $this->month,
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Accessors                                                          */
	/* ------------------------------------------------------------------ */
	/*  Render.php still needs raw access to a few fields for its own       */
	/*  template wiring (data attrs, filter-bar params, etc).               */
	/* ------------------------------------------------------------------ */

	public function paged(): int {
		return $this->paged;
	}

	public function past(): bool {
		return $this->past;
	}

	public function eventSearch(): string {
		return $this->event_search;
	}

	public function dateStart(): string {
		return $this->date_start;
	}

	public function dateEnd(): string {
		return $this->date_end;
	}

	public function scope(): string {
		return $this->scope;
	}

	/** @return array<string,int[]> */
	public function taxFilter(): array {
		return $this->tax_filter;
	}

	public function archiveTaxonomy(): string {
		return $this->archive_taxonomy;
	}

	public function archiveTermId(): int {
		return $this->archive_term_id;
	}

	public function geoLat(): string {
		return $this->geo_lat;
	}

	public function geoLng(): string {
		return $this->geo_lng;
	}

	public function geoRadius(): int {
		return $this->geo_radius;
	}

	public function geoRadiusUnit(): string {
		return $this->geo_radius_unit;
	}

	/**
	 * Response format. Empty string for the legacy HTML envelope, `'data'`
	 * for the structured data-only envelope (phase 1 of refactor #298).
	 *
	 * Used by the REST controller to branch into the data envelope builder
	 * AND fed into the full-response cache key so HTML and data responses
	 * never share a cache bucket.
	 */
	public function format(): string {
		return $this->format;
	}

	/**
	 * Visible month for the month-grid display mode (`YYYY-MM`), or empty
	 * string when not set. See `$month` doc for semantics.
	 */
	public function month(): string {
		return $this->month;
	}

	/* ------------------------------------------------------------------ */
	/*  Internal                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Sanitize a `YYYY-MM` month string. Returns an empty string for any
	 * input that does not match the format exactly OR that does not
	 * represent a valid calendar month (e.g. month 13).
	 *
	 * @param mixed $raw
	 */
	private static function sanitize_month( $raw ): string {
		$str = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $str ) {
			return '';
		}
		if ( ! preg_match( '/^(\d{4})-(\d{2})$/', $str, $matches ) ) {
			return '';
		}
		$year  = (int) $matches[1];
		$month = (int) $matches[2];
		if ( $year < 1970 || $year > 2999 || $month < 1 || $month > 12 ) {
			return '';
		}
		return sprintf( '%04d-%02d', $year, $month );
	}

	/**
	 * Sanitize the `tax_filter` map to `array<string,int[]>` with no empty
	 * sub-arrays. Accepts the same shapes the legacy render.php and REST
	 * route-args sanitizer accepted.
	 *
	 * @param mixed $raw
	 * @return array<string,int[]>
	 */
	private static function sanitize_tax_filter( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();
		foreach ( $raw as $taxonomy_slug => $term_ids ) {
			$taxonomy_slug = sanitize_key( (string) $taxonomy_slug );
			if ( '' === $taxonomy_slug ) {
				continue;
			}
			$term_ids   = (array) $term_ids;
			$clean_ids  = array();
			foreach ( $term_ids as $term_id ) {
				$term_id = absint( $term_id );
				if ( $term_id > 0 ) {
					$clean_ids[] = $term_id;
				}
			}
			if ( ! empty( $clean_ids ) ) {
				$clean[ $taxonomy_slug ] = $clean_ids;
			}
		}

		return $clean;
	}
}
