/**
 * Pipeline Selector Component
 *
 * Typeahead selector backed by a server-side search query. Lets the admin page
 * scale to hundreds/thousands of pipelines without returning every pipeline to
 * the browser.
 *
 * The selected pipeline stays visible even when the current search filters it
 * out — we stash a pinned option (id + name) pulled from the selected-pipeline
 * cache so users never lose their current context while searching.
 */

/**
 * WordPress dependencies
 */
import { ComboboxControl } from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { usePipelineSearch, usePipeline } from '../../queries/pipelines';
import { useUIStore } from '../../stores/uiStore';
import { isSameId } from '../../utils/ids';

const SEARCH_DEBOUNCE_MS = 200;

/**
 * Pipeline combobox selector with server-side search.
 *
 * @return {React.ReactElement|null} Selector component or null if no pipelines
 */
export default function PipelineSelector() {
	const { selectedPipelineId, setSelectedPipelineId } = useUIStore();

	// Raw input text + debounced term sent to the server.
	const [ inputValue, setInputValue ] = useState( '' );
	const [ debouncedSearch, setDebouncedSearch ] = useState( '' );
	const debounceTimer = useRef( null );

	useEffect( () => {
		if ( debounceTimer.current ) {
			clearTimeout( debounceTimer.current );
		}
		debounceTimer.current = setTimeout( () => {
			setDebouncedSearch( inputValue );
		}, SEARCH_DEBOUNCE_MS );

		return () => {
			if ( debounceTimer.current ) {
				clearTimeout( debounceTimer.current );
			}
		};
	}, [ inputValue ] );

	const {
		data: searchData,
		isLoading: searchLoading,
	} = usePipelineSearch( { search: debouncedSearch } );

	const results = searchData?.pipelines ?? [];
	const total = searchData?.total ?? 0;

	// Keep the selected pipeline visible in the list even when the search
	// filters it out. Falls back to a single-pipeline fetch if the selection
	// isn't in the current results — cheap because the cache is shared.
	const selectedInResults = useMemo(
		() =>
			selectedPipelineId
				? results.find( ( p ) =>
						isSameId( p.pipeline_id, selectedPipelineId )
				  )
				: null,
		[ results, selectedPipelineId ]
	);

	const { data: fetchedSelectedPipeline } = usePipeline(
		selectedPipelineId && ! selectedInResults ? selectedPipelineId : null
	);

	// Build options: selected pipeline (pinned) + search results, de-duplicated.
	const options = useMemo( () => {
		const entries = [];
		const seen = new Set();

		const push = ( pipeline ) => {
			if ( ! pipeline ) {
				return;
			}
			const id = String( pipeline.pipeline_id );
			if ( seen.has( id ) ) {
				return;
			}
			seen.add( id );
			entries.push( {
				label:
					pipeline.pipeline_name ||
					__( 'Untitled Pipeline', 'data-machine' ),
				value: id,
			} );
		};

		// Pinned selection (if not already in results).
		if ( selectedPipelineId && ! selectedInResults ) {
			push( fetchedSelectedPipeline );
		}

		results.forEach( push );

		return entries;
	}, [
		selectedPipelineId,
		selectedInResults,
		fetchedSelectedPipeline,
		results,
	] );

	/**
	 * Nothing to render when the user has no pipelines at all. We intentionally
	 * keep the selector visible while a search is in-flight so the UI does not
	 * flicker between states.
	 */
	if ( ! selectedPipelineId && options.length === 0 && ! searchLoading ) {
		return null;
	}

	const currentValue = selectedPipelineId
		? String( selectedPipelineId )
		: options[ 0 ]?.value ?? '';

	const handleChange = ( value ) => {
		if ( ! value ) {
			return;
		}
		setSelectedPipelineId( value );
	};

	// When results are capped, surface a hint so users know to refine search.
	const showOverflowHint =
		total > results.length && options.length >= results.length;

	return (
		<div className="datamachine-pipeline-selector-wrapper datamachine-spacing--margin-bottom-20">
			<ComboboxControl
				label={ __( 'Select Pipeline', 'data-machine' ) }
				value={ currentValue }
				options={ options }
				onChange={ handleChange }
				onFilterValueChange={ setInputValue }
				className="datamachine-pipeline-selector"
				__experimentalRenderItem={ undefined }
				allowReset={ false }
			/>
			{ showOverflowHint && (
				<p className="datamachine-pipeline-selector__hint datamachine-color--text-muted">
					{ __(
						'Showing first %1$d of %2$d pipelines. Keep typing to narrow the list.',
						'data-machine'
					)
						.replace( '%1$d', results.length )
						.replace( '%2$d', total ) }
				</p>
			) }
		</div>
	);
}
