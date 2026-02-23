/**
 * Social Editor - Main component for cross-platform social posting
 */

import { __ } from '@wordpress/i18n';
import { Button, PanelBody, Notice } from '@wordpress/components';
import { useState, useMemo, useCallback } from '@wordpress/element';
import { PlatformSelector } from './PlatformSelector';
import { CaptionInput } from './CaptionInput';
import { ImageSelector } from './ImageSelector';
import { ImageCropper } from './ImageCropper';
import { usePlatformAuth } from '../hooks/usePlatformAuth';
import { useCrossPost } from '../hooks/useCrossPost';
import { getCombinedConstraints } from '../utils/PlatformRegistry';
import { SelectedImage } from '../types';
import { uploadCroppedImage } from '../utils/api';

interface SocialEditorProps {
	postId: number;
}

export function SocialEditor({ postId }: SocialEditorProps) {
	const [selectedPlatforms, setSelectedPlatforms] = useState<string[]>([]);
	const [selectedImages, setSelectedImages] = useState<SelectedImage[]>([]);
	const [caption, setCaption] = useState('');
	const [aspectRatio, setAspectRatio] = useState('4:5');
	const [showCropper, setShowCropper] = useState(false);
	
	const { authStatus, isLoading: isAuthLoading, isPlatformAuthenticated, refreshAuth } = usePlatformAuth();
	const { isPosting, results, errors, post, reset } = useCrossPost();

	const authenticatedPlatforms = useMemo(() => {
		return Object.entries(authStatus)
			.filter(([, status]) => status.isAuthenticated)
			.map(([slug]) => slug);
	}, [authStatus]);

	const constraints = useMemo(() => {
		if (selectedPlatforms.length === 0) return null;
		return getCombinedConstraints(selectedPlatforms);
	}, [selectedPlatforms]);

	const maxImages = constraints?.maxImages || 1;
	const supportsCarousel = constraints?.supportsCarousel || false;

	// Open cropper when images are selected (if cropping is needed)
	const handleImagesSelected = useCallback((images: SelectedImage[]) => {
		setSelectedImages(images);
		if (images.length > 0 && selectedPlatforms.length > 0) {
			// Check if any selected platform requires specific aspect ratio
			const needsCropping = selectedPlatforms.some(platform => {
				const config = constraints;
				return config?.aspectRatios && !config.aspectRatios.includes('any');
			});
			if (needsCropping) {
				setShowCropper(true);
			}
		}
	}, [selectedPlatforms, constraints]);

	const canPost = useMemo(() => {
		if (selectedPlatforms.length === 0) return false;
		if (selectedImages.length === 0) return false;
		if (!caption.trim()) return false;
		if (caption.length > (constraints?.charLimit || Infinity)) return false;
		return true;
	}, [selectedPlatforms, selectedImages, caption, constraints]);

	const handlePost = useCallback(async () => {
		if (!canPost) return;

		// Upload cropped images if needed
		const uploadedImages = await Promise.all(
			selectedImages.map(async (image) => {
				if (image.croppedBlob) {
					const result = await uploadCroppedImage(
						image.croppedBlob,
						`dms_${image.id}.jpg`
					);
					return { ...image, url: result.url };
				}
				return image;
			})
		);

		const success = await post({
			postId,
			platforms: selectedPlatforms,
			images: uploadedImages,
			caption: caption.trim(),
			aspectRatio,
		});

		if (success) {
			// Reset form
			setSelectedImages([]);
			setCaption('');
		}
	}, [canPost, post, postId, selectedPlatforms, selectedImages, caption, aspectRatio]);

	const handleReset = useCallback(() => {
		reset();
		setSelectedPlatforms([]);
		setSelectedImages([]);
		setCaption('');
	}, [reset]);

	if (isAuthLoading) {
		return (
			<div className="dms-social-editor is-loading">
				{__('Loading...', 'data-machine-socials')}
			</div>
		);
	}

	if (authenticatedPlatforms.length === 0) {
		return (
			<PanelBody>
				<Notice status="warning" isDismissible={false}>
					{__('No social media accounts connected.', 'data-machine-socials')}
				</Notice>
				<p>
					{__('Connect your social media accounts in the Data Machine Socials settings.', 'data-machine-socials')}
				</p>
			</PanelBody>
		);
	}

	return (
		<div className="dms-social-editor">
			{/* Results */}
			{results.length > 0 && (
				<div className="dms-post-results">
					{results.map((result) => (
						<Notice
							key={result.platform}
							status={result.status === 'completed' ? 'success' : 'warning'}
							isDismissible={false}
						>
							<strong>{result.platform}:</strong>{' '}
							{result.status === 'completed' 
								? __('Posted successfully', 'data-machine-socials')
								: result.error || __('Failed', 'data-machine-socials')
							}
							{result.permalink && (
								<a 
									href={result.permalink} 
									target="_blank" 
									rel="noopener noreferrer"
								>
									{__('View post', 'data-machine-socials')}
								</a>
							)}
						</Notice>
					))}
				</div>
			)}

			{/* Errors */}
			{errors.length > 0 && (
				<div className="dms-post-errors">
					{errors.map((error, index) => (
						<Notice key={index} status="error" isDismissible={false}>
							{error}
						</Notice>
					))}
				</div>
			)}

			{/* Platform Selector */}
			<PanelBody>
				<PlatformSelector
					selected={selectedPlatforms}
					onChange={setSelectedPlatforms}
					authenticated={authenticatedPlatforms}
				/>
			</PanelBody>

			{/* Image Selector */}
			{selectedPlatforms.length > 0 && (
				<PanelBody>
				<ImageSelector
					selectedImages={selectedImages}
					onChange={handleImagesSelected}
					maxImages={maxImages}
					disabled={isPosting}
					postId={postId}
				/>

				{showCropper && selectedImages.length > 0 && (
					<ImageCropper
						images={selectedImages}
						aspectRatio={aspectRatio}
						availableAspectRatios={constraints?.aspectRatios || ['any']}
						onAspectRatioChange={setAspectRatio}
						onImagesCropped={setSelectedImages}
						isOpen={showCropper}
						onClose={() => setShowCropper(false)}
					/>
				)}
				</PanelBody>
			)}

			{/* Caption Input */}
			{selectedPlatforms.length > 0 && (
				<PanelBody>
					<CaptionInput
						value={caption}
						onChange={setCaption}
						selectedPlatforms={selectedPlatforms}
						disabled={isPosting}
					/>
				</PanelBody>
			)}

			{/* Post Actions */}
			{selectedPlatforms.length > 0 && (
				<PanelBody>
					<div className="dms-post-actions">
						<Button
							isPrimary
							onClick={handlePost}
							disabled={!canPost || isPosting}
							isBusy={isPosting}
						>
							{isPosting 
								? __('Posting...', 'data-machine-socials')
								: __('Post to Selected Platforms', 'data-machine-socials')
							}
						</Button>

						{results.length > 0 && (
							<Button
								isSecondary
								onClick={handleReset}
								disabled={isPosting}
							>
								{__('Post Again', 'data-machine-socials')}
							</Button>
						)}
					</div>
				</PanelBody>
			)}
		</div>
	);
}
