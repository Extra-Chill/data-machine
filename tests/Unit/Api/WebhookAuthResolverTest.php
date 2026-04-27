<?php
/**
 * WebhookAuthResolver tests.
 *
 * Covers canonical auth resolution and the preset filter registry.
 * Requires WordPress because `apply_filters` is used for preset discovery.
 *
 * @package DataMachine\Tests\Unit\Api
 */

namespace DataMachine\Tests\Unit\Api;

use DataMachine\Api\WebhookAuthResolver;
use WP_UnitTestCase;

class WebhookAuthResolverTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'datamachine_webhook_auth_presets' );
		parent::tear_down();
	}

	/* -------- resolve() -------- */

	public function test_empty_config_resolves_to_bearer(): void {
		$out = WebhookAuthResolver::resolve( array() );
		$this->assertSame( 'bearer', $out['mode'] );
		$this->assertNull( $out['verifier'] );
	}

	public function test_bearer_returns_token(): void {
		$out = WebhookAuthResolver::resolve( array(
			'webhook_auth_mode' => 'bearer',
			'webhook_token'     => 'abc',
		) );
		$this->assertSame( 'bearer', $out['mode'] );
		$this->assertSame( 'abc', $out['token'] );
	}

	public function test_hmac_with_template_passes_through(): void {
		$template = array(
			'mode'             => 'hmac',
			'signed_template'  => '{body}',
			'signature_source' => array(
				'header'   => 'X-Sig',
				'extract'  => array( 'kind' => 'raw' ),
				'encoding' => 'hex',
			),
		);
		$out = WebhookAuthResolver::resolve( array(
			'webhook_auth_mode' => 'hmac',
			'webhook_auth'      => $template,
			'webhook_secrets'   => array( array( 'id' => 'current', 'value' => 'abc' ) ),
		) );

		$this->assertSame( 'hmac', $out['mode'] );
		$this->assertSame( '{body}', $out['verifier']['signed_template'] );
		$this->assertSame( 'abc', $out['verifier']['secrets'][0]['value'] );
	}

	public function test_hmac_without_template_returns_null_verifier(): void {
		$out = WebhookAuthResolver::resolve( array(
			'webhook_auth_mode' => 'hmac',
			// no webhook_auth block — this is a misconfigured flow, caller should 401 it.
		) );
		$this->assertSame( 'hmac', $out['mode'] );
		$this->assertNull( $out['verifier'] );
	}

	/* -------- presets -------- */

	public function test_get_presets_empty_by_default(): void {
		$this->assertSame( array(), WebhookAuthResolver::get_presets() );
	}

	public function test_presets_registered_via_filter(): void {
		add_filter( 'datamachine_webhook_auth_presets', function ( $p ) {
			$p['example'] = array( 'mode' => 'hmac' );
			return $p;
		} );
		$presets = WebhookAuthResolver::get_presets();
		$this->assertArrayHasKey( 'example', $presets );
	}

	public function test_deep_merge_preserves_base_keys(): void {
		$base = array(
			'signature_source' => array( 'header' => 'X-Default', 'encoding' => 'hex' ),
			'tolerance_seconds' => 300,
		);
		$override = array(
			'signature_source' => array( 'header' => 'X-Override' ),
		);
		$out = WebhookAuthResolver::deep_merge( $base, $override );
		$this->assertSame( 'X-Override', $out['signature_source']['header'] );
		$this->assertSame( 'hex', $out['signature_source']['encoding'] ); // preserved
		$this->assertSame( 300, $out['tolerance_seconds'] );
	}
}
