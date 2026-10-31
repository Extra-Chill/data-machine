<?php
/**
 * Pure-PHP smoke for structured agent token scope payloads.
 *
 * @package DataMachine\Tests
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	if ( ! interface_exists( 'WP_Agent_Token_Store' ) ) {
		interface WP_Agent_Token_Store {}
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) {
			return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
		}
	}

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ) {
			return trim( wp_strip_all_tags( (string) $value ) );
		}
	}

	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( $value ) {
			return strip_tags( (string) $value );
		}
	}

	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = 'default' ) {
			unset( $domain );
			return $text;
		}
	}
}

namespace DataMachine\Core\Database {
	if ( ! class_exists( BaseRepository::class ) ) {
		abstract class BaseRepository {}
	}
}

namespace {
	require_once __DIR__ . '/../inc/Core/Database/Agents/AgentTokens.php';

	use DataMachine\Core\Database\Agents\AgentTokens;

	$failures = 0;

	$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
		if ( $condition ) {
			echo "PASS: {$message}\n";
			return;
		}

		++$failures;
		echo "FAIL: {$message}\n";
	};

	$scope = array(
		'scope'              => 'read_only',
		'label'              => 'Read-only',
		'ability_categories' => array( 'datamachine-content', '' ),
		'ability_allow'      => array( 'datamachine/wiki' ),
		'ability_deny'       => array( 'datamachine/delete-post' ),
		'capabilities'       => array( 'read', 'datamachine_chat', 'read' ),
	);

	$normalized = AgentTokens::normalize_capability_payload( $scope );

	$assert( array( 'read', 'datamachine_chat' ) === $normalized['allowed_capabilities'], 'structured scope extracts raw capability ceiling' );
	$assert( 'read_only' === $normalized['stored_payload']['scope'], 'structured scope keeps scope key for storage' );
	$assert( array( 'datamachine-content' ) === $normalized['stored_payload']['ability_categories'], 'structured scope normalizes categories' );
	$assert( 'Read-only' === AgentTokens::scope_label( $normalized['stored_payload'] ), 'structured scope reports audit label' );
	$assert( 'Full owner ceiling' === AgentTokens::scope_label( null ), 'null capabilities remain full owner ceiling' );

	exit( $failures > 0 ? 1 : 0 );
}
