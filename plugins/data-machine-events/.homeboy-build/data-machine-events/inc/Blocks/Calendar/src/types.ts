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
/*  REST API responses                                                 */
/* ------------------------------------------------------------------ */

export interface CalendarResponse {
	success: boolean;
	html: string;
	pagination: { html: string } | null;
	counter: string | null;
	navigation: { html: string } | null;
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
