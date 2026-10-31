/**
 * Time-gap separator renderer — TypeScript port of
 * `inc/Blocks/Calendar/templates/time-gap-separator.php`.
 *
 * Renders the "X days later" chip between date groups when
 * `grouping.gaps[ date ]` is set in the data envelope. Markup matches
 * the PHP template exactly so CSS rules apply uniformly across
 * server-rendered and client-appended separators.
 *
 *   <div class="data-machine-time-gap-separator">
 *     <div class="data-machine-gap-line"></div>
 *     <div class="data-machine-gap-text">
 *       <span class="data-machine-gap-indicator">• • •</span>
 *       <span class="data-machine-gap-label">{label}</span>
 *     </div>
 *     <div class="data-machine-gap-line"></div>
 *   </div>
 *
 * Server stores `gap_days` as "raw days between date buckets" — the
 * label subtracts one so adjacent-but-one days read as "1 day later",
 * matching the PHP `$gap_days - 1` calculation.
 */

/**
 * Render the time-gap separator chip.
 *
 * @param gapDays Raw gap-days value from `CalendarGrouping.gaps[ date ]`.
 *                Server only emits gaps `>= 2`.
 */
export function renderGapSeparator( gapDays: number ): HTMLElement {
	const node = document.createElement( 'div' );
	node.className = 'data-machine-time-gap-separator';

	const leadingLine = document.createElement( 'div' );
	leadingLine.className = 'data-machine-gap-line';
	node.appendChild( leadingLine );

	const text = document.createElement( 'div' );
	text.className = 'data-machine-gap-text';

	const indicator = document.createElement( 'span' );
	indicator.className = 'data-machine-gap-indicator';
	indicator.textContent = '• • •';
	text.appendChild( indicator );

	const label = document.createElement( 'span' );
	label.className = 'data-machine-gap-label';
	label.textContent = formatGapLabel( gapDays );
	text.appendChild( label );

	node.appendChild( text );

	const trailingLine = document.createElement( 'div' );
	trailingLine.className = 'data-machine-gap-line';
	node.appendChild( trailingLine );

	return node;
}

function formatGapLabel( gapDays: number ): string {
	// Mirror the PHP branch: gap_days == 2 → "1 day later" singular.
	if ( gapDays === 2 ) {
		return '1 day later';
	}
	return `${ gapDays - 1 } days later`;
}
