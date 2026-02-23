/**
 * Caption input component with platform-aware character counting
 */

import { __ } from '@wordpress/i18n';
import { TextareaControl } from '@wordpress/components';
import { useMemo } from '@wordpress/element';
import { getCombinedConstraints } from '../utils/PlatformRegistry';

interface CaptionInputProps {
	value: string;
	onChange: (value: string) => void;
	selectedPlatforms: string[];
	disabled?: boolean;
}

export function CaptionInput({ value, onChange, selectedPlatforms, disabled }: CaptionInputProps) {
	const constraints = useMemo(() => {
		if (selectedPlatforms.length === 0) return null;
		return getCombinedConstraints(selectedPlatforms);
	}, [selectedPlatforms]);

	const charCount = value?.length || 0;
	const charLimit = constraints?.charLimit || 0;
	const isOverLimit = charCount > charLimit;
	const remaining = charLimit - charCount;

	const getCounterClass = () => {
		if (charCount === 0) return 'dms-char-counter';
		if (isOverLimit) return 'dms-char-counter is-over-limit';
		if (remaining <= 20) return 'dms-char-counter is-warning';
		return 'dms-char-counter';
	};

	return (
		<div className="dms-caption-input">
			<h4>{__('Caption', 'data-machine-socials')}</h4>
			
			{constraints && (
				<div className={getCounterClass()}>
					{isOverLimit ? (
						<span className="dms-char-over">
							{__('Over limit by', 'data-machine-socials')} {charCount - charLimit}
						</span>
					) : (
						<span className="dms-char-remaining">
							{remaining.toLocaleString()} {__('characters remaining', 'data-machine-socials')}
						</span>
					)}
					<span className="dms-char-total">
						{charCount.toLocaleString()} / {charLimit.toLocaleString()}
					</span>
				</div>
			)}

			<TextareaControl
				value={value}
				onChange={onChange}
				placeholder={__('Write your caption...', 'data-machine-socials')}
				disabled={disabled}
				rows={6}
				className={isOverLimit ? 'is-error' : ''}
			/>

			{isOverLimit && (
				<div className="dms-caption-error">
					{__('Caption exceeds character limit for selected platforms', 'data-machine-socials')}
				</div>
			)}
		</div>
	);
}
