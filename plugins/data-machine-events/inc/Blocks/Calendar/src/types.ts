/**
 * Shared type definitions for the Calendar block frontend.
 *
 * These interfaces mirror the PHP data shapes from CalendarAbilities,
 * FilterAbilities, and the REST API controllers.
 */

/* ------------------------------------------------------------------ */
/*  Geo                                                                */
/* ------------------------------------------------------------------ */

export interface GeoContext {
	lat: string;
	lng: string;
	radius: number;
	radius_unit: 'mi' | 'km';
	label?: string;
}

/** Stored geo preference in localStorage. */
export interface StoredGeo extends GeoContext {
	label: string;
}

/* ------------------------------------------------------------------ */
/*  Date                                                               */
/* ------------------------------------------------------------------ */

export interface DateContext {
	date_start: string;
	date_end: string;
	past: string;
}

/* ------------------------------------------------------------------ */
/*  Archive                                                            */
/* ------------------------------------------------------------------ */

export interface ArchiveContext {
	taxonomy: string;
	term_id: number;
	term_name: string;
}

/* ------------------------------------------------------------------ */
/*  Taxonomy filters                                                   */
/* ------------------------------------------------------------------ */

/** Keyed by taxonomy slug, values are term IDs. */
export type TaxFilters = Record<string, number[]>;

export interface TaxonomyTerm {
	term_id: number;
	name: string;
	slug: string;
	event_count: number;
	children?: TaxonomyTerm[];
}

export interface FlatTaxonomyTerm extends TaxonomyTerm {
	level: number;
}

export interface TaxonomyData {
	label: string;
	terms: TaxonomyTerm[];
}

/* ------------------------------------------------------------------ */
/*  REST API request shape                                             */
/* ------------------------------------------------------------------ */

/**
 * The complete set of calendar URL params understood by the
 * `/wp-json/datamachine/v1/events/calendar` endpoint, expressed as
 * URL-wire strings (everything that ends up in URLSearchParams is a
 * string at the network boundary).
 *
 * This is the single source of truth used by `buildCalendarRequest()`
 * to drive passthrough from `window.location.search` and to type the
 * `overrides` map. Adding a new calendar URL param means adding it
 * here once.
 *
 * `tax_filter[*]` is intentionally not modeled here — it's a dynamic
 * key family, not a fixed param, and `buildCalendarRequest()` walks
 * `urlParams.entries()` to passthrough every `tax_filter[...]` key.
 */
export interface CalendarRequest {
	event_search: string;
	scope: string;
	past: string;
	paged: string;
	date_start: string;
	date_end: string;
	archive_taxonomy: string;
	archive_term_id: string;
	lat: string;
	lng: string;
	radius: string;
	radius_unit: string;
}

/* ------------------------------------------------------------------ */
/*  REST API responses                                                 */
/* ------------------------------------------------------------------ */

export interface CalendarResponse {
	success: boolean;
	html: string;
	pagination: { html: string } | null;
	counter: string | null;
	navigation: { html: string } | null;
}

/* ------------------------------------------------------------------ */
/*  Data-only REST response (phase 1 of refactor #298)                 */
/* ------------------------------------------------------------------ */
/*  Activated by passing `format=data` to                              */
/*  `/wp-json/datamachine/v1/events/calendar`. The legacy              */
/*  `CalendarResponse` schema above remains the default. No existing   */
/*  consumer is wired to this shape yet — porting consumers is the     */
/*  scope of phase 2+ in #298.                                         */
/*                                                                     */
/*  See `docs/calendar-data-schema.md` for the full schema doc.        */
/* ------------------------------------------------------------------ */

/** Identifies the schema version + phase the server returned. */
export interface CalendarDataSchemaMeta {
	name: 'calendar-data';
	version: 1;
	phase: 1;
	issue: 298;
}

/** Date / time slot for a single event, sourced from EventHydrator. */
export interface CalendarEventDate {
	start_date: string;
	start_time: string;
	end_date: string;
	end_time: string;
	venue_timezone: string;
}

/** Venue term attached to an event, or null when none. */
export interface CalendarEventVenue {
	term_id: number;
	name: string;
	slug: string;
	address: string;
}

/** Organizer (promoter) attached to an event, or null when none. */
export interface CalendarEventOrganizer {
	name: string;
	url: string;
	type: string;
}

