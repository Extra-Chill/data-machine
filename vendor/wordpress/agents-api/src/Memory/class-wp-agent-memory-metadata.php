<?php
/**
 * Agent Memory Metadata
 *
 * Store-neutral provenance and trust metadata for an agent memory record.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\Core\FilesRepository;

use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Memory_Metadata {

	public const SOURCE_USER_ASSERTED       = 'user_asserted';
	public const SOURCE_AGENT_INFERRED      = 'agent_inferred';
	public const SOURCE_WORKSPACE_EXTRACTED = 'workspace_extracted';
	public const SOURCE_SYSTEM_GENERATED    = 'system_generated';
	public const SOURCE_CURATED             = 'curated';
	public const SOURCE_IMPORTED            = 'imported';

	public const AUTHORITY_LOW       = 'low';
	public const AUTHORITY_MEDIUM    = 'medium';
	public const AUTHORITY_HIGH      = 'high';
	public const AUTHORITY_CANONICAL = 'canonical';

	public const FIELDS = array(
		'source_type',
		'source_ref',
		'created_by_user_id',
		'created_by_agent_id',
		'workspace',
		'confidence',
		'validator',
		'authority_tier',
		'created_at',
		'updated_at',
	);

	/**
	 * @param string|null $source_type         Origin class for the memory.
	 * @param string|null $source_ref          Store-specific source reference, URL, path, ID, or content hash.
	 * @param int|null    $created_by_user_id  User who asserted or caused this memory to be written.
	 * @param int|null    $created_by_agent_id Agent identity that inferred or wrote this memory.
	 * @param WP_Agent_Workspace_Scope|null $workspace Workspace scope for revalidation.
	 * @param float|null  $confidence          Trust score from 0.0 to 1.0.
	 * @param string|null $validator           Validator identifier that can re-check this memory.
	 * @param string|null $authority_tier      Ranking authority, independent from confidence.
	 * @param int|null    $created_at          Unix timestamp when the memory was created.
	 * @param int|null    $updated_at          Unix timestamp when the metadata/content was last updated.
	 */
	public function __construct(
		public readonly ?string $source_type = null,
		public readonly ?string $source_ref = null,
		public readonly ?int $created_by_user_id = null,
		public readonly ?int $created_by_agent_id = null,
		public readonly ?WP_Agent_Workspace_Scope $workspace = null,
		public readonly ?float $confidence = null,
		public readonly ?string $validator = null,
		public readonly ?string $authority_tier = null,
		public readonly ?int $created_at = null,
		public readonly ?int $updated_at = null,
	) {}

	/**
	 * Build metadata from a JSON-friendly array.
	 *
	 * @param array<string,mixed> $metadata Metadata values.
	 * @return self
	 */
	public static function from_array( array $metadata ): self {
		$workspace = null;
		if ( isset( $metadata['workspace'] ) && is_array( $metadata['workspace'] ) ) {
			$workspace = WP_Agent_Workspace_Scope::from_array( $metadata['workspace'] );
		}

		return new self(
			self::optional_string( $metadata['source_type'] ?? null ),
			self::optional_string( $metadata['source_ref'] ?? null ),
			self::optional_int( $metadata['created_by_user_id'] ?? null ),
			self::optional_int( $metadata['created_by_agent_id'] ?? null ),
			$workspace,
			self::optional_confidence( $metadata['confidence'] ?? null ),
			self::optional_string( $metadata['validator'] ?? null ),
			self::optional_string( $metadata['authority_tier'] ?? null ),
			self::optional_int( $metadata['created_at'] ?? null ),
			self::optional_int( $metadata['updated_at'] ?? null ),
		);
	}

	/**
	 * Apply source-type trust defaults without overwriting explicit values.
	 *
	 * @param int|null $timestamp Timestamp to use for missing created/updated values.
	 * @return self
	 */
	public function with_defaults( ?int $timestamp = null ): self {
		$timestamp      = $timestamp ?? time();
		$source_type    = $this->source_type ?? self::SOURCE_AGENT_INFERRED;
		$confidence     = $this->confidence;
		$authority_tier = $this->authority_tier;

		if ( null === $confidence ) {
			$confidence = match ( $source_type ) {
				self::SOURCE_USER_ASSERTED       => 0.9,
				self::SOURCE_CURATED,
				self::SOURCE_SYSTEM_GENERATED    => 1.0,
				self::SOURCE_WORKSPACE_EXTRACTED => 0.8,
				self::SOURCE_IMPORTED            => 0.7,
				default                          => 0.5,
			};
		}

		if ( null === $authority_tier ) {
			$authority_tier = match ( $source_type ) {
				self::SOURCE_CURATED,
				self::SOURCE_SYSTEM_GENERATED => self::AUTHORITY_CANONICAL,
				self::SOURCE_USER_ASSERTED    => self::AUTHORITY_HIGH,
				self::SOURCE_IMPORTED         => self::AUTHORITY_MEDIUM,
				default                       => self::AUTHORITY_LOW,
			};
		}

		return new self(
			$source_type,
			$this->source_ref,
			$this->created_by_user_id,
			$this->created_by_agent_id,
			$this->workspace,
			self::normalize_confidence( $confidence ),
			$this->validator,
			$authority_tier,
			$this->created_at ?? $timestamp,
			$this->updated_at ?? $timestamp,
		);
	}

	/**
	 * Convert to a JSON-friendly associative array.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		$metadata = array(
			'source_type'         => $this->source_type,
			'source_ref'          => $this->source_ref,
			'created_by_user_id'  => $this->created_by_user_id,
			'created_by_agent_id' => $this->created_by_agent_id,
			'workspace'           => $this->workspace?->to_array(),
			'confidence'          => $this->confidence,
			'validator'           => $this->validator,
			'authority_tier'      => $this->authority_tier,
			'created_at'          => $this->created_at,
			'updated_at'          => $this->updated_at,
		);

		return array_filter(
			$metadata,
			static fn ( $value ): bool => null !== $value
		);
	}

	/**
	 * Return a copy containing only the requested metadata fields.
	 *
	 * @param string[] $fields Fields to keep.
	 * @return self
	 */
	public function only_fields( array $fields ): self {
		return self::from_array( array_intersect_key( $this->to_array(), array_flip( $fields ) ) );
	}

	private static function normalize_confidence( float $confidence ): float {
		return max( 0.0, min( 1.0, $confidence ) );
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function optional_string( $value ): ?string {
		return is_scalar( $value ) ? (string) $value : null;
	}

	/**
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private static function optional_int( $value ): ?int {
		return is_scalar( $value ) ? (int) $value : null;
	}

	/**
	 * @param mixed $value Raw value.
	 * @return float|null
	 */
	private static function optional_confidence( $value ): ?float {
		return is_scalar( $value ) ? self::normalize_confidence( (float) $value ) : null;
	}
}
