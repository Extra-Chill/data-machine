<?php
/**
 * WebhookAuthResolver tests.
 *
 * Covers the silent legacy-migration path and the preset filter registry.
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

	/* -------- migrate_legacy() -------- */

	public function test_migrate_noop_for_bearer_flow(): void {
		$in  = array(
			'webhook_enabled'   => true,
			'webhook_auth_mode' => 'bearer',
			'webhook_token'     => 'tok',
		);
		$out = WebhookAuthResolver::migrate_legacy( $in );
		$this->assertFalse( $out['migrated'] );
		$this->assertSame( $in, $out['config'] );
	}

	public function test_migrate_noop_for_canonical_hmac_flow(): void {
		$in  = array(
			'webhook_enabled'   => true,
			'webhook_auth_mode' => 'hmac',
			'webhook_auth'      => array( 'mode' => 'hmac' ),
			'webhook_secrets'   => array(),
		);
		$out = WebhookAuthResolver::migrate_legacy( $in );
		$this->assertFalse( $out['migrated'] );
	}

	public function test_migrate_v1_hmac_sha256_flow_to_v2(): void {
		$in  = array(
			'webhook_enabled'          => true,
			'webhook_auth_mode'        => 'hmac_sha256',
			'webhook_signature_header' => 'X-Hub-Signature-256',
			'webhook_signature_format' => 'sha256=hex',
			'webhook_secret'           => 'legacy-secret',
		);
		$out = WebhookAuthResolver::migrate_legacy( $in );

		$this->assertTrue( $out['migrated'] );
		$config = $out['config'];

		$this->assertSame( 'hmac', $config['webhook_auth_mode'] );
		$this->assertArrayNotHasKey( 'webhook_signature_header', $config );
		$this->assertArrayNotHasKey( 'webhook_signature_format', $config );
		$this->assertArrayNotHasKey( 'webhook_secret', $config );

		$this->assertArrayHasKey( 'webhook_auth', $config );
		$this->assertSame( '{body}', $config['webhook_auth']['signed_template'] );
		$this->assertSame( 'X-Hub-Signature-256', $config['webhook_auth']['signature_source']['header'] );
		$this->assertSame( 'prefix', $config['webhook_auth']['signature_source']['extract']['kind'] );
		$this->assertSame( 'sha256=', $config['webhook_auth']['signature_source']['extract']['key'] );
		$this->assertSame( 'hex', $config['webhook_auth']['signature_source']['encoding'] );

		$this->assertArrayHasKey( 'webhook_secrets', $config );
		$this->assertSame( 'current', $config['webhook_secrets'][0]['id'] );
		$this->assertSame( 'legacy-secret', $config['webhook_secrets'][0]['value'] );
	}

	public function test_migrate_v1_base64_format(): void {
		$in  = array(
			'webhook_enabled'          => true,
			'webhook_auth_mode'        => 'hmac_sha256',
			'webhook_signature_header' => 'X-Shopify-Hmac-Sha256',
			'webhook_signature_format' => 'base64',
			'webhook_secret'           => 'x',
		);
		$out = WebhookAuthResolver::migrate_legacy( $in );
		$this->assertTrue( $out['migrated'] );
		$this->assertSame( 'base64', $out['config']['webhook_auth']['signature_source']['encoding'] );
		$this->assertSame( 'raw', $out['config']['webhook_auth']['signature_source']['extract']['kind'] );
	}

	public function test_migrate_drops_orphan_legacy_fields(): void {
		// Fields left over from a partial migration but no legacy mode set.
		$in  = array(
			'webhook_enabled'          => true,
			'webhook_auth_mode'        => 'hmac',
			'webhook_auth'             => array( 'mode' => 'hmac' ),
			'webhook_signature_header' => 'stale',
			'webhook_secret'           => 'stale',
		);
		$out = WebhookAuthResolver::migrate_legacy( $in );
		$this->assertTrue( $out['migrated'] );
		$this->assertArrayNotHasKey( 'webhook_signature_header', $out['config'] );
		$this->assertArrayNotHasKey( 'webhook_secret', $out['config'] );
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
