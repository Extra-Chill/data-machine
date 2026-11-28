<?php
/**
 * AGENTS.md section generator.
 *
 * @package DataMachineEvents\Cli
 */

namespace DataMachineEvents\Cli;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides stable intent routing for the events CLI.
 */
class AgentsMdSection {

	/**
	 * Build the Markdown body for the Data Machine Events CLI AGENTS.md section.
	 *
	 * @param string $wp The Events-site WP-CLI invocation prefix.
	 * @return string
	 */
	public static function render( $wp ) {
		return <<<MARKDOWN
### Data Machine Events CLI

Owns event and venue data quality, scraper/import diagnostics, and maintenance for the Events site.

**Default routing**
- Unified quality audit/repair: start with `{$wp} data-machine-events check quality`, then use nested help to select a targeted repair
- Event updates: `{$wp} data-machine-events update-event`
- Venue maintenance: `{$wp} data-machine-events audit-venues`
- Scraper/import diagnostics: `{$wp} data-machine-events test-event-scraper`
- Date-table maintenance: `{$wp} data-machine-events backfill-event-dates`

**Discovery**
Use `{$wp} data-machine-events --help` for the complete live command map and `{$wp} data-machine-events <command> --help` for current options and nested commands. Live `--help` is authoritative.
MARKDOWN;
	}
}
