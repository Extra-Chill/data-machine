/**
 * Data Flow Arrow Component
 *
 * Visual arrow between pipeline steps showing data flow direction.
 */

/**
 * Data Flow Arrow Component
 *
 * @return {React.ReactElement} Arrow SVG
 */
export default function DataFlowArrow() {
	return (
		<div className="datamachine-data-flow-arrow">
			<svg
				width="40"
				height="20"
				viewBox="0 0 40 20"
				fill="none"
				xmlns="http://www.w3.org/2000/svg"
				aria-hidden="true"
			>
				<path
					d="M0 10 L30 10 L25 5 M30 10 L25 15"
					stroke="#8c8f94"
					strokeWidth="2"
					strokeLinecap="round"
					strokeLinejoin="round"
				/>
			</svg>
		</div>
	);
}
