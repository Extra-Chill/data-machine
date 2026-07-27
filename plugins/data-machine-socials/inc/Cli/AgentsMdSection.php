<?php
/**
 * AGENTS.md Section Generator
 *
 * @package DataMachineSocials\Cli
 */

namespace DataMachineSocials\Cli;

defined( 'ABSPATH' ) || exit;

class AgentsMdSection {

	/**
	 * Build concise intent routing for the Data Machine Socials CLI.
	 *
	 * @param string $wp The `wp --allow-root --path=...` invocation prefix.
	 * @return string
	 */
	public static function render( $wp ) {
		$lines = array(
			'### Data Machine Socials CLI',
			'',
			'Data Machine Socials owns cross-platform social media authentication, reading, publishing, engagement, and per-item share tracking.',
			'',
			'**Default routing**',
			"- Check authentication or account status: `{$wp} datamachine-socials instagram status`",
			"- Read recent platform content: `{$wp} datamachine-socials instagram posts`",
			"- Discover public content: `{$wp} datamachine-socials reddit search \"live music\"`",
			"- Publish through a platform command: `{$wp} datamachine-socials instagram publish --help`",
			"- Read or reply to comments across supported platforms: `{$wp} datamachine-socials comments --help`",
			"- Inspect share tracking for a WordPress post: `{$wp} datamachine-socials shares list <post-id>`",
			'',
			'**Safety**',
			'Publishing, replies, deletes, and other mutations affect live social accounts. Confirm the target account, content, and requested action before running them.',
			'',
			'**Discovery**',
			"Use `{$wp} datamachine-socials --help` for the current platform list and `{$wp} datamachine-socials <platform> --help` for that platform's live actions and options. Live `--help` output is authoritative.",
		);

		return implode( "\n", $lines );
	}
}
