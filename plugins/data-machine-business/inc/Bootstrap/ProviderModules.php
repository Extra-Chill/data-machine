<?php
/**
 * Data Machine Business provider module declarations.
 *
 * @package DataMachineBusiness
 */

namespace DataMachineBusiness\Bootstrap;

defined( 'ABSPATH' ) || exit;

final class ProviderModules {

	/** @return ProviderModule[] */
	public static function all(): array {
		$abilities        = array(
			'DataMachine\\Abilities\\AbilityRegistration' => static fn(): bool => class_exists( 'DataMachine\\Abilities\\AbilityRegistration' ),
		);
		$tools            = array(
			'DataMachine\\Engine\\AI\\Tools\\BaseTool' => static fn(): bool => class_exists( 'DataMachine\\Engine\\AI\\Tools\\BaseTool' ),
		);
		$fetch_handlers   = array(
			'DataMachine\\Core\\Steps\\Fetch\\Handlers\\FetchHandler' => static fn(): bool => class_exists( 'DataMachine\\Core\\Steps\\Fetch\\Handlers\\FetchHandler' ),
		);
		$publish_handlers = array(
			'DataMachine\\Core\\Steps\\Publish\\Handlers\\PublishHandler' => static fn(): bool => class_exists( 'DataMachine\\Core\\Steps\\Publish\\Handlers\\PublishHandler' ),
		);

		return array(
			new ProviderModule(
				'indexnow',
				$abilities,
				array( 'datamachine/indexnow-submit', 'datamachine/indexnow-status', 'datamachine/indexnow-generate-key', 'datamachine/indexnow-verify-key' ),
				static fn() => new \DataMachineBusiness\Abilities\SEO\IndexNowAbilities()
			),
			new ProviderModule(
				'google-analytics',
				array_merge( $abilities, $tools ),
				array( 'datamachine/google-analytics', 'tool:google_analytics' ),
				static function (): void {
					new \DataMachineBusiness\Abilities\Analytics\GoogleAnalyticsAbilities();
					new \DataMachineBusiness\Engine\AI\Tools\Global\GoogleAnalytics();
				}
			),
			new ProviderModule(
				'mediavine',
				$abilities,
				array( 'datamachine/mediavine-reports' ),
				static fn() => new \DataMachineBusiness\Abilities\Analytics\MediavineReportsAbilities()
			),
			new ProviderModule(
				'content-insights',
				$abilities,
				array( 'datamachine/content-performance', 'datamachine/content-flags' ),
				static function (): void {
					new \DataMachineBusiness\Abilities\Analytics\ContentPerformanceAbility();
					new \DataMachineBusiness\Abilities\Analytics\ContentFlagsAbility();
				}
			),
			new ProviderModule(
				'google-sheets',
				array_merge( $abilities, $fetch_handlers, $publish_handlers ),
				array( 'datamachine/fetch-googlesheets', 'datamachine/publish-googlesheets', 'handler:googlesheets_fetch', 'handler:googlesheets_publish' ),
				static function (): void {
					new \DataMachineBusiness\Abilities\GoogleSheets\FetchGoogleSheetsAbility();
					new \DataMachineBusiness\Abilities\GoogleSheets\PublishGoogleSheetsAbility();
					new \DataMachineBusiness\Handlers\GoogleSheets\GoogleSheetsFetch();
					new \DataMachineBusiness\Handlers\GoogleSheets\GoogleSheetsPublish();
				}
			),
			new ProviderModule(
				'google-drive',
				array_merge( $abilities, $fetch_handlers ),
				array( 'datamachine/fetch-googledrive', 'datamachine/list-googledrive-files', 'datamachine/read-googledrive-doc', 'datamachine/download-googledrive', 'handler:googledrive_fetch' ),
				static function (): void {
					new \DataMachineBusiness\Abilities\GoogleDrive\FetchGoogleDriveAbility();
					new \DataMachineBusiness\Abilities\GoogleDrive\ListGoogleDriveFilesAbility();
					new \DataMachineBusiness\Abilities\GoogleDrive\ReadGoogleDriveDocAbility();
					new \DataMachineBusiness\Abilities\GoogleDrive\DownloadGoogleDriveAbility();
					new \DataMachineBusiness\Handlers\GoogleDrive\GoogleDriveFetch();
				}
			),
			new ProviderModule(
				'pagespeed',
				array_merge( $abilities, $tools ),
				array( 'datamachine/pagespeed', 'tool:pagespeed', 'rest:datamachine/v1/analytics/pagespeed' ),
				static function (): void {
					new \DataMachineBusiness\Abilities\PageSpeed\PageSpeedAbility();
					new \DataMachineBusiness\Tools\PageSpeedTool();
					\DataMachineBusiness\Api\PageSpeedAnalytics::register();
				}
			),
			new ProviderModule(
				'google-search',
				$tools,
				array( 'tool:google_search' ),
				static fn() => new \DataMachineBusiness\Tools\GoogleSearch()
			),
			new ProviderModule(
				'google-search-console',
				array_merge( $abilities, $tools ),
				array( 'datamachine/google-search-console', 'datamachine/gsc-opportunity', 'tool:google_search_console', 'rest:datamachine/v1/analytics/gsc' ),
				static function (): void {
					new \DataMachineBusiness\Abilities\Analytics\GoogleSearchConsoleAbilities();
					new \DataMachineBusiness\Abilities\Analytics\GscOpportunityAbility();
					new \DataMachineBusiness\Tools\GoogleSearchConsole();
					\DataMachineBusiness\Api\GoogleSearchConsoleAnalytics::register();
				}
			),
			new ProviderModule(
				'slack',
				array_merge( $abilities, $fetch_handlers, $publish_handlers ),
				array( 'datamachine/post-message-slack', 'datamachine/fetch-messages-slack', 'handler:slack_publish', 'handler:slack_fetch' ),
				static function (): void {
					new \DataMachineBusiness\Abilities\Slack\PostMessageSlackAbility();
					new \DataMachineBusiness\Abilities\Slack\FetchMessagesSlackAbility();
					new \DataMachineBusiness\Handlers\Slack\SlackPublish();
					new \DataMachineBusiness\Handlers\Slack\SlackFetch();
				}
			),
			new ProviderModule(
				'discord',
				array_merge( $abilities, $fetch_handlers, $publish_handlers ),
				array( 'datamachine/post-message-discord', 'datamachine/fetch-messages-discord', 'handler:discord_publish', 'handler:discord_fetch' ),
				static function (): void {
					new \DataMachineBusiness\Abilities\Discord\PostMessageDiscordAbility();
					new \DataMachineBusiness\Abilities\Discord\FetchMessagesDiscordAbility();
					new \DataMachineBusiness\Handlers\Discord\DiscordPublish();
					new \DataMachineBusiness\Handlers\Discord\DiscordFetch();
				}
			),
			new ProviderModule(
				'bing-webmaster',
				array_merge( $abilities, $tools ),
				array( 'datamachine/bing-webmaster', 'tool:bing_webmaster' ),
				static function (): void {
					new \DataMachineBusiness\Abilities\Analytics\BingWebmasterAbilities();
					new \DataMachineBusiness\Tools\BingWebmaster();
				}
			),
			new ProviderModule(
				'amazon-affiliate',
				$tools,
				array( 'tool:amazon_affiliate_link' ),
				static fn() => new \DataMachineBusiness\Tools\AmazonAffiliateLink()
			),
			new ProviderModule(
				'media-hygiene',
				$abilities,
				array( 'datamachine/media-hygiene' ),
				static fn() => new \DataMachineBusiness\Abilities\MediaHygiene\MediaHygieneAbility()
			),
			new ProviderModule(
				'sendy',
				$abilities,
				array( 'datamachine/sendy-subscribe', 'datamachine/sendy-push-campaign', 'datamachine/sendy-list-campaigns', 'datamachine/sendy-get-campaign', 'datamachine/sendy-delete-campaign', 'datamachine/sendy-metrics' ),
				static fn() => new \DataMachineBusiness\Abilities\Sendy\SendyAbilities()
			),
		);
	}
}
