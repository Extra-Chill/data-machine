<?php
/**
 * Image validation utilities for Data Machine.
 *
 * Thin subclass of MediaValidator for image-specific MIME types.
 * Validates images from repository files (not URLs) to ensure they can be
 * processed by publish handlers.
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.2.1
 */

namespace DataMachine\Core\FilesRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImageValidator extends MediaValidator {

	/**
	 * Supported image MIME types.
	 *
	 * @return array<string>
	 */
	protected function supported_mime_types(): array {
		return array(
			'image/jpeg',
			'image/png',
			'image/gif',
			'image/webp',
		);
	}

	/**
	 * Human-readable label for error messages.
	 *
	 * @return string
	 */
	protected function media_label(): string {
		return 'Image';
	}
}
