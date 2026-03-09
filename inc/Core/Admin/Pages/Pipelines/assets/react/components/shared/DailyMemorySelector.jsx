/**
 * Daily Memory Selector Component
 *
 * UI for selecting which daily memory files to include in AI context.
 * Supports multiple selection modes: recent days, specific dates,
 * date ranges, and entire months.
 *
 * @since 0.40.0
 * @see https://github.com/Extra-Chill/data-machine/issues/761
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import {
	RadioControl,
	NumberControl,
	DatePicker,
	CheckboxControl,
	Button,
	Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { useAgentFiles } from '../../queries/pipelines';

/**
 * Default daily memory config.
 */
const DEFAULT_CONFIG = {
	mode: 'none',
	days: 7,
	dates: [],
	from: null,
	to: null,
	months: [],
};

/**
 * Format a date string for display.
 *
 * @param {string} dateStr Date in YYYY-MM-DD format.
 * @return {string} Human-readable date.
 */
const formatDateLabel = ( dateStr ) => {
	try {
		const date = new Date( dateStr + 'T00:00:00' );
		return date.toLocaleDateString( undefined, {
			weekday: 'short',
			month: 'short',
			day: 'numeric',
		} );
	} catch {
		return dateStr;
	}
};

/**
 * Format a month key (YYYY/MM) for display.
 *
 * @param {string} monthKey Month key like '2026/03'.
 * @return {string} Human-readable month like 'March 2026'.
 */
const formatMonthLabel = ( monthKey ) => {
	const [ year, month ] = monthKey.split( '/' );
	const date = new Date( parseInt( year, 10 ), parseInt( month, 10 ) - 1 );
	return date.toLocaleDateString( undefined, {
		year: 'numeric',
		month: 'long',
	} );
};

/**
 * Get today's date in YYYY-MM-DD format.
 *
 * @return {string} Today's date.
 */
const getTodayDate = () => {
	const now = new Date();
	return now.toISOString().split( 'T' )[ 0 ];
};

/**
 * Daily Memory Selector Component
 *
 * @param {Object}   props               - Component props
 * @param {Object}   props.config        - Current daily memory config
 * @param {Function} props.onChange      - Callback when config changes
 * @param {boolean}  props.disabled      - Whether the selector is disabled
 * @return {React.ReactElement} Daily memory selector UI
 */
