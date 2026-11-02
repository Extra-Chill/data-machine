<?php
/**
 * Event date context directive.
 *
 * Event-import AI steps read venue pages that often print dates without a
 * year ("Saturday October 31"). Without knowing today's date the model can
 * only defer those items. This directive gives event-upsert AI steps today's
 * date in the site timezone plus the same year-inference rule the
 * deterministic extractors use (BaseExtractor::inferDateFromMonthDay() and
 * WeeblyExtractor's weekday disambiguation), so AI and extractor paths
 * resolve year-less dates identically.
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplies today's date and the year-inference rule to event-upsert AI steps.
 */
final class EventDateContextDirective {

	/** Days a year-less date may sit in the past before it rolls to next year. Mirrors BaseExtractor::PAST_DATE_GRACE_DAYS. */
	public const PAST_DATE_GRACE_DAYS = 14;

	/**
	 * Register the directive with Data Machine for pipeline AI steps.
	 */
	public static function register(): void {
		add_filter(
			'datamachine_directives',
			static function ( $directives ) {
				$directives[] = array(
					'class'    => self::class,
					'priority' => 55,
					'modes'    => array( 'pipeline' ),
				);
				return $directives;
			}
		);
	}

	/**
	 * Directive outputs for the Data Machine directive system.
	 *
	 * @param string      $provider_name AI provider name.
	 * @param array       $tools         Tools available to this step, keyed by tool name.
	 * @param string|null $step_id       Pipeline step ID.
	 * @param array       $payload       Directive payload.
	 * @return array<int, array{type: string, content: string}>
	 */
	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Data Machine directive contract.
		if ( ! self::has_event_upsert_tool( $tools ) ) {
			return array();
		}

		return array(
			array(
				'type'    => 'system_text',
				'content' => self::content( current_datetime() ),
			),
		);
	}

	/**
	 * Whether the step can publish through the events upsert handler.
	 *
	 * @param array $tools Tools keyed by tool name.
	 */
	public static function has_event_upsert_tool( array $tools ): bool {
		foreach ( $tools as $name => $tool ) {
			if ( EVENT_UPSERT_HANDLER_SLUG === $name
				|| ( is_array( $tool ) && EVENT_UPSERT_HANDLER_SLUG === ( $tool['handler'] ?? null ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Directive text for a given "now" in the site timezone.
	 *
	 * @param \DateTimeImmutable $now Current time in the site timezone.
	 */
	public static function content( \DateTimeImmutable $now ): string {
		$today = $now->format( 'l, F j, Y' );
		$ymd   = $now->format( 'Y-m-d' );
		$year  = (int) $now->format( 'Y' );
		$tz    = $now->getTimezone()->getName();
		$grace = self::PAST_DATE_GRACE_DAYS;

		return implode(
			"\n",
			array(
				'## Event date context',
				"Today is {$today} ({$ymd}, {$tz}).",
				'',
				'Venue pages often print dates without a year. Resolve the year yourself; a missing year alone is never a reason to defer or reject an event.',
				"- No weekday printed: use {$year}. Only if that date is more than {$grace} days before today, use " . ( $year + 1 ) . '.',
				'- Weekday printed (e.g. "Saturday October 31"): use whichever of ' . $year . ' or ' . ( $year + 1 ) . ' puts that month and day on that weekday, applying the same past-date rule. If neither year matches, the date is ambiguous: defer the item.',
				'- A resolved date before today is a past event: do not create it.',
				'- Always send startDate as YYYY-MM-DD.',
			)
		);
	}
}
