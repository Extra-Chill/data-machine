/**
 * Image Cropper Component
 *
 * Multi-image cropping with platform-specific aspect ratios
 * Ported from post-to-instagram with TypeScript improvements
 */

import { __, sprintf } from '@wordpress/i18n';
import { Button, Modal, SelectControl } from '@wordpress/components';
import { useState, useCallback, useMemo } from '@wordpress/element';
import Cropper from 'react-easy-crop';
import { getCroppedImg, parseAspectRatio } from '../utils/cropImage';
import { SelectedImage, CropData } from '../types';

interface ImageCropperProps {
	images: SelectedImage[];
	aspectRatio: string;
	availableAspectRatios: string[];
	onAspectRatioChange: (ratio: string) => void;
	onImagesCropped: (images: SelectedImage[]) => void;
	isOpen: boolean;
	onClose: () => void;
}

interface Point {
	x: number;
	y: number;
}

interface Area {
	x: number;
	y: number;
	width: number;
	height: number;
}

export function ImageCropper({
	images,
	aspectRatio,
	availableAspectRatios,
	onAspectRatioChange,
	onImagesCropped,
	isOpen,
	onClose,
}: ImageCropperProps) {
	const [currentIndex, setCurrentIndex] = useState(0);
	const [crop, setCrop] = useState<Point>({ x: 0, y: 0 });
	const [zoom, setZoom] = useState(1);
	const [rotation, setRotation] = useState(0);
	const [croppedAreaPixels, setCroppedAreaPixels] = useState<Area | null>(null);
	const [isProcessing, setIsProcessing] = useState(false);
	const [processedImages, setProcessedImages] = useState<SelectedImage[]>([]);

	const currentImage = images[currentIndex];
	const isLastImage = currentIndex === images.length - 1;

	const aspectRatioOptions = useMemo(() => {
		return availableAspectRatios.map((ratio) => ({
			label: ratio === 'any' ? __('Any ratio', 'data-machine-socials') : ratio,
			value: ratio,
		}));
	}, [availableAspectRatios]);

	const parsedAspect = useMemo(() => {
		return parseAspectRatio(aspectRatio);
	}, [aspectRatio]);

	const onCropComplete = useCallback(
		(_croppedArea: Area, croppedAreaPixels: Area) => {
			setCroppedAreaPixels(croppedAreaPixels);
		},
		[]
	);

	const handleNext = useCallback(async () => {
		if (!currentImage || !croppedAreaPixels) return;

		setIsProcessing(true);

		try {
			const croppedBlob = await getCroppedImg(
				currentImage.url,
				croppedAreaPixels,
				rotation
			);

			if (croppedBlob) {
				const processedImage: SelectedImage = {
					...currentImage,
					croppedBlob,
					cropData: {
						x: croppedAreaPixels.x,
						y: croppedAreaPixels.y,
						width: croppedAreaPixels.width,
						height: croppedAreaPixels.height,
					},
				};

				setProcessedImages((prev) => [...prev, processedImage]);

				if (isLastImage) {
					// All images processed
					onImagesCropped([...processedImages, processedImage]);
					onClose();
				} else {
					// Move to next image
					setCurrentIndex((prev) => prev + 1);
					setCrop({ x: 0, y: 0 });
					setZoom(1);
					setRotation(0);
				}
			}
		} catch (error) {
			console.error('Cropping failed:', error);
		} finally {
			setIsProcessing(false);
		}
	}, [
		currentImage,
		croppedAreaPixels,
		rotation,
		isLastImage,
		processedImages,
		onImagesCropped,
		onClose,
	]);

	const handleSkip = useCallback(() => {
		if (!currentImage) return;

		// Add image without cropping
		setProcessedImages((prev) => [...prev, currentImage]);

		if (isLastImage) {
			onImagesCropped([...processedImages, currentImage]);
			onClose();
		} else {
			setCurrentIndex((prev) => prev + 1);
			setCrop({ x: 0, y: 0 });
			setZoom(1);
			setRotation(0);
		}
	}, [currentImage, isLastImage, processedImages, onImagesCropped, onClose]);

	const handleCancel = useCallback(() => {
		onClose();
		setCurrentIndex(0);
		setProcessedImages([]);
		setCrop({ x: 0, y: 0 });
		setZoom(1);
		setRotation(0);
	}, [onClose]);

	if (!isOpen || !currentImage) {
		return null;
	}

	return (
		<Modal
			title={
				sprintf(
					__('Crop Image %d of %d', 'data-machine-socials'),
					currentIndex + 1,
					images.length
				)
			}
			onRequestClose={handleCancel}
			className="dms-crop-modal"
		>
			<div className="dms-crop-container">
				{aspectRatioOptions.length > 1 && (
					<div className="dms-aspect-ratio-selector">
						<SelectControl
							label={__('Aspect Ratio', 'data-machine-socials')}
							value={aspectRatio}
							options={aspectRatioOptions}
							onChange={onAspectRatioChange}
						/>
					</div>
				)}

				<div className="dms-cropper-wrapper">
					<Cropper
						image={currentImage.url}
						crop={crop}
						zoom={zoom}
						rotation={rotation}
						aspect={parsedAspect || undefined}
						onCropChange={setCrop}
						onCropComplete={onCropComplete}
						onZoomChange={setZoom}
					/>
				</div>

				<div className="dms-crop-controls">
					<div className="dms-zoom-control">
						<label>{__('Zoom', 'data-machine-socials')}</label>
						<input
							type="range"
							min={1}
							max={3}
							step={0.1}
							value={zoom}
							onChange={(e) => setZoom(parseFloat(e.target.value))}
						/>
					</div>

					<div className="dms-rotation-control">
						<Button
							isSecondary
							onClick={() => setRotation((r) => (r - 90) % 360)}
							disabled={isProcessing}
						>
							{__('Rotate Left', 'data-machine-socials')}
						</Button>
						<Button
							isSecondary
							onClick={() => setRotation((r) => (r + 90) % 360)}
							disabled={isProcessing}
						>
							{__('Rotate Right', 'data-machine-socials')}
						</Button>
					</div>
				</div>

				<div className="dms-crop-actions">
					<Button isSecondary onClick={handleSkip} disabled={isProcessing}>
						{__('Skip Cropping', 'data-machine-socials')}
					</Button>

					<Button isPrimary onClick={handleNext} isBusy={isProcessing}>
						{isLastImage
							? __('Done', 'data-machine-socials')
							: __('Next Image', 'data-machine-socials')}
					</Button>
				</div>
			</div>
		</Modal>
	);
}
