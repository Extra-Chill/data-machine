<?php
/**
 * TextNormalization tests.
 *
 * Covers the ingestion text storage contract (issue #844): entity decoding,
 * entity detection, and the bounded kses suspension that keeps a decoded
 * title from being re-encoded on the way into the database.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.65.0
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\TextNormalization;
use WP_UnitTestCase;

class TextNormalizationTest extends WP_UnitTestCase {

	public function test_decodes_named_decimal_and_hex_entities(): void {
		$this->assertSame(
			'Kanika Moore & The Brown Eyed Bois',
			TextNormalization::decode_entities( 'Kanika Moore &amp; The Brown Eyed Bois' )
		);

		$this->assertSame(
			'Kanika Moore & The Brown Eyed Bois',
			TextNormalization::decode_entities( 'Kanika Moore &#038; The Brown Eyed Bois' )
		);

		$this->assertSame(
			'Sam’s Grill – Live',
			TextNormalization::decode_entities( 'Sam&#8217;s Grill &#8211; Live' )
		);

		$this->assertSame(
			'The "Quiet" Show',
			TextNormalization::decode_entities( 'The &quot;Quiet&quot; Show' )
		);

		$this->assertSame(
			'Rock & Roll',
			TextNormalization::decode_entities( 'Rock &#x26; Roll' )
		);
	}

	public function test_decoding_is_idempotent_for_plain_text(): void {
		$plain = 'Move Wellness — Power Pilates & Matcha';
		$this->assertSame( $plain, TextNormalization::decode_entities( $plain ) );
		$this->assertSame( $plain, TextNormalization::decode_entities( TextNormalization::decode_entities( $plain ) ) );
	}

	public function test_decoding_does_not_strip_content(): void {
		$this->assertSame(
			'Ben & Jerry & Phish',
			TextNormalization::decode_entities( 'Ben &amp; Jerry &amp; Phish' )
		);
	}

	public function test_detects_entity_references(): void {
		$this->assertTrue( TextNormalization::contains_entities( 'Jordan Igoe &amp; Friends' ) );
		$this->assertTrue( TextNormalization::contains_entities( 'Pilates &#038; Matcha' ) );
		$this->assertTrue( TextNormalization::contains_entities( 'Guitar&#8217;s Night' ) );
		$this->assertTrue( TextNormalization::contains_entities( 'AC&#x2F;DC Tribute' ) );

		$this->assertFalse( TextNormalization::contains_entities( 'Move Wellness — Power Pilates & Matcha' ) );
		$this->assertFalse( TextNormalization::contains_entities( 'R&B Night' ) );
		$this->assertFalse( TextNormalization::contains_entities( 'Plain title' ) );
	}

	public function test_kses_suspension_removes_and_restores_save_filters(): void {
		add_filter( 'title_save_pre', 'wp_filter_kses' );

		$inside = TextNormalization::with_kses_suspended(
			static fn(): string => (string) apply_filters( 'title_save_pre', 'Foo & Bar' )
		);

		$this->assertSame( 'Foo & Bar', $inside, 'The write callback must not see re-encoding filters.' );

		$after = (string) apply_filters( 'title_save_pre', 'Foo & Bar' );
		$this->assertSame( 'Foo &amp; Bar', $after, 'Prior filter state must be restored after the callback.' );

		remove_filter( 'title_save_pre', 'wp_filter_kses' );
	}

	public function test_kses_suspension_is_a_noop_when_filters_inactive(): void {
		remove_all_filters( 'title_save_pre' );

		$called = false;
		$result = TextNormalization::with_kses_suspended(
			static function () use ( &$called ): string {
				$called = true;
				return 'value';
			}
		);

		$this->assertTrue( $called );
		$this->assertSame( 'value', $result );
		$this->assertFalse( has_filter( 'title_save_pre', 'wp_filter_kses' ) );
	}

	public function test_kses_suspension_restores_filters_after_exception(): void {
		add_filter( 'title_save_pre', 'wp_filter_kses' );

		try {
			TextNormalization::with_kses_suspended(
				static function (): void {
					throw new \RuntimeException( 'write failed' );
				}
			);
			$this->fail( 'Expected the exception to propagate.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'write failed', $e->getMessage() );
		}

		$this->assertNotFalse( has_filter( 'title_save_pre', 'wp_filter_kses' ), 'Prior filter state must survive a throwing callback.' );

		remove_filter( 'title_save_pre', 'wp_filter_kses' );
	}
}
