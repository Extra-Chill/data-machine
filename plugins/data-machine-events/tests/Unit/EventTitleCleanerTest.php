<?php
/**
 * EventTitleCleaner tests.
 *
 * Schedule and price noise is stripped from imported titles (issue #932)
 * while legitimate names that contain digits, dashes, or dollar signs stay
 * intact. Cases come from live upcoming titles on events.extrachill.com.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.68.0
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\EventTitleCleaner;
use WP_UnitTestCase;

class EventTitleCleanerTest extends WP_UnitTestCase {

	/**
	 * @return array<string, array{0:string,1:string,2:string,3:string}>
	 */
	public function noisy_title_provider(): array {
		return array(
			'hour range'                => array( 'Sean Smith 6-9pm', 'Sean Smith', '21:00', '' ),
			'meridiem on both ends'     => array( 'Cooper River Band 7pm-10pm', 'Cooper River Band', '22:00', '' ),
			'minutes in range'          => array( 'Dave Landeo 6:30-9:30pm', 'Dave Landeo', '21:30', '' ),
			'uppercase meridiem'        => array( 'Gwen McMillin 7-9PM', 'Gwen McMillin', '21:00', '' ),
			'spaced range plus FREE'    => array( 'Jazz Wednesdays @ Axelrad 7pm - 10pm FREE', 'Jazz Wednesdays @ Axelrad', '22:00', '' ),
			'comma before range'        => array( 'An Afternoon of Goth LONDON - Indie for the over 30s, 3pm-7pm', 'An Afternoon of Goth LONDON - Indie for the over 30s', '19:00', '' ),
			'range before age marker'   => array( 'The 90s Halloween Party, 11pm-3am (18+)', 'The 90s Halloween Party (18+)', '03:00', '' ),
			'trailing price'            => array( 'David Peterson $20', 'David Peterson', '', '$20' ),
			'door/show/price/age blob'  => array( 'Say Uncle / Kook. Door @ 7:30. Show @ 8:30. $10 21+', 'Say Uncle / Kook 21+', '', '$10' ),
			'adv/dos price'             => array( 'Phantom / Interceptor. Door @ 7:30. Show @ 8:30. $10 ADV / $13 DOS 21+', 'Phantom / Interceptor 21+', '', '$10' ),
			'door and show combined'    => array( 'Deep Crates: Yacht Rock Edition! Door and Show @ 7. $5 21+', 'Deep Crates: Yacht Rock Edition! 21+', '', '$5' ),
			'door/show without price'   => array( 'Pentagram String Band / Doom Scroll. Door @ 7:30. Show @ 8:30. 21+', 'Pentagram String Band / Doom Scroll 21+', '', '' ),
			'tiered price, age kept'    => array( 'Serenity presents Nothing But House. Door at 7. Show @ 8. $5 over 21 / $10 18-20. 18+ event', 'Serenity presents Nothing But House 18+', '', '$5' ),
		);
	}

	/**
	 * @dataProvider noisy_title_provider
	 */
	public function test_noise_is_stripped_and_details_recovered( string $raw, string $title, string $end_time, string $price ): void {
		$result = EventTitleCleaner::clean( $raw );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( $title, $result['title'] );
		$this->assertSame( $end_time, $result['end_time'] );
		$this->assertSame( $price, $result['price'] );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function clean_title_provider(): array {
		return array(
			'dollar-sign name'          => array( '$uicideboy$' ),
			'dollar-sign name and tour' => array( '$uicideboy$ - Grey Day Tour' ),
			'dash and digits'           => array( 'Blink-182' ),
			'leading number'            => array( '21 Savage' ),
			'trailing number'           => array( 'Area 51' ),
			'band with number'          => array( 'Sum 41 w/ Simple Plan' ),
			'year-like number'          => array( 'The 1975' ),
			'number pair in name'       => array( 'Lo-Fi 4-20 Party' ),
			'range without meridiem'    => array( 'Karaoke 8:30-11:30' ),
			'musical title'             => array( '9 to 5: The Musical' ),
			'age marker only'           => array( 'Black Flag (21+)' ),
			'ages suffix'               => array( 'She Wants Revenge - Ages 21+' ),
			'price inside parentheses'  => array( 'Fairy Market | Costume Contest ($100 prize)' ),
			'dollar amount as a name'   => array( '$5 Fridays' ),
			'price word not vocabulary' => array( 'Cash $20 Show' ),
			'show @ venue'              => array( 'Comedy Show @ Tomorrowland' ),
			'single time only'          => array( 'Late Show 10pm' ),
			'interior punctuation'      => array( 'Carnivore A.D., Necrostalker, Karnage' ),
			'trailing period'           => array( 'Bobby Bare Jr.' ),
			'spaced colon'              => array( 'Tabber : Modern Goth Amsterdam' ),
		);
	}

	/**
	 * @dataProvider clean_title_provider
	 */
	public function test_legitimate_titles_are_untouched( string $raw ): void {
		$result = EventTitleCleaner::clean( $raw );

		$this->assertFalse( $result['changed'] );
		$this->assertSame( $raw, $result['title'] );
		$this->assertSame( '', $result['end_time'] );
		$this->assertSame( '', $result['price'] );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function schedule_blob_provider(): array {
		return array(
			'multi-act schedule'     => array( 'Rasa Nova 7pm, Touch of Space 8-9pm (J. Daily)' ),
			'two ranges'             => array( 'Peace & Riot 9pm-11pm, George Lee 11pm-1am' ),
			'extra @ times'          => array( 'Cosmic Culture Club: MUTATION! Door @ 7. Screening @ 8. Live Performances @ 9. $10-$30 suggested donation' ),
			'dangling connector'     => array( '614 HALLOWEEN PARTY - Starting at $10 at the door' ),
			'price ends the sentence' => array( 'Michigan Hardcore 10 for $10.00' ),
		);
	}

	/**
	 * Titles where a partial cleanup would read worse than the original are
	 * left for a human or the AI step.
	 *
	 * @dataProvider schedule_blob_provider
	 */
	public function test_schedule_blobs_are_left_alone( string $raw ): void {
		$result = EventTitleCleaner::clean( $raw );

		$this->assertFalse( $result['changed'] );
		$this->assertSame( $raw, $result['title'] );
	}

	public function test_cleaning_never_empties_a_title(): void {
		$result = EventTitleCleaner::clean( '7pm-10pm' );

		$this->assertFalse( $result['changed'] );
		$this->assertSame( '7pm-10pm', $result['title'] );
	}
}
