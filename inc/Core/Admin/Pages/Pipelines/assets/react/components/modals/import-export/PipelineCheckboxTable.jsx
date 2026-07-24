/**
 * Pipeline Checkbox Table Component
 *
 * Reusable table with checkbox selection for pipelines.
 */

/**
 * WordPress dependencies
 */
import { CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { isSameId, includesId, normalizeId } from '../../../utils/ids';

/**
 * Pipeline Checkbox Table Component
 *
 * @param {Object}   props                   - Component props
 * @param {Array}    props.pipelines         - All available pipelines
 * @param {Array}    props.selectedIds       - Currently selected pipeline IDs
 * @param {Function} props.onSelectionChange - Selection change handler
 * @return {React.ReactElement} Pipeline checkbox table
 */
export default function PipelineCheckboxTable( {
	pipelines,
	selectedIds,
	onSelectionChange,
} ) {
	/**
	 * Toggle individual pipeline selection
	 * @param pipelineId
	 */
	const togglePipeline = ( pipelineId ) => {
		const normalizedId = normalizeId( pipelineId );
		const newSelection = includesId( selectedIds, pipelineId )
			? selectedIds.filter( ( id ) => ! isSameId( id, pipelineId ) )
			: [ ...selectedIds, normalizedId ];

		onSelectionChange( newSelection );
	};

	/**
	 * Toggle all pipelines
	 */
	const toggleAll = () => {
		if ( selectedIds.length === pipelines.length ) {
			onSelectionChange( [] );
		} else {
			onSelectionChange(
				pipelines.map( ( p ) => normalizeId( p.pipeline_id ) )
			);
		}
	};

	/**
	 * Check if all pipelines are selected
	 */
	const allSelected =
		pipelines.length > 0 && selectedIds.length === pipelines.length;

	return (
		<div className="datamachine-pipeline-table-wrapper">
			<table className="datamachine-pipeline-table">
				<thead>
					<tr className="datamachine-pipeline-table-header">
						<th className="datamachine-table-col--40">
							<CheckboxControl
								checked={ allSelected }
								onChange={ toggleAll }
								__nextHasNoMarginBottom
							/>
						</th>
						<th>{ __( 'Pipeline Name', 'data-machine' ) }</th>
						<th className="datamachine-table-col--100">
							{ __( 'Steps', 'data-machine' ) }
						</th>
						<th className="datamachine-table-col--100">
							{ __( 'Flows', 'data-machine' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ pipelines.length === 0 && (
						<tr>
							<td
								colSpan="4"
								className="datamachine-pipeline-table-empty"
							>
								{ __(
									'No pipelines available',
									'data-machine'
								) }
							</td>
						</tr>
					) }

					{ pipelines.map( ( pipeline ) => {
						const stepCount = Object.keys(
							pipeline.pipeline_config || {}
						).length;
						// Prefer the lightweight flow_count field exposed by the
						// /pipelines list endpoint. Fall back to counting an
						// embedded flows array (include_flows=true responses)
						// for back-compat.
						const flowCount =
							pipeline.flow_count ?? pipeline.flows?.length ?? 0;
						const isSelected = includesId(
							selectedIds,
							pipeline.pipeline_id
						);

						const rowClass = isSelected
							? 'datamachine-pipeline-table-row datamachine-pipeline-table-row--selected'
							: 'datamachine-pipeline-table-row';

						return (
							<tr
								key={ pipeline.pipeline_id }
								className={ rowClass }
							>
								<td>
									<CheckboxControl
										checked={ isSelected }
										onChange={ () =>
											togglePipeline(
												pipeline.pipeline_id
											)
										}
										__nextHasNoMarginBottom
									/>
								</td>
								<td className="datamachine-pipeline-table-name">
									{ pipeline.pipeline_name }
								</td>
								<td className="datamachine-pipeline-table-meta">
									{ stepCount }
								</td>
								<td className="datamachine-pipeline-table-meta">
									{ flowCount }
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</div>
	);
}
