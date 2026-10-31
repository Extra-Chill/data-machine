<?php
/**
 * UpdateEventCommand synopsis + field-mapping regression tests.
 *
 * Regression coverage for #858: `wp data-machine-events update-event`
 * rejected every option except `--venue` because (1) the docblock declared
 * camelCase flags (`--startDate`), which WP-CLI's synopsis parser only
 * accepts in lowercase/kebab-case, and (2) most ability-supported fields
 * were never declared in the synopsis at all, so WP-CLI rejected them as
 * unknown assoc args.
 *
 * These tests assert:
 *  - every declared `[--flag=<value>]` token in the `__invoke()` docblock
 *    matches WP-CLI's actual synopsis token grammar (mirrors
 *    `WP_CLI\SynopsisParser::classify_token()`'s `$p_name` regex, which
 *    only accepts `[a-z-_0-9]+`);
 *  - the docblock declares exactly the fields the
 *    `data-machine-events/update-event` ability's input schema accepts,
 *    so the two cannot silently drift apart again;
 *  - `extractUpdateFields()` correctly maps kebab-case CLI flags onto the
 *    ability's camelCase field names, including JSON-decoding
 *    `--occurrence-dates`.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\EventUpdateAbilities;
use DataMachineEvents\Cli\UpdateEventCommand;
use ReflectionMethod;
use WP_UnitTestCase;

class UpdateEventCommandTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		// Ensure the ability's `wp_abilities_api_init` callback is hooked.
		// If another test already fired that action in this process, the
		// ability is already registered in the shared registry and this
		// is a no-op — matches the pattern EventUpdateAbilitiesTest relies on.
		new EventUpdateAbilities();
	}

	/**
	 * Extract every `[--flag=<value>]` token name declared in the
	 * `__invoke()` docblock's `## OPTIONS` section.
	 *
	 * Anchored to lines starting with `[--` (after the docblock `*`
	 * prefix) so `## EXAMPLES` command lines — which reuse real flag
	 * names in sample invocations — are not double-counted as
	 * declarations.
	 *
	 * @return string[] Flag names, without the leading `--`.
	 */
	private function docblockOptionNames(): array {
		$reflection = new ReflectionMethod( UpdateEventCommand::class, '__invoke' );
		$doc        = (string) $reflection->getDocComment();

		preg_match_all( '/^\s*\*\s*\[--([^\s=\]]+)=/m', $doc, $matches );

		return $matches[1];
	}

	private function fieldMap(): array {
		$reflection = new ReflectionMethod( UpdateEventCommand::class, 'fieldMap' );
		$reflection->setAccessible( true );

		return $reflection->invoke( new UpdateEventCommand() );
	}

	/**
	 * Regression for #858 cause 1: WP_CLI\SynopsisParser::classify_token()
	 * matches assoc-arg names against `$p_name = '([a-z-_0-9]+)'` — no
	 * uppercase. `--startDate` / `--startTime` failed this and were
	 * silently discarded as `unknown` tokens.
	 */
	public function test_no_uppercase_option_names_in_docblock(): void {
		$options = $this->docblockOptionNames();

		$this->assertNotEmpty( $options, 'Expected at least one declared option in the docblock.' );

		foreach ( $options as $option ) {
			$this->assertMatchesRegularExpression(
				'/^[a-z0-9_-]+$/',
				$option,
				"--{$option} is not valid WP-CLI synopsis syntax. WP_CLI\\SynopsisParser only matches [a-z-_0-9]+ for assoc-arg names, so an uppercase letter makes the token 'unknown' and WP-CLI discards it with an 'invalid synopsis part' warning."
			);
		}
	}

	/**
	 * Regression for #858 cause 2: most ability-supported fields were
	 * missing from the synopsis entirely, so WP_CLI\SynopsisValidator
	 * rejected them as unknown assoc args at call time. Assert the
	 * docblock declares exactly the ability's fields (minus the
	 * structural `event`/`events` keys, which come from the positional
	 * `<event_ids>` argument instead).
	 */
	public function test_docblock_declares_every_ability_field(): void {
		$expected = array_keys( $this->fieldMap() );
		sort( $expected );

		// `--format` is a CLI-only output concern, not part of the
		// ability's input schema.
		$declared = array_values( array_diff( $this->docblockOptionNames(), array( 'format' ) ) );
		sort( $declared );

		$this->assertSame(
			$expected,
			$declared,
			'The docblock ## OPTIONS list must declare exactly the fields the data-machine-events/update-event ability accepts (derived from its input schema), or CLI users will be told about flags that do not work, or be unable to set flags that do.'
		);
	}

	public function test_field_map_derives_kebab_case_flags_from_ability_schema(): void {
		$this->assertSame(
			array(
				'start-date'       => 'startDate',
				'start-time'       => 'startTime',
				'end-date'         => 'endDate',
				'end-time'         => 'endTime',
				'venue'            => 'venue',
				'description'      => 'description',
				'price'            => 'price',
				'ticket-url'       => 'ticketUrl',
				'performer'        => 'performer',
				'performer-type'   => 'performerType',
				'event-status'     => 'eventStatus',
				'event-type'       => 'eventType',
				'occurrence-dates' => 'occurrenceDates',
			),
			$this->fieldMap()
		);
	}

	public function test_extract_update_fields_maps_kebab_flags_to_ability_schema_keys(): void {
		$command    = new UpdateEventCommand();
		$reflection = new ReflectionMethod( UpdateEventCommand::class, 'extractUpdateFields' );
		$reflection->setAccessible( true );

		$assoc_args = array(
			'start-date'       => '2026-01-01',
			'start-time'       => '20:00',
			'end-date'         => '2026-01-02',
			'end-time'         => '23:00',
			'venue'            => '42',
			'description'      => 'Great show',
			'price'            => '$25',
			'ticket-url'       => 'https://example.com/tix',
			'performer'        => 'The Band',
			'performer-type'   => 'MusicGroup',
			'event-status'     => 'EventScheduled',
			'event-type'       => 'Concert',
			'occurrence-dates' => '["2026-01-01","2026-01-08"]',
		);

		$fields = $reflection->invoke( $command, $assoc_args, $this->fieldMap() );

		$this->assertSame(
			array(
				'startDate'       => '2026-01-01',
				'startTime'       => '20:00',
				'endDate'         => '2026-01-02',
				'endTime'         => '23:00',
				'venue'           => '42',
				'description'     => 'Great show',
				'price'           => '$25',
				'ticketUrl'       => 'https://example.com/tix',
				'performer'       => 'The Band',
				'performerType'   => 'MusicGroup',
				'eventStatus'     => 'EventScheduled',
				'eventType'       => 'Concert',
				'occurrenceDates' => array( '2026-01-01', '2026-01-08' ),
			),
			$fields
		);
	}

	public function test_extract_update_fields_ignores_unmapped_assoc_args(): void {
		$command    = new UpdateEventCommand();
		$reflection = new ReflectionMethod( UpdateEventCommand::class, 'extractUpdateFields' );
		$reflection->setAccessible( true );

		// camelCase / unknown keys must not leak through, since WP-CLI
		// itself would have already rejected them before __invoke() runs.
		$fields = $reflection->invoke(
			$command,
			array(
				'startDate' => '2026-01-01',
				'bogus'     => 'value',
			),
			$this->fieldMap()
		);

		$this->assertSame( array(), $fields );
	}
}
