<?php
/**
 * Webhook payload fetch handler.
 *
 * Converts explicitly mapped webhook trigger payload fields into a DataPacket.
 *
 * @package DataMachine\Core\Steps\Fetch\Handlers\WebhookPayload
 */

namespace DataMachine\Core\Steps\Fetch\Handlers\WebhookPayload;

use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WebhookPayload extends FetchHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'webhook_payload' );

		self::registerHandler(
			'webhook_payload',
			'fetch',
			self::class,
			'Webhook Payload',
			'Convert selected webhook payload fields into a DataPacket',
			false,
			null,
			WebhookPayloadSettings::class,
			null
		);
	}

	/**
	 * Build one packet item from the stored webhook trigger payload.
	 *
	 * @param array            $config  Handler configuration.
	 * @param ExecutionContext $context Execution context.
	 * @return array Handler result.
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$trigger = $context->getEngine()->get( 'webhook_trigger', array() );
		$payload = is_array( $trigger ) && is_array( $trigger['payload'] ?? null )
			? $trigger['payload']
			: array();

		if ( empty( $payload ) ) {
			$context->log( 'info', 'Webhook payload fetch skipped: no webhook payload found' );
			return array();
		}

		try {
			$item = self::mapPayloadToItem( $payload, $config );
		} catch ( \UnexpectedValueException $e ) {
			if ( ! empty( $config['ignore_missing_paths'] ) ) {
				$context->log(
					'info',
					'Webhook payload fetch skipped: mapped payload field is missing',
					array( 'reason' => $e->getMessage() )
				);
				return array();
			}

			throw $e;
		}

		return empty( $item ) ? array() : array( 'items' => array( $item ) );
	}

	/**
	 * Map a webhook payload into one normalized fetch item.
	 *
	 * @param array $payload Webhook payload.
	 * @param array $config  Mapping configuration.
	 * @return array Normalized fetch item.
	 */
	public static function mapPayloadToItem( array $payload, array $config ): array {
		$title_path   = trim( (string) ( $config['title_path'] ?? '' ) );
		$content_path = trim( (string) ( $config['content_path'] ?? '' ) );

		if ( '' === $title_path && '' === $content_path ) {
			throw new \InvalidArgumentException( 'Webhook payload mapping requires title_path, content_path, or both.' );
		}

		$title   = '' === $title_path ? '' : self::stringifyValue( self::getRequiredPath( $payload, $title_path ) );
		$content = '' === $content_path ? '' : self::stringifyValue( self::getRequiredPath( $payload, $content_path ) );

		$metadata = array(
			'source_type' => sanitize_key( (string) ( $config['source_type'] ?? 'webhook_payload' ) ),
		);

		foreach ( self::normalizeMetadataMapping( $config['metadata'] ?? array() ) as $key => $path ) {
			$metadata[ $key ] = self::getRequiredPath( $payload, $path );
		}

		$template = trim( (string) ( $config['item_identifier_template'] ?? '' ) );
		if ( '' !== $template ) {
			$metadata['item_identifier'] = self::interpolateTemplate( $template, $payload );
		}

		return array(
			'title'    => $title,
			'content'  => $content,
			'metadata' => $metadata,
		);
	}

	/**
	 * Extract a nested payload value using dot-path notation.
	 *
	 * @param array  $payload Payload array.
	 * @param string $path    Dot path.
	 * @return mixed Extracted value.
	 */
	public static function getPath( array $payload, string $path ) {
		$path = trim( $path );
		if ( '' === $path ) {
			return null;
		}

		$value = $payload;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( '' === $segment || ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}
			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * Return a nested value or throw a clear missing-path exception.
	 *
	 * @param array  $payload Payload array.
	 * @param string $path    Dot path.
	 * @return mixed Extracted value.
	 */
	private static function getRequiredPath( array $payload, string $path ) {
		$value = self::getPath( $payload, $path );
		if ( null === $value ) {
			throw new \UnexpectedValueException( sprintf( 'Missing webhook payload path "%s".', $path ) );
		}

		return $value;
	}

	/**
	 * Interpolate `{path.to.value}` placeholders from the payload.
	 *
	 * @param string $template Identifier template.
	 * @param array  $payload  Payload array.
	 * @return string Interpolated value.
	 */
	private static function interpolateTemplate( string $template, array $payload ): string {
		return (string) preg_replace_callback(
			'/\{([^{}]+)\}/',
			static function ( array $matches ) use ( $payload ): string {
				return self::stringifyValue( self::getRequiredPath( $payload, trim( $matches[1] ) ) );
			},
			$template
		);
	}

	/**
	 * Normalize metadata mapping from an array or JSON object string.
	 *
	 * @param mixed $mapping Metadata mapping.
	 * @return array<string,string> Normalized mapping.
	 */
	private static function normalizeMetadataMapping( $mapping ): array {
		if ( is_string( $mapping ) ) {
			$decoded = json_decode( $mapping, true );
			$mapping = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $mapping ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $mapping as $key => $path ) {
			$key  = sanitize_key( (string) $key );
			$path = trim( (string) $path );
			if ( '' !== $key && '' !== $path ) {
				$normalized[ $key ] = $path;
			}
		}

		return $normalized;
	}

	/**
	 * Convert payload values into model-visible strings.
	 *
	 * @param mixed $value Payload value.
	 * @return string String value.
	 */
	private static function stringifyValue( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Get the display label for the webhook payload handler.
	 *
	 * @return string Handler label.
	 */
	public static function get_label(): string {
		return __( 'Webhook Payload', 'data-machine' );
	}
}
