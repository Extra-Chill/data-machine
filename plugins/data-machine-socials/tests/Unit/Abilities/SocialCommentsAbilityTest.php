<?php
/**
 * Contract tests for the generic account-level comments ability.
 */

namespace DataMachineSocials\Tests\Unit\Abilities;

use DataMachineSocials\Abilities\SocialCommentsAbility;
use WP_UnitTestCase;

class SocialCommentsAbilityTest extends WP_UnitTestCase {

	private SocialCommentsAbility $ability;

	public function set_up(): void {
		parent::set_up();
		$this->ability = new SocialCommentsAbility();
	}

	public function test_unsupported_provider_is_explicit(): void {
		$result = $this->ability->execute( array( 'provider' => 'threads' ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'threads', $result['data']['provider'] );
		$this->assertSame( 'unsupported', $result['data']['status'] );
		$this->assertFalse( $result['data']['partial'] );
	}

	public function test_missing_provider_is_explicitly_unsupported(): void {
		$result = $this->ability->execute( array() );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'unsupported', $result['data']['status'] );
	}

	public function test_unknown_action_is_rejected_by_the_contract(): void {
		$result = $this->ability->execute( array( 'action' => 'comments' ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'invalid_action', $result['data']['status'] );
	}

	public function test_supported_provider_without_read_ability_is_provider_error(): void {
		$result = $this->ability->execute( array( 'provider' => 'facebook' ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'provider_error', $result['data']['status'] );
		$this->assertNotEmpty( $result['data']['error'] );
	}

	public function test_supported_provider_returns_globally_sorted_normalized_comments(): void {
		$ability = new class() extends SocialCommentsAbility {
			protected function getProviderAbility( string $provider ) {
				return new class() {
					public function execute( array $input ): array {
						if ( 'list' === $input['action'] ) {
							return array(
								'success' => true,
								'data'    => array(
									'media'    => array( array( 'id' => 'new-post' ), array( 'id' => 'old-post' ) ),
									'has_next' => false,
								),
							);
						}

						$timestamp = 'new-post' === $input['media_id'] ? '2026-08-01T10:00:00Z' : '2026-08-02T10:00:00Z';
						return array(
							'success' => true,
							'data'    => array(
								'comments' => array( array(
									'id'        => $input['media_id'] . '-comment',
									'username'  => 'listener',
									'text'      => 'Thanks @extrachill',
									'timestamp' => $timestamp,
								) ),
							),
						);
					}
				};
			}
		};

		$result = $ability->execute( array( 'provider' => 'instagram', 'limit' => 2 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'old-post-comment', $result['data']['comments'][0]['id'] );
		$this->assertSame( 'instagram', $result['data']['comments'][0]['platform'] );
		$this->assertSame( 'old-post', $result['data']['comments'][0]['media_id'] );
		$this->assertSame( array( 'extrachill' ), $result['data']['comments'][0]['mentions'] );
		$this->assertSame( 2, $result['data']['count'] );
	}
}
