/**
 * Platform selector component
 * 
 * Allows selecting multiple platforms for cross-posting
 */

import { __, sprintf } from '@wordpress/i18n';
import { CheckboxControl } from '@wordpress/components';
import { getAllPlatforms, getCombinedConstraints } from '../utils/PlatformRegistry';
import { PlatformConfig } from '../types';

interface PlatformSelectorProps {
	selected: string[];
	onChange: (selected: string[]) => void;
	authenticated: string[];
}

export function PlatformSelector({ selected, onChange, authenticated }: PlatformSelectorProps) {
	const platforms = getAllPlatforms();
	
	const handleToggle = (slug: string) => {
		if (selected.includes(slug)) {
			onChange(selected.filter(s => s !== slug));
		} else {
			onChange([...selected, slug]);
		}
	};

	const constraints = selected.length > 0 ? getCombinedConstraints(selected) : null;

	return (
		<div className="dms-platform-selector">
			<h4>{__('Select Platforms', 'data-machine-socials')}</h4>
			
			<div className="dms-platform-list">
				{Object.entries(platforms).map(([slug, config]: [string, PlatformConfig]) => {
					const isSelected = selected.includes(slug);
					const isAuthenticated = authenticated.includes(slug);
					
					return (
						<div 
							key={slug}
							className={`dms-platform-item ${isSelected ? 'is-selected' : ''} ${!isAuthenticated ? 'not-authenticated' : ''}`}
						>
					<CheckboxControl
						label={config.label}
						checked={isSelected}
						onChange={() => handleToggle(slug)}
						disabled={!isAuthenticated}
					/>
					<div className="dms-platform-details">
								<span className="dms-platform-max">
									{config.maxImages > 1 
										? sprintf(__('Up to %d images', 'data-machine-socials'), config.maxImages)
										: __('1 image', 'data-machine-socials')
									}
								</span>
								<span className="dms-platform-limit">
									{config.charLimit.toLocaleString()} {__('chars', 'data-machine-socials')}
								</span>
								{!isAuthenticated && (
									<span className="dms-platform-not-authenticated">
										{__('Not connected', 'data-machine-socials')}
									</span>
								)}
							</div>
						</div>
					);
				})}
			</div>

			{constraints && (
				<div className="dms-constraints-notice">
					<h5>{__('Combined Constraints', 'data-machine-socials')}</h5>
					<ul>
						<li>
							{__('Max images:', 'data-machine-socials')} {constraints.maxImages}
						</li>
						<li>
							{__('Character limit:', 'data-machine-socials')} {constraints.charLimit.toLocaleString()}
						</li>
						{constraints.supportsCarousel && (
							<li>{__('Carousel supported', 'data-machine-socials')}</li>
						)}
					</ul>
				</div>
			)}
		</div>
	);
}