/** Single taxonomy term summary as surfaced in `event.taxonomies`. */
export interface CalendarEventTaxonomyTerm {
	term_id: number;
	name: string;
	slug: string;
	link: string;
}

/** Structured event object. One entry per post_id regardless of multi-day expansion. */
export interface CalendarEventItem {
	id: number;
	title: string;
	permalink: string;
	date: CalendarEventDate;
	venue: CalendarEventVenue | null;
	organizer: CalendarEventOrganizer | null;
	ticket: { url: string };
	performer: { name: string };
	address: string;
	taxonomies: Record<string, CalendarEventTaxonomyTerm[]>;
}

/**
 * Per-occurrence display context carried by `grouping.by_date`.
 * Mirrors the `display_context` slot produced by `DateGrouper::group_events_by_date()`:
 * an event can appear under multiple dates (multi-day), and each occurrence
 * carries its own continuation / start-day / day-number flags.
 */
export interface CalendarEventOccurrenceContext {
	is_multi_day?: boolean;
	is_start_day?: boolean;
	is_end_day?: boolean;
	is_continuation?: boolean;
	display_date?: string;
	original_start_date?: string;
	original_end_date?: string;
	day_number?: number;
	total_days?: number;
}

/** A single occurrence of an event on a specific date. */
export interface CalendarEventOccurrence {
	post_id: number;
	display_context: CalendarEventOccurrenceContext;
}

/** Date grouping index. Date keys are `Y-m-d` strings. */
export interface CalendarGrouping {
	/** Ordered list of dates as rendered on this page. */
	ordered_dates: string[];
	/** `Y-m-d => occurrence[]` map matching `ordered_dates`. */
	by_date: Record<string, CalendarEventOccurrence[]>;
	/** `Y-m-d => gap_days` map; gaps >= 2 days from `DateGrouper::detect_time_gaps`. */
	gaps: Record<string, number>;
}

/** Pagination metadata as JSON — no HTML. */
export interface CalendarDataPagination {
	current_page: number;
	total_pages: number;
	total_items: number;
	page_items: number;
}

/** "Showing X of Y events between A and B" counter as JSON. */
export interface CalendarDataCounter {
	showing_count: number;
	total_count: number;
	page_start_date: string;
	page_end_date: string;
}

/** Past / future navigation state as JSON. */
export interface CalendarDataNavigation {
	show_past: boolean;
	past_count: number;
	future_count: number;
	has_past: boolean;
	has_future: boolean;
}

/** Full data-only response envelope. */
export interface CalendarDataResponse {
	success: boolean;
	schema: CalendarDataSchemaMeta;
	events: CalendarEventItem[];
	grouping: CalendarGrouping;
	pagination: CalendarDataPagination;
	counter: CalendarDataCounter;
	navigation: CalendarDataNavigation;
}

export interface FilterResponse {
	success: boolean;
	taxonomies: Record<string, TaxonomyData>;
	archive_context?: ArchiveContext;
	geo_context?: {
		active: boolean;
		venue_count: number;
	};
}

/* ------------------------------------------------------------------ */
/*  Lazy render — event placeholder JSON payload                       */
/* ------------------------------------------------------------------ */

export interface EventDisplayVars {
	formatted_time_display: string;
	performer_name: string;
	show_performer: boolean;
	multi_day_label: string;
	venue_name: string;
	iso_start_date: string;
	ticket_url: string;
	show_ticket_link: boolean;
	is_continuation?: boolean;
	is_multi_day?: boolean;
}

export interface EventPlaceholderData {
	title: string;
	permalink: string;
	badges_html: string;
	button_classes: string;
	display_vars?: EventDisplayVars;
}

/* ------------------------------------------------------------------ */
/*  Flatpickr (minimal type surface we use)                            */
/* ------------------------------------------------------------------ */

export interface FlatpickrInstance {
	selectedDates: Date[];
	clear: () => void;
	destroy: () => void;
}

/* ------------------------------------------------------------------ */
/*  Carousel observer tracking                                         */
/* ------------------------------------------------------------------ */

export interface CarouselObserverEntry {
	observer: ResizeObserver;
	wrapper: HTMLElement;
	events: NodeListOf<HTMLElement>;
}
