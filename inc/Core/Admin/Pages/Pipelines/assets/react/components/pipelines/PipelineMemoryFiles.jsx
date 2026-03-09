/**
 * Pipeline Memory Files Component
 *
 * Allows selecting agent memory files to include in pipeline AI context.
 * Delegates to the shared MemoryFilesSelector component.
 *
 * Note: Daily memory selection is flow-scoped, not pipeline-scoped.
 * Pipelines only select custom memory files.
 */

/**
 * Internal dependencies
 */
import MemoryFilesSelector from '../shared/MemoryFilesSelector';
import {
	usePipelineMemoryFiles,
	useUpdatePipelineMemoryFiles,
} from '../../queries/pipelines';

/**
 * Pipeline Memory Files Component
 *
 * @param {Object} props            - Component props
 * @param {number} props.pipelineId - Pipeline ID
 * @return {React.ReactElement} Memory files selector for pipeline scope
 */
export default function PipelineMemoryFiles( { pipelineId } ) {
	const { data: selectedFiles = [], isLoading } =
		usePipelineMemoryFiles( pipelineId );
	const updateMutation = useUpdatePipelineMemoryFiles( pipelineId );

	return (
		<MemoryFilesSelector
			scopeLabel="pipeline"
			selectedFiles={ selectedFiles }
			isLoading={ isLoading }
			updateMutation={ updateMutation }
			showDailyMemory={ false }
		/>
	);
}
