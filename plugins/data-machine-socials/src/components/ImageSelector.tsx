/**
 * Image selector component
 * 
 * Displays images from post content for selection
 */

import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useState, useCallback, useEffect } from '@wordpress/element';
import { getPostImageIds, extractImagesFromBlocks, extractFeaturedImage } from '../utils/imageExtractor';
import { SelectedImage } from '../types';

interface ImageSelectorProps {
	selectedImages: SelectedImage[];
	onChange: (images: SelectedImage[]) => void;
	maxImages: number;
	disabled?: boolean;
	postId?: number;
}

export function ImageSelector({ selectedImages, onChange, maxImages, disabled, postId }: ImageSelectorProps) {
	const [availableImages, setAvailableImages] = useState<SelectedImage[]>([]);
	const [isLoading, setIsLoading] = useState(true);

	// Extract images from blocks on mount
	useEffect(() => {
		const loadImages = () => {
			const featured = extractFeaturedImage();
			const fromBlocks: SelectedImage[] = [];
			
			// Try to get from window.wp data
			const editor = (window as any).wp?.data?.select('core/block-editor');
			if (editor) {
				const blocks = editor.getBlocks();
				const extracted = extractImagesFromBlocks(blocks);
				fromBlocks.push(...extracted);
			}

			// Combine featured and blocks, deduplicate
			const allImages: SelectedImage[] = [];
			const seenIds = new Set<number>();

			if (featured && !seenIds.has(featured.id)) {
				allImages.push(featured);
				seenIds.add(featured.id);
			}

			fromBlocks.forEach(img => {
				if (!seenIds.has(img.id)) {
					allImages.push(img);
					seenIds.add(img.id);
				}
			});

			setAvailableImages(allImages);
			setIsLoading(false);
		};

		loadImages();
	}, [postId]);

	const toggleImage = useCallback((image: SelectedImage) => {
		const isSelected = selectedImages.some(img => img.id === image.id);
		
		if (isSelected) {
			onChange(selectedImages.filter(img => img.id !== image.id));
		} else if (selectedImages.length < maxImages) {
			onChange([...selectedImages, image]);
		}
	}, [selectedImages, onChange, maxImages]);

	const clearAll = useCallback(() => {
		onChange([]);
	}, [onChange]);

	if (isLoading) {
		return (
			<div className="dms-image-selector is-loading">
				{__('Loading images...', 'data-machine-socials')}
			</div>
		);
	}

	if (availableImages.length === 0) {
		return (
			<div className="dms-image-selector is-empty">
				<p>{__('No images found in post.', 'data-machine-socials')}</p>
				<p>{__('Add images to your post to share on social media.', 'data-machine-socials')}</p>
			</div>
		);
	}

	return (
		<div className="dms-image-selector">
			<div className="dms-image-selector-header">
				<h4>{__('Select Images', 'data-machine-socials')}</h4>
				<span className="dms-image-count">
					{selectedImages.length} / {maxImages} {__('selected', 'data-machine-socials')}
				</span>
			</div>

			<div className="dms-image-grid">
				{availableImages.map((image) => {
					const isSelected = selectedImages.some(img => img.id === image.id);
					const canSelect = selectedImages.length < maxImages || isSelected;

					return (
						<div
							key={image.id}
							className={`dms-image-item ${isSelected ? 'is-selected' : ''} ${!canSelect ? 'is-disabled' : ''}`}
							onClick={() => !disabled && canSelect && toggleImage(image)}
						>
							<img src={image.url} alt={image.alt || ''} />
							{isSelected && (
								<div className="dms-image-selected-indicator">
									✓
								</div>
							)}
						</div>
					);
				})}
			</div>

			{selectedImages.length > 0 && (
				<div className="dms-image-actions">
					<Button
						isSecondary
						onClick={clearAll}
						disabled={disabled}
					>
						{__('Clear selection', 'data-machine-socials')}
					</Button>
				</div>
			)}
		</div>
	);
}
