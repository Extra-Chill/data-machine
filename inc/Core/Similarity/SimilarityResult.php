<?php
/**
 * Value object representing the result of a similarity comparison.
 *
 * Immutable data carrier returned by SimilarityEngine methods.
 * Carries the match verdict, confidence score, and which strategy
 * produced the match — so callers can log, display, or act on it
 * without inspecting internals.
 *
 * @package DataMachine\Core\Similarity
 * @since   0.39.0
 */

namespace DataMachine\Core\Similarity;

defined( 'ABSPATH' ) || exit;

class SimilarityResult {

	/**
	 * Strategy constants.
	 */
	const STRATEGY_EXACT    = 'exact';
	const STRATEGY_PREFIX   = 'prefix';
	const STRATEGY_EDIT     = 'levenshtein';
	const STRATEGY_JACCARD  = 'jaccard';
	const STRATEGY_NONE     = 'none';

	/**
	 * Whether the comparison matched.
	 *
	 * @var bool
	 */
	public bool $match;

	/**
	 * Confidence score between 0.0 and 1.0.
	 *
	 * For exact/prefix matches this is 1.0.
	 * For Levenshtein it's (1 - distance/maxLen).
	 * For Jaccard it's the Jaccard coefficient.
	 *
	 * @var float
	 */
	public float $score;

	/**
	 * Which strategy produced the match (or 'none' if no match).
	 *
	 * @var string
	 */
	public string $strategy;

	/**
	 * Normalized form of the first input.
	 *
	 * @var string
	 */
	public string $normalized_a;

	/**
	 * Normalized form of the second input.
	 *
	 * @var string
	 */
	public string $normalized_b;

	/**
	 * @param bool   $match        Whether the comparison matched.
	 * @param float  $score        Confidence score (0.0–1.0).
	 * @param string $strategy     Strategy that matched (use class constants).
	 * @param string $normalized_a Normalized first input.
	 * @param string $normalized_b Normalized second input.
	 */
	public function __construct(
		bool $match,
		float $score,
		string $strategy,
		string $normalized_a = '',
		string $normalized_b = ''
	) {
		$this->match        = $match;
		$this->score        = $score;
		$this->strategy     = $strategy;
		$this->normalized_a = $normalized_a;
		$this->normalized_b = $normalized_b;
	}

	/**
	 * Convenience factory for a non-match.
	 *
	 * @param string $normalized_a Normalized first input.
	 * @param string $normalized_b Normalized second input.
	 * @return self
	 */
	public static function noMatch( string $normalized_a = '', string $normalized_b = '' ): self {
		return new self( false, 0.0, self::STRATEGY_NONE, $normalized_a, $normalized_b );
	}

	/**
	 * Convert to array for structured responses.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'match'        => $this->match,
			'score'        => $this->score,
			'strategy'     => $this->strategy,
			'normalized_a' => $this->normalized_a,
			'normalized_b' => $this->normalized_b,
		);
	}
}
