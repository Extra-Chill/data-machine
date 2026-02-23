/**
 * Image cropping utilities for social media posting
 */

export interface CropArea {
	x: number;
	y: number;
	width: number;
	height: number;
}

interface RotatedSize {
	width: number;
	height: number;
}

function createImage(url: string): Promise<HTMLImageElement> {
	return new Promise((resolve, reject) => {
		const image = new Image();
		image.addEventListener('load', () => resolve(image));
		image.addEventListener('error', (error) => reject(error));
		image.setAttribute('crossOrigin', 'anonymous');
		image.src = url;
	});
}

function rotateSize(width: number, height: number, rotation: number): RotatedSize {
	const rotRad = (rotation * Math.PI) / 180;
	return {
		width: Math.abs(Math.cos(rotRad) * width) + Math.abs(Math.sin(rotRad) * height),
		height: Math.abs(Math.sin(rotRad) * width) + Math.abs(Math.cos(rotRad) * height),
	};
}

export async function getCroppedImg(
	imageSrc: string,
	pixelCrop: CropArea,
	rotation: number = 0
): Promise<Blob | null> {
	const image = await createImage(imageSrc);
	const canvas = document.createElement('canvas');
	const ctx = canvas.getContext('2d');

	if (!ctx) {
		return null;
	}

	const radian = (rotation * Math.PI) / 180;
	const { width: bBoxWidth, height: bBoxHeight } = rotateSize(
		image.width,
		image.height,
		rotation
	);

	canvas.width = bBoxWidth;
	canvas.height = bBoxHeight;

	ctx.translate(bBoxWidth / 2, bBoxHeight / 2);
	ctx.rotate(radian);
	ctx.translate(-image.width / 2, -image.height / 2);
	ctx.drawImage(image, 0, 0);

	const data = ctx.getImageData(
		pixelCrop.x,
		pixelCrop.y,
		pixelCrop.width,
		pixelCrop.height
	);

	canvas.width = pixelCrop.width;
	canvas.height = pixelCrop.height;
	ctx.putImageData(data, 0, 0);

	return new Promise((resolve, reject) => {
		canvas.toBlob((file) => {
			if (file) {
				resolve(file);
			} else {
				reject(new Error('Canvas to Blob conversion failed'));
			}
		}, 'image/jpeg', 0.9);
	});
}

export function parseAspectRatio(ratio: string): number | null {
	if (ratio === 'any') return null;
	const [width, height] = ratio.split(':').map(Number);
	return width / height;
}

export function calculateCanvasDimensions(
	imgWidth: number,
	imgHeight: number,
	aspectRatio: number | null,
	maxWidth: number = 1200,
	maxHeight: number = 1200
): { width: number; height: number } {
	let width = imgWidth;
	let height = imgHeight;

	if (aspectRatio) {
		const currentRatio = imgWidth / imgHeight;
		if (currentRatio > aspectRatio) {
			width = imgHeight * aspectRatio;
		} else {
			height = imgWidth / aspectRatio;
		}
	}

	const scale = Math.min(maxWidth / width, maxHeight / height, 1);
	return {
		width: Math.round(width * scale),
		height: Math.round(height * scale),
	};
}

export function generateFilename(originalName: string, platform: string): string {
	const timestamp = Date.now();
	const cleanName = originalName.replace(/[^a-zA-Z0-9]/g, '_');
	return `dms_${platform}_${cleanName}_${timestamp}.jpg`;
}
