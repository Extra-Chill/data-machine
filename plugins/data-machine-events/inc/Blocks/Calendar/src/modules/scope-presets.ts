/**
 * In-block time-scope preset chips (#373).
 *
 * Surfaces the calendar block's existing `scope` round-trip (ScopeResolver
 * -> query -> render -> `data-scope`) as a generic, opt-in filter-bar
 * control. The chips are IN-BLOCK FILTERS: clicking one sets the active
 * scope and triggers the SAME filter-change flow the search/date controls
 * use (buildParams -> re-fetch). They are deliberately NOT links to other
 * pages — any SEO-landing-page behavior is a consumer concern that lives
 * outside this generic layer.
 *
 * The chip group is only present in the DOM when the `showScopePresets`
 * block attribute is enabled, so this module is a no-op otherwise.
 */

const CHIP_SELECTOR = '.data-machine-events-scope-chip';
const ACTIVE_CLASS = 'data-machine-events-scope-chip-active';

/**
 * Wire scope-preset chip clicks to the shared filter-change flow.
 *
 * @param calendar       The calendar root element.
 * @param onFilterChange The same callback the search/date controls fire;
 *                       it rebuilds params (including the now-active
 *                       scope) and re-fetches via the existing path.
 */
export function initScopePresets(
	calendar: HTMLElement,
	onFilterChange: () => void
): void {
	const group = calendar.querySelector< HTMLElement >(
		'.data-machine-events-scope-presets'
	);
	if ( ! group ) {
		return;
	}

	group.addEventListener( 'click', function ( e: Event ) {
		const target = e.target as HTMLElement;
		const chip = target.closest< HTMLButtonElement >( CHIP_SELECTOR );
		if ( ! chip || ! group.contains( chip ) ) {
			return;
		}

		e.preventDefault();

		// No-op when the user clicks the already-active chip.
		if ( chip.classList.contains( ACTIVE_CLASS ) ) {
			return;
		}

		setActiveChip( group, chip );
		onFilterChange();
	} );
}

/**
 * Mark a single chip active and reset the rest, keeping `aria-pressed` in
 * sync for assistive tech.
 * @param group
 * @param activeChip
 */
function setActiveChip(
	group: HTMLElement,
	activeChip: HTMLButtonElement
): void {
	const chips = group.querySelectorAll< HTMLButtonElement >( CHIP_SELECTOR );
	chips.forEach( ( chip ) => {
		const isActive = chip === activeChip;
		chip.classList.toggle( ACTIVE_CLASS, isActive );
		chip.setAttribute( 'aria-pressed', isActive ? 'true' : 'false' );
	} );
}
