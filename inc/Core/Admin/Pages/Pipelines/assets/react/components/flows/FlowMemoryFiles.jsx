/**
 * Flow Memory Files Component
 *
 * Allows selecting agent memory files to include in flow AI context.
 * Flow memory files are additive — they inject alongside pipeline memory files.
 * Delegates to the shared MemoryFilesSelector component.
 *
 * @since 0.40.0 Added daily memory support.
 */

/**
 * Internal dependencies
 */
import MemoryFilesSelector from '../shared/MemoryFilesSelector';
import {
	useFlowMemoryFiles,
	useUpdateFlowMemoryFiles,
} from '../../queries/flows';

/**
 * Flow Memory Files Component
 *
 * @param {Object} props        - Component props
 * @param {number} props.flowId - Flow ID
 * @return {React.ReactElement} Memory files selector for flow scope
 */
export default function FlowMemoryFiles( { flowId } ) {
	const { data, isLoading } = useFlowMemoryFiles( flowId );
	const updateMutation = useUpdateFlowMemoryFiles( flowId );

	// Extract memory files and daily memory from the query response.
	const selectedFiles = data?.memoryFiles || [];
	const dailyMemory = data?.dailyMemory || { mode: 'none' };

	return (
		<MemoryFilesSelector
			scopeLabel="flow"
			selectedFiles={ selectedFiles }
			dailyMemory={ dailyMemory }
			isLoading={ isLoading }
			updateMutation={ updateMutation }
			showDailyMemory={ true }
		/>
	);
}
