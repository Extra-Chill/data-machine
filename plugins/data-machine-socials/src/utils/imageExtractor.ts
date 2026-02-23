/**
 * Extract images from WordPress post content
 */

import { SelectedImage } from '../types';

interface WpImageBlock {
	clientId: string;
	name?: string;
	attributes?: {
		id?: number;
		url?: string;
		alt?: string;
	};
	innerBlocks?: WpImageBlock[];
}

export function getPostImageIds(): number[] {
	const ids: number[] = [];
	
	// Get from wp-image-* classes in content
	const content = document.querySelector('.block-editor-block-list__layout')?.innerHTML || '';
	const regex = /wp-image-(\d+)/g;
	let match;
	while ((match = regex.exec(content)) !== null) {
		ids.push(parseInt(match[1], 10));
	}

	// Remove duplicates
	return [...new Set(ids)];
}

export function extractImagesFromBlocks(blocks: WpImageBlock[]): SelectedImage[] {
	const images: SelectedImage[] = [];
	const seenIds = new Set<number>();

	function traverse(block: WpImageBlock) {
		if (block.name === 'core/image' || block.name === 'core/cover') {
			const id = block.attributes?.id;
			if (id && !seenIds.has(id)) {
				seenIds.add(id);
				images.push({
					id,
					url: block.attributes?.url || '',
					width: 0,
					height: 0,
					alt: block.attributes?.alt,
				});
			}
		}

		if (block.name === 'core/gallery' && block.innerBlocks) {
			block.innerBlocks.forEach(traverse);
		}

		if (block.innerBlocks) {
			block.innerBlocks.forEach(traverse);
		}
	}

	blocks.forEach(traverse);
	return images;
}

export function extractFeaturedImage(): SelectedImage | null {
	const featuredImage = (window as any)._dmsFeaturedImage;
	if (featuredImage?.id) {
		return {
			id: featuredImage.id,
			url: featuredImage.url,
			width: featuredImage.width || 0,
			height: featuredImage.height || 0,
			alt: featuredImage.alt,
		};
	}
	return null;
}