export default function DailyMemorySelector( { config, onChange, disabled = false } ) {
	const { data: agentFiles = [], isLoading } = useAgentFiles();

	// Extract daily memory summary from agent files.
	const dailySummary = useMemo( () => {
		return agentFiles.find( ( f ) => f.type === 'daily_summary' );
	}, [ agentFiles ] );

	// Available months from the daily memory files.
	const availableMonths = useMemo( () => {
		if ( ! dailySummary?.months ) {
			return [];
		}
		return Object.keys( dailySummary.months );
	}, [ dailySummary ] );

	// Available dates grouped by month.
	const availableDatesByMonth = useMemo( () => {
		if ( ! dailySummary?.months ) {
			return {};
		}
		const result = {};
		for ( const [ monthKey, days ] of Object.entries( dailySummary.months ) ) {
			const [ year, month ] = monthKey.split( '/' );
			result[ monthKey ] = days.map( ( day ) => `${ year }-${ month }-${ day }` );
		}
		return result;
	}, [ dailySummary ] );

	// Local state for the config.
	const [ localConfig, setLocalConfig ] = useState( config || DEFAULT_CONFIG );

	// Sync local state when prop changes.
	useEffect( () => {
		setLocalConfig( config || DEFAULT_CONFIG );
	}, [ config ] );

	// Update a single field in the config.
	const updateConfig = useCallback(
		( key, value ) => {
			const newConfig = { ...localConfig, [ key ]: value };
			setLocalConfig( newConfig );
			onChange( newConfig );
		},
		[ localConfig, onChange ]
	);

	// Handle mode change - reset mode-specific fields.
	const handleModeChange = useCallback(
		( newMode ) => {
			const newConfig = {
				mode: newMode,
				days: newMode === 'recent_days' ? 7 : undefined,
				dates: newMode === 'specific_dates' ? [] : undefined,
				from: newMode === 'date_range' ? null : undefined,
				to: newMode === 'date_range' ? null : undefined,
				months: newMode === 'months' ? [] : undefined,
			};
			setLocalConfig( newConfig );
			onChange( newConfig );
		},
		[ onChange ]
	);

	// Toggle a specific date in the dates array.
	const toggleDate = useCallback(
		( date ) => {
			const currentDates = localConfig.dates || [];
			const newDates = currentDates.includes( date )
				? currentDates.filter( ( d ) => d !== date )
				: [ ...currentDates, date ];
			updateConfig( 'dates', newDates );
		},
		[ localConfig.dates, updateConfig ]
	);

	// Toggle a month in the months array.
	const toggleMonth = useCallback(
		( monthKey ) => {
			const currentMonths = localConfig.months || [];
			const newMonths = currentMonths.includes( monthKey )
				? currentMonths.filter( ( m ) => m !== monthKey )
				: [ ...currentMonths, monthKey ];
			updateConfig( 'months', newMonths );
		},
		[ localConfig.months, updateConfig ]
	);

	// Select all dates in a month.
	const selectAllInMonth = useCallback(
		( monthKey ) => {
			const monthDates = availableDatesByMonth[ monthKey ] || [];
			const currentDates = localConfig.dates || [];
			const allSelected = monthDates.every( ( d ) =>
				currentDates.includes( d )
			);

			let newDates;
			if ( allSelected ) {
				// Deselect all in this month.
				newDates = currentDates.filter(
					( d ) => ! monthDates.includes( d )
				);
			} else {
				// Select all in this month (merge with existing).
				newDates = [ ...new Set( [ ...currentDates, ...monthDates ] ) ];
			}
			updateConfig( 'dates', newDates );
		},
		[ availableDatesByMonth, localConfig.dates, updateConfig ]
	);

	if ( isLoading ) {
		return (
			<div
				style={ {
					textAlign: 'center',
					padding: '20px',
					color: '#757575',
				} }
			>
				<Spinner />
			</div>
		);
	}

	// No daily memory files available.
	if ( ! dailySummary || ! availableMonths.length ) {
		return (
			<div
				style={ {
					color: '#757575',
					fontStyle: 'italic',
					padding: '12px 0',
				} }
			>
				{ __(
					'No daily memory files available. Daily memory will appear here once generated.',
					'data-machine'
				) }
			</div>
		);
	}

	const modeOptions = [
		{ label: __( 'None', 'data-machine' ), value: 'none' },
		{
			label: __( 'Recent days', 'data-machine' ),
			value: 'recent_days',
		},
		{
			label: __( 'Specific dates', 'data-machine' ),
			value: 'specific_dates',
		},
		{
			label: __( 'Date range', 'data-machine' ),
			value: 'date_range',
		},
		{
			label: __( 'Entire months', 'data-machine' ),
			value: 'months',
		},
	];

	return (
		<div className="datamachine-daily-memory-selector">
			<h4
				style={ {
					margin: '0 0 12px 0',
					fontSize: '14px',
					fontWeight: '600',
				} }
			>
				{ __( 'Daily Memory', 'data-machine' ) }
			</h4>
			<p
				style={ {
					margin: '0 0 12px 0',
					color: '#757575',
					fontSize: '13px',
				} }
			>
				{ __(
					'Select which daily memory files to include in AI context.',
					'data-machine'
				) }
			</p>

			<RadioControl
				selected={ localConfig.mode || 'none' }
				options={ modeOptions }
				onChange={ handleModeChange }
				disabled={ disabled }
			/>

			{/* Recent Days Mode */}
			{ localConfig.mode === 'recent_days' && (
				<div
					style={ {
						marginTop: '12px',
						padding: '12px',
						background: '#f9f9f9',
						borderRadius: '4px',
					} }
				>
					<NumberControl
						label={ __( 'Number of days', 'data-machine' ) }
						value={ localConfig.days || 7 }
						onChange={ ( value ) =>
							updateConfig(
								'days',
								Math.max( 1, Math.min( 90, value || 7 ) )
							)
						}
						min={ 1 }
						max={ 90 }
						disabled={ disabled }
					/>
					<p
						className="description"
						style={ { marginTop: '8px' } }
					>
						{ __(
							'Include daily memory from the past N days (max 90).',
							'data-machine'
						) }
					</p>
				</div>
			) }

			{/* Specific Dates Mode */}
			{ localConfig.mode === 'specific_dates' && (
				<div
					style={ {
						marginTop: '12px',
						padding: '12px',
						background: '#f9f9f9',
						borderRadius: '4px',
						maxHeight: '300px',
						overflowY: 'auto',
					} }
				>
					<p
						style={ {
							margin: '0 0 8px 0',
							fontSize: '13px',
							fontWeight: '500',
						} }
					>
						{ __( 'Select specific dates:', 'data-machine' ) }
					</p>
					{ availableMonths.map( ( monthKey ) => {
						const monthDates =
							availableDatesByMonth[ monthKey ] || [];
						const allSelected = monthDates.every( ( d ) =>
							( localConfig.dates || [] ).includes( d )
						);
						const someSelected = monthDates.some( ( d ) =>
							( localConfig.dates || [] ).includes( d )
						);

						return (
							<div
								key={ monthKey }
								style={ { marginBottom: '12px' } }
							>
								<div
									style={ {
										display: 'flex',
										alignItems: 'center',
										gap: '8px',
										marginBottom: '4px',
									} }
								>
									<CheckboxControl
										checked={ allSelected }
										onChange={ () =>
											selectAllInMonth( monthKey )
										}
										disabled={ disabled }
										__nextHasNoMarginBottom
									/>
									<strong>{ formatMonthLabel( monthKey ) }</strong>
									<span
										style={ {
											color: '#757575',
											fontSize: '12px',
										} }
									>
										({ monthDates.length } days)
									</span>
								</div>
								<div
									style={ {
										display: 'flex',
										flexWrap: 'wrap',
										gap: '4px',
										marginLeft: '24px',
									} }
								>
									{ monthDates
										.sort()
										.reverse()
										.map( ( date ) => (
											<CheckboxControl
												key={ date }
												label={ formatDateLabel( date ) }
												checked={ (
													localConfig.dates || []
												).includes( date ) }
												onChange={ () =>
													toggleDate( date )
												}
												disabled={ disabled }
												__nextHasNoMarginBottom
											/>
										) ) }
								</div>
							</div>
						);
					} ) }
				</div>
			) }

			{/* Date Range Mode */}
			{ localConfig.mode === 'date_range' && (
				<div
					style={ {
						marginTop: '12px',
						padding: '12px',
						background: '#f9f9f9',
						borderRadius: '4px',
					} }
				>
					<div
						style={ {
							display: 'flex',
							gap: '16px',
							flexWrap: 'wrap',
						} }
					>
						<div>
							<label
								style={ {
									display: 'block',
									marginBottom: '4px',
									fontSize: '13px',
									fontWeight: '500',
								} }
							>
								{ __( 'From', 'data-machine' ) }
							</label>
							<input
								type="date"
								value={ localConfig.from || '' }
								onChange={ ( e ) =>
									updateConfig( 'from', e.target.value || null )
								}
								disabled={ disabled }
								max={ localConfig.to || getTodayDate() }
							/>
						</div>
						<div>
							<label
								style={ {
									display: 'block',
									marginBottom: '4px',
									fontSize: '13px',
									fontWeight: '500',
								} }
							>
								{ __( 'To', 'data-machine' ) }
							</label>
							<input
								type="date"
								value={ localConfig.to || '' }
								onChange={ ( e ) =>
									updateConfig( 'to', e.target.value || null )
								}
								disabled={ disabled }
								min={ localConfig.from || undefined }
								max={ getTodayDate() }
							/>
						</div>
					</div>
					<p
						className="description"
						style={ { marginTop: '8px' } }
					>
						{ __(
							'Include all daily memory files within the date range.',
							'data-machine'
						) }
					</p>
				</div>
			) }

			{/* Months Mode */}
			{ localConfig.mode === 'months' && (
				<div
					style={ {
						marginTop: '12px',
						padding: '12px',
						background: '#f9f9f9',
						borderRadius: '4px',
					} }
				>
					<p
						style={ {
							margin: '0 0 8px 0',
							fontSize: '13px',
							fontWeight: '500',
						} }
					>
						{ __( 'Select months:', 'data-machine' ) }
					</p>
					<div
						style={ {
							display: 'flex',
							flexDirection: 'column',
							gap: '8px',
						} }
					>
						{ availableMonths.map( ( monthKey ) => {
							const dayCount =
								dailySummary.months[ monthKey ]?.length || 0;
							return (
								<CheckboxControl
									key={ monthKey }
									label={ `${ formatMonthLabel( monthKey) } (${ dayCount } days)` }
									checked={ (
										localConfig.months || []
									).includes( monthKey ) }
									onChange={ () => toggleMonth( monthKey ) }
									disabled={ disabled }
									__nextHasNoMarginBottom
								/>
							);
						} ) }
					</div>
				</div>
			) }

			{/* Summary */}
			{ localConfig.mode !== 'none' && (
				<div
					style={ {
						marginTop: '12px',
						padding: '8px 12px',
						background: '#e7f3ff',
						borderRadius: '4px',
						fontSize: '13px',
					} }
				>
					<strong>{ __( 'Selection:', 'data-machine' ) }</strong>{ ' ' }
					{ localConfig.mode === 'recent_days' &&
						`Past ${ localConfig.days || 7 } days` }
					{ localConfig.mode === 'specific_dates' &&
						`${ ( localConfig.dates || [] ).length } date(s) selected` }
					{ localConfig.mode === 'date_range' &&
						( localConfig.from || localConfig.to
							? `${ localConfig.from || '...' } to ${ localConfig.to || '...' }`
							: 'No range set' ) }
					{ localConfig.mode === 'months' &&
						`${ ( localConfig.months || [] ).length } month(s) selected` }
				</div>
			) }
		</div>
	);
}
