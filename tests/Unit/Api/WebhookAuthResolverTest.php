<?php
/**
 * WebhookAuthResolver tests.
 *
 * Exercises the v1→v2 compatibility layer and the preset filter registry.
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

	public function test_no_webhook_fields_resolves_to_bearer_with_empty_token(): void {
		$out = WebhookAuthResolver::resolve( array() );
		$this->assertSame( 'bearer', $out['mode'] );
		$this->assertNull( $out['verifier'] );
		$this->assertSame( '', $out['token'] );
	}

	public function test_v1_bearer_resolves_with_token(): void {
		$out = WebhookAuthResolver::resolve( array(
			'webhook_auth_mode' => 'bearer',
			'webhook_token'     => 'abc',
		) );
		$this->assertSame( 'bearer', $out['mode'] );
		$this->assertSame( 'abc', $out['token'] );
	}

	public function test_v1_hmac_sha256_expands_to_template_config(): void {
		$out = WebhookAuthResolver::resolve( array(
			'webhook_auth_mode'        => 'hmac_sha256',
			'webhook_signature_header' => 'X-Hub-Signature-256',
			'webhook_signature_format' => 'sha256=hex',
			'webhook_secret'           => 'deadbeef',
		) );

		$this->assertSame( 'hmac', $out['mode'] );
		$this->assertIsArray( $out['verifier'] );
		$this->assertSame( '{body}', $out['verifier']['signed_template'] );
		$this->assertSame( 'X-Hub-Signature-256', $out['verifier']['signature_source']['header'] );
		$this->assertSame( 'prefix', $out['verifier']['signature_source']['extract']['kind'] );
		$this->assertSame( 'sha256=', $out['verifier']['signature_source']['extract']['key'] );
		$this->assertSame( 'hex', $out['verifier']['signature_source']['encoding'] );
		$this->assertSame( 'deadbeef', $out['verifier']['secrets'][0]['value'] );
	}

	public function test_v1_hmac_sha256_base64_format_expands(): void {
		$out = WebhookAuthResolver::resolve( array(
			'webhook_auth_mode'        => 'hmac_sha256',
			'webhook_signature_header' => 'X-Shopify-Hmac-Sha256',
			'webhook_signature_format' => 'base64',
			'webhook_secret'           => 'xxx',
		) );

		$this->assertSame( 'base64', $out['verifier']['signature_source']['encoding'] );
		$this->assertSame( 'raw', $out['verifier']['signature_source']['extract']['kind'] );
	}

	public function test_v2_webhook_auth_block_passes_through(): void {
		$config = array(
			'mode'             => 'hmac',
			'algo'             => 'sha256',
			'signed_template'  => '{timestamp}.{body}',
			'signature_source' => array( 'header' => 'X', 'extract' => array( 'kind' => 'raw' ), 'encoding' => 'hex' ),
			'secrets'          => array( array( 'id' => 'current', 'value' => 'abc' ) ),
		);
		$out = WebhookAuthResolver::resolve( array( 'webhook_auth' => $config ) );

		$this->assertSame( 'hmac', $out['mode'] );
		$this->assertSame( '{timestamp}.{body}', $out['verifier']['signed_template'] );
		$this->assertSame( 'abc', $out['verifier']['secrets'][0]['value'] );
	}

	public function test_v2_webhook_auth_inherits_scheduling_config_secrets(): void {
		$config = array(
			'mode'             => 'hmac',
			'signed_template'  => '{body}',
			'signature_source' => array( 'header' => 'X', 'extract' => array( 'kind' => 'raw' ), 'encoding' => 'hex' ),
		);
		$out = WebhookAuthResolver::resolve( array(
			'webhook_auth'    => $config,
			'webhook_secrets' => array(
				array( 'id' => 'current', 'value' => 'xyz' ),
			),
		) );

		$this->assertSame( 'xyz', $out['verifier']['secrets'][0]['value'] );
	}

	public function test_preset_lookup_via_filter(): void {
		add_filter( 'datamachine_webhook_auth_presets', function ( $p ) {
			$p['stripe'] = array(
				'mode'             => 'hmac',
				'algo'             => 'sha256',
				'signed_template'  => '{timestamp}.{body}',
				'signature_source' => array(
					'header'   => 'Stripe-Signature',
					'extract'  => array( 'kind' => 'kv_pairs', 'key' => 'v1', 'separator' => ',' ),
					'encoding' => 'hex',
				),
				'timestamp_source' => array(
					'header'  => 'Stripe-Signature',
					'extract' => array( 'kind' => 'kv_pairs', 'key' => 't', 'separator' => ',' ),
					'format'  => 'unix',
				),
				'tolerance_seconds' => 300,
			);
			return $p;
		} );

		$out = WebhookAuthResolver::resolve( array(
			'webhook_auth_preset' => 'stripe',
			'webhook_secret'      => 'whsec_abc',
		) );

		$this->assertSame( 'hmac', $out['mode'] );
		$this->assertSame( 'stripe', $out['preset'] );
		$this->assertSame( 'Stripe-Signature', $out['verifier']['signature_source']['header'] );
		$this->assertSame( 'whsec_abc', $out['verifier']['secrets'][0]['value'] );
	}

	public function test_preset_with_overrides_merges_deeply(): void {
		add_filter( 'datamachine_webhook_auth_presets', function ( $p ) {
			$p['stripe'] = array(
				'mode'             => 'hmac',
				'tolerance_seconds' => 300,
				'signature_source' => array( 'header' => 'Stripe-Signature', 'encoding' => 'hex' ),
			);
			return $p;
		} );

		$out = WebhookAuthResolver::resolve( array(
			'webhook_auth_preset'    => 'stripe',
			'webhook_auth_overrides' => array(
				'tolerance_seconds' => 600,
				'signature_source'  => array( 'header' => 'X-Custom' ),
			),
			'webhook_secret'         => 'x',
		) );

		$this->assertSame( 600, $out['verifier']['tolerance_seconds'] );
		$this->assertSame( 'X-Custom', $out['verifier']['signature_source']['header'] );
		$this->assertSame( 'hex', $out['verifier']['signature_source']['encoding'], 'Base encoding preserved via deep merge' );
	}

	public function test_unknown_preset_falls_through_to_v1_compat(): void {
		// When the preset isn't registered, resolver falls back to legacy v1 fields.
		$out = WebhookAuthResolver::resolve( array(
			'webhook_auth_preset' => 'nonexistent',
			'webhook_auth_mode'   => 'bearer',
			'webhook_token'       => 'legacy',
		) );

		$this->assertSame( 'bearer', $out['mode'] );
		$this->assertSame( 'legacy', $out['token'] );
	}

	public function test_get_presets_empty_by_default(): void {
		$this->assertSame( array(), WebhookAuthResolver::get_presets() );
	}

	public function test_deep_merge_replaces_scalars_merges_arrays(): void {
		$base = array(
			'a' => 1,
			'b' => array( 'x' => 1, 'y' => 2 ),
			'c' => array( 'nested' => array( 'deep' => 'original' ) ),
		);
		$over = array(
			'a' => 99,
			'b' => array( 'y' => 20, 'z' => 30 ),
			'c' => array( 'nested' => array( 'deep' => 'overridden', 'extra' => 'added' ) ),
		);
		$out = WebhookAuthResolver::deep_merge( $base, $over );

		$this->assertSame( 99, $out['a'] );
		$this->assertSame( 1, $out['b']['x'] );
		$this->assertSame( 20, $out['b']['y'] );
		$this->assertSame( 30, $out['b']['z'] );
		$this->assertSame( 'overridden', $out['c']['nested']['deep'] );
		$this->assertSame( 'added', $out['c']['nested']['extra'] );
	}
}
