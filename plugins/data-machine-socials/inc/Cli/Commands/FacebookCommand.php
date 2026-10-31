<?php
/**
 * WP-CLI Facebook Command
 *
 * @package    DataMachineSocials
 * @subpackage Cli\Commands
 * @since      0.3.0
 */

namespace DataMachineSocials\Cli\Commands;

use WP_CLI;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Facebook integration for Data Machine Socials.
 *
 * ## EXAMPLES
 *
	 *     wp datamachine-socials facebook posts
	 *     wp datamachine-socials facebook post 123456789
	 *     wp datamachine-socials facebook comments 123456789
	 *     wp datamachine-socials facebook reply-comment 987654321 "Thanks!"
	 *     wp datamachine-socials facebook status
	 */
class FacebookCommand {

	/**
	 * List recent Facebook Page posts.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Number of posts to return.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--after=<cursor>]
	 * : Pagination cursor for next page.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 */
	public function posts( $args, $assoc_args ) {
		$ability = $this->get_ability();

		$result = $ability->execute( array(
			'action' => 'list',
			'limit'  => absint( $assoc_args['limit'] ?? 25 ),
			'after'  => $assoc_args['after'] ?? '',
		) );

		if ( is_wp_error( $result ) || ! $result['success'] ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : $result['error'] );
		}

		$data   = $result['data'];
		$posts  = $data['posts'] ?? array();
		$format = $assoc_args['format'] ?? 'table';

		if ( empty( $posts ) ) {
			WP_CLI::warning( 'No posts found.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( "Found {$data['count']} posts" );
		WP_CLI::log( '' );

		foreach ( $posts as $item ) {
			$message = mb_substr( $item['message'] ?? '(no message)', 0, 60 );
			if ( mb_strlen( $item['message'] ?? '' ) > 60 ) {
				$message .= '...';
			}
			$likes    = $item['likes']['summary']['total_count'] ?? 0;
			$comments = $item['comments']['summary']['total_count'] ?? 0;
			$shares   = $item['shares']['count'] ?? 0;
			$date     = isset( $item['created_time'] ) ? wp_date( 'Y-m-d', strtotime( $item['created_time'] ) ) : '';

			WP_CLI::log( sprintf(
				'  %s  %s  %d likes  %d comments  %d shares  %s',
				$item['id'],
				$date,
				$likes,
				$comments,
				$shares,
				$message
			) );
		}

		if ( $data['has_next'] && ! empty( $data['cursors']['after'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( "Next page: --after={$data['cursors']['after']}" );
		}
	}

	/**
	 * Get details for a specific Facebook post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The Facebook post ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 */
	public function post( $args, $assoc_args ) {
		$post_id = $args[0];
		$ability = $this->get_ability();

		$result = $ability->execute( array(
			'action'  => 'get',
			'post_id' => $post_id,
		) );

		if ( is_wp_error( $result ) || ! $result['success'] ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : $result['error'] );
		}

		$data   = $result['data'];
		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( "Post {$post_id}" );
		WP_CLI::log( '' );
		WP_CLI::log( 'ID:        ' . ( $data['id'] ?? '' ) );
		WP_CLI::log( 'Date:      ' . ( $data['created_time'] ?? '' ) );
		WP_CLI::log( 'Likes:     ' . ( $data['likes']['summary']['total_count'] ?? 0 ) );
		WP_CLI::log( 'Comments:  ' . ( $data['comments']['summary']['total_count'] ?? 0 ) );
		WP_CLI::log( 'Shares:    ' . ( $data['shares']['count'] ?? 0 ) );
		WP_CLI::log( 'Permalink: ' . ( $data['permalink_url'] ?? '' ) );
		WP_CLI::log( '' );
		WP_CLI::log( 'Message:' );
		WP_CLI::log( $data['message'] ?? '(no message)' );
	}

	/**
	 * Get comments on a Facebook post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The Facebook post ID.
	 *
	 * [--limit=<limit>]
	 * : Number of comments to return.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 */
	public function comments( $args, $assoc_args ) {
		$post_id = $args[0];
		$ability = $this->get_ability();

		$result = $ability->execute( array(
			'action'  => 'comments',
			'post_id' => $post_id,
			'limit'   => absint( $assoc_args['limit'] ?? 25 ),
		) );

		if ( is_wp_error( $result ) || ! $result['success'] ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : $result['error'] );
		}

		$data     = $result['data'];
		$comments = $data['comments'] ?? array();
		$format   = $assoc_args['format'] ?? 'table';

		if ( empty( $comments ) ) {
			WP_CLI::warning( 'No comments found.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( "Found {$data['count']} comments" );
		WP_CLI::log( '' );

		foreach ( $comments as $comment ) {
			$from = $comment['from']['name'] ?? 'unknown';
			$text = $comment['message'] ?? '';
			$date = isset( $comment['created_time'] ) ? wp_date( 'Y-m-d H:i', strtotime( $comment['created_time'] ) ) : '';

			WP_CLI::log( sprintf( '  %-20s %s  %s', $from, $date, $text ) );
		}
	}

	/**
	 * Reply to a Facebook Page comment.
	 *
	 * ## OPTIONS
	 *
	 * <comment_id>
	 * : The Facebook comment ID to reply to.
	 *
	 * <message>
	 * : The reply text.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials facebook reply-comment 987654321 "Thanks for listening!"
	 */
	public function reply_comment( $args ) {
		$comment_id = $args[0] ?? '';
		$message    = $args[1] ?? '';
		$ability    = $this->get_comment_reply_ability();

		if ( empty( $comment_id ) ) {
			WP_CLI::error( 'Comment ID is required.' );
		}

		if ( empty( $message ) ) {
			WP_CLI::error( 'Reply message is required.' );
		}

		$result = $ability->execute(
			array(
				'comment_id' => $comment_id,
				'message'    => $message,
			)
		);

		if ( is_wp_error( $result ) || ! $result['success'] ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : $result['error'] );
		}

		WP_CLI::success( 'Facebook comment reply posted successfully!' );
		WP_CLI::log( 'Comment ID: ' . ( $result['data']['comment_id'] ?? $comment_id ) );
		WP_CLI::log( 'Reply ID:   ' . ( $result['data']['reply_id'] ?? '' ) );
	}

	/**
	 * Show Facebook authentication status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials facebook status
	 */
	public function status( $args, $assoc_args ) {
		$args;
		$assoc_args;
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'facebook' );

		WP_CLI::log( 'Facebook Integration Status' );
		WP_CLI::log( '---' );

		if ( ! $provider ) {
			WP_CLI::log( 'Provider:      Not found' );
			return;
		}

		$authenticated = $provider->is_authenticated();
		WP_CLI::log( 'Authenticated: ' . ( $authenticated ? 'Yes' : 'No' ) );

		if ( method_exists( $provider, 'get_page_id' ) ) {
			$page_id = $provider->get_page_id();
			if ( $page_id ) {
				WP_CLI::log( 'Page ID:       ' . $page_id );
			}
		}

		$details = $provider->get_account_details();
		if ( $details ) {
			$username = $provider->get_username();
			if ( $username ) {
				WP_CLI::log( 'Page:          ' . $username );
			}
		}

		$next_cron = wp_next_scheduled( 'datamachine_refresh_token_facebook' );
		if ( $next_cron ) {
			WP_CLI::log( 'Next cron:     ' . wp_date( 'Y-m-d H:i:s', $next_cron ) );
		}
	}

	/**
	 * Publish a post to Facebook.
	 *
	 * Posts content to a Facebook Page with optional image and source URL.
	 *
	 * ## OPTIONS
	 *
	 * <content>
	 * : The post content text.
	 *
	 * [--title=<title>]
	 * : Optional title to prepend to the post.
	 *
	 * [--image=<url>]
	 * : Image URL to attach to the post.
	 *
	 * [--source-url=<url>]
	 * : Source URL to include in the post.
	 *
	 * [--link-handling=<handling>]
	 * : How to handle the source URL: none, append (default), or comment.
	 * ---
	 * default: append
	 * options:
	 *   - none
	 *   - append
	 *   - comment
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Simple text post
	 *     wp datamachine-socials facebook publish "Check out our latest article!"
	 *
	 *     # Post with image
	 *     wp datamachine-socials facebook publish "New photo from the show" --image=https://example.com/photo.jpg
	 *
	 *     # Post with source URL as comment
	 *     wp datamachine-socials facebook publish "Great read" --source-url=https://extrachill.com/article --link-handling=comment
	 */
	public function publish( $args, $assoc_args ) {
		$content = $args[0] ?? '';

		if ( empty( $content ) ) {
			WP_CLI::error( 'Post content is required.' );
		}

		$this->get_publish_ability();

		$input = array( 'content' => $content );

		if ( ! empty( $assoc_args['title'] ) ) {
			$input['title'] = $assoc_args['title'];
		}

		if ( ! empty( $assoc_args['image'] ) ) {
			$input['image_url'] = $assoc_args['image'];
			WP_CLI::log( 'Publishing to Facebook with image...' );
		} else {
			WP_CLI::log( 'Publishing to Facebook...' );
		}

		if ( ! empty( $assoc_args['source-url'] ) ) {
			$input['source_url'] = $assoc_args['source-url'];
		}

		if ( ! empty( $assoc_args['link-handling'] ) ) {
			$input['link_handling'] = $assoc_args['link-handling'];
		}

		$result = \DataMachineSocials\Abilities\Facebook\FacebookPublishAbility::execute_publish( $input );

		if ( is_wp_error( $result ) || ! $result['success'] ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : $result['error'] );
		}

		WP_CLI::success( 'Published to Facebook!' );
		WP_CLI::log( 'Post ID:  ' . ( $result['post_id'] ?? '' ) );
		WP_CLI::log( 'Post URL: ' . ( $result['post_url'] ?? '' ) );

		if ( ! empty( $result['comment_id'] ) ) {
			WP_CLI::log( 'Comment ID:  ' . $result['comment_id'] );
			WP_CLI::log( 'Comment URL: ' . ( $result['comment_url'] ?? '' ) );
		}
	}

	private function get_ability() {
		$ability = wp_get_ability( 'datamachine/facebook-read' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/facebook-read ability not registered.' );
		}

		return $ability;
	}

	private function get_update_ability() {
		$ability = wp_get_ability( 'datamachine/facebook-update' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/facebook-update ability not registered.' );
		}

		return $ability;
	}

	private function get_delete_ability() {
		$ability = wp_get_ability( 'datamachine/facebook-delete' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/facebook-delete ability not registered.' );
		}

		return $ability;
	}

	private function get_publish_ability() {
		$ability = wp_get_ability( 'datamachine/facebook-publish' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/facebook-publish ability not registered.' );
		}

		return $ability;
	}

	/**
	 * Get the Facebook comment reply ability.
	 *
	 * @return \DataMachineSocials\Abilities\Facebook\FacebookCommentReplyAbility
	 */
	private function get_comment_reply_ability() {
		$ability = wp_get_ability( 'datamachine/facebook-comment-reply' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/facebook-comment-reply ability not registered.' );
		}

		return $ability;
	}

	/**
	 * Edit a Facebook post message.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The Facebook post ID.
	 *
	 * <message>
	 * : New message text.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials facebook edit 123456789 "New message"
	 */
	public function edit( $args, $assoc_args ) {
		$assoc_args;
		$post_id = $args[0];
		$message = $args[1] ?? '';
		$ability = $this->get_update_ability();

		if ( empty( $message ) ) {
			WP_CLI::error( 'Message is required.' );
		}

		$result = $ability->execute( array(
			'action'  => 'edit',
			'post_id' => $post_id,
			'message' => $message,
		) );

		if ( is_wp_error( $result ) || ! $result['success'] ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : $result['error'] );
		}

		WP_CLI::success( 'Post updated successfully!' );
		WP_CLI::log( 'Post ID: ' . $result['data']['post_id'] );
	}

	/**
	 * Hide a Facebook post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The Facebook post ID to hide.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials facebook hide 123456789
	 */
	public function hide( $args, $assoc_args ) {
		$assoc_args;
		$post_id = $args[0];
		$ability = $this->get_update_ability();

		$result = $ability->execute( array(
			'action'  => 'hide',
			'post_id' => $post_id,
		) );

		if ( is_wp_error( $result ) || ! $result['success'] ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : $result['error'] );
		}

		WP_CLI::success( 'Post hidden successfully!' );
	}

	/**
	 * Delete a Facebook post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The Facebook post ID to delete.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials facebook delete 123456789
	 */
	public function delete( $args, $assoc_args ) {
		$assoc_args;
		$post_id = $args[0];
		$ability = $this->get_delete_ability();

		$result = $ability->execute( array(
			'post_id' => $post_id,
		) );

		if ( is_wp_error( $result ) || ! $result['success'] ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : $result['error'] );
		}

		WP_CLI::success( 'Post deleted successfully!' );
		WP_CLI::log( 'Post ID: ' . $result['data']['post_id'] );
	}

	/**
	 * Print the opt-in authorization URL that adds paid-promotion permissions.
	 *
	 * Open it in a browser while logged in as a site admin. The default Page
	 * connection never requests ads access; this is the only way to grant it.
	 *
	 * @subcommand ads-connect
	 */
	public function ads_connect( $args, $assoc_args ) {
		$auth = ( new AuthAbilities() )->getProvider( 'facebook' );
		if ( ! $auth instanceof \DataMachineSocials\Handlers\Facebook\FacebookAuth ) {
			WP_CLI::error( 'Facebook auth provider not available.' );
		}
		WP_CLI::log( 'Approve ads access (expires in 15 minutes):' );
		WP_CLI::log( $auth->get_ads_authorization_url() );
	}

	/**
	 * Show or set the ad account and hard spend limits.
	 *
	 * ## OPTIONS
	 *
	 * [--account=<act_id>]
	 * : Ad account to promote from (act_<id> or numeric id).
	 *
	 * [--max-budget=<amount>]
	 * : Hard cap on one promotion's lifetime budget, in account currency.
	 *
	 * [--max-days=<days>]
	 * : Hard cap on one promotion's duration.
	 *
	 * @subcommand ads-config
	 */
	public function ads_config( $args, $assoc_args ) {
		$auth = \DataMachineSocials\Handlers\Facebook\FacebookGraphClient::auth();
		if ( ! $auth ) {
			WP_CLI::error( 'Facebook auth provider not available.' );
		}

		if ( isset( $assoc_args['account'] ) && ! $auth->set_ad_account_id( (string) $assoc_args['account'] ) ) {
			WP_CLI::error( 'Invalid ad account ID. Use act_<digits> or the numeric ID.' );
		}

		$changes = array();
		if ( isset( $assoc_args['max-budget'] ) ) {
			$changes['max_lifetime_budget'] = $assoc_args['max-budget'];
		}
		if ( isset( $assoc_args['max-days'] ) ) {
			$changes['max_duration_days'] = $assoc_args['max-days'];
		}
		$limits = $changes ? \DataMachineSocials\Abilities\Facebook\FacebookAdsPolicy::save_limits( $changes ) : \DataMachineSocials\Abilities\Facebook\FacebookAdsPolicy::limits();
		if ( is_wp_error( $limits ) ) {
			WP_CLI::error( $limits->get_error_message() );
		}

		$account = $auth->get_ad_account_id();
		WP_CLI::log( 'Ad account:      ' . ( '' !== $account ? $account : '(none)' ) );
		WP_CLI::log( 'Max budget:      ' . $limits['max_lifetime_budget'] . ' per promotion' );
		WP_CLI::log( 'Max duration:    ' . $limits['max_duration_days'] . ' days' );
		WP_CLI::log( 'Ads permission:  ' . ( $auth->has_ads_permission() ? 'granted' : 'not granted (run ads-connect)' ) );
	}

	/**
	 * List ad accounts available to the connected Facebook user.
	 *
	 * @subcommand ad-accounts
	 */
	public function ad_accounts( $args, $assoc_args ) {
		$result = $this->run_ads_ability( 'datamachine/facebook-ad-accounts', array() );
		foreach ( $result['accounts'] as $account ) {
			WP_CLI::log( sprintf( '  %s  %s  %s  status=%s%s', $account['id'] ?? '', $account['currency'] ?? '', $account['name'] ?? '', $account['account_status'] ?? '', ( $account['id'] ?? '' ) === $result['selected'] ? '  (selected)' : '' ) );
		}
	}

	/**
	 * Search Meta ad targeting interests.
	 *
	 * ## OPTIONS
	 *
	 * <query>
	 * : Interest keyword.
	 *
	 * @subcommand ads-interests
	 */
	public function ads_interests( $args, $assoc_args ) {
		$result = $this->run_ads_ability( 'datamachine/facebook-ads-interests', array( 'query' => $args[0] ) );
		foreach ( $result['interests'] as $interest ) {
			WP_CLI::log( sprintf( '  %s  %s  (~%s people)', $interest['id'], $interest['name'], $interest['audience_size'] ?? '?' ) );
		}
	}

	/**
	 * Stage a paused, budget-capped promotion for human approval.
	 *
	 * Creates the campaign, ad set and ad PAUSED and files a pending action.
	 * Nothing spends until an administrator runs
	 * `wp datamachine pending-actions accept <action_id>`.
	 *
	 * ## OPTIONS
	 *
	 * --budget=<amount>
	 * : Lifetime budget in account currency (hard-capped).
	 *
	 * --end=<datetime>
	 * : When the promotion stops.
	 *
	 * [--start=<datetime>]
	 * : When it starts (default: 15 minutes from now).
	 *
	 * --lat=<latitude>
	 * : Center of the audience radius.
	 *
	 * --lng=<longitude>
	 * : Center of the audience radius.
	 *
	 * --radius=<miles>
	 * : Audience radius in miles (1-50).
	 *
	 * --link=<url>
	 * : Destination URL (https).
	 *
	 * [--name=<name>]
	 * : Campaign name.
	 *
	 * [--age-min=<age>]
	 * : Minimum age (default 21).
	 *
	 * [--age-max=<age>]
	 * : Maximum age (default 65).
	 *
	 * [--interests=<list>]
	 * : Comma-separated exact interest names or numeric IDs (see ads-interests).
	 *
	 * [--post=<post_id>]
	 * : Promote an existing Page post instead of building a link ad.
	 *
	 * [--ig-post=<media_id>]
	 * : Boost an existing Instagram post (numeric media ID from
	 *   `wp datamachine-socials instagram posts`). Defaults to Instagram placements only.
	 *
	 * [--platforms=<list>]
	 * : Comma-separated placements: facebook, instagram (default both).
	 *
	 * [--message=<text>]
	 * : Ad text (link ad).
	 *
	 * [--headline=<text>]
	 * : Ad headline (link ad).
	 *
	 * [--image=<path>]
	 * : Local image for the link ad.
	 *
	 * [--image-url=<url>]
	 * : Public image URL for the link ad.
	 */
	public function promote( $args, $assoc_args ) {
		$input = array(
			'name'               => $assoc_args['name'] ?? '',
			'budget'             => (float) $assoc_args['budget'],
			'start_time'         => $assoc_args['start'] ?? '',
			'end_time'           => $assoc_args['end'],
			'latitude'           => (float) $assoc_args['lat'],
			'longitude'          => (float) $assoc_args['lng'],
			'radius_miles'       => (int) $assoc_args['radius'],
			'age_min'            => (int) ( $assoc_args['age-min'] ?? 21 ),
			'age_max'            => (int) ( $assoc_args['age-max'] ?? 65 ),
			'interests'          => isset( $assoc_args['interests'] ) ? array_map( 'trim', explode( ',', $assoc_args['interests'] ) ) : array(),
			'link'               => $assoc_args['link'],
			'post_id'            => $assoc_args['post'] ?? '',
			'instagram_media_id' => $assoc_args['ig-post'] ?? '',
			'platforms'          => isset( $assoc_args['platforms'] ) ? explode( ',', $assoc_args['platforms'] ) : array(),
			'message'            => $assoc_args['message'] ?? '',
			'headline'           => $assoc_args['headline'] ?? '',
			'image_path'         => $assoc_args['image'] ?? '',
			'image_url'          => $assoc_args['image-url'] ?? '',
		);

		$result    = $this->run_ads_ability( 'datamachine/facebook-ads-promote', $input );
		$action_id = (string) ( $result['action_id'] ?? '' );

		WP_CLI::success( 'Promotion created PAUSED. Nothing will spend until it is approved.' );
		WP_CLI::log( wp_json_encode( $result['preview'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		WP_CLI::log( '' );
		if ( ! empty( $result['links']['approve_url'] ) ) {
			WP_CLI::log( 'Approve link: ' . $result['links']['approve_url'] );
			WP_CLI::log( 'Reject link:  ' . $result['links']['reject_url'] );
			WP_CLI::log( 'Links expire: ' . $result['links']['expires_at'] );
			WP_CLI::log( '' );
		}
		WP_CLI::log( 'Approve: wp datamachine pending-actions accept ' . $action_id );
		WP_CLI::log( 'Reject:  wp datamachine pending-actions reject ' . $action_id . '   (deletes the paused promotion)' );
	}

	/**
	 * Stage an edit to a live promotion for human approval.
	 *
	 * Nothing changes until the edit is approved (signed link or
	 * `wp datamachine pending-actions accept <id>`). Edited values must
	 * still satisfy the hard limits.
	 *
	 * ## OPTIONS
	 *
	 * <campaign_id>
	 * : Campaign to edit (from ads-report).
	 *
	 * [--budget=<amount>]
	 * : New lifetime budget (must exceed what is already spent).
	 *
	 * [--end=<datetime>]
	 * : New end date.
	 *
	 * [--radius=<miles>]
	 * : New audience radius.
	 *
	 * [--lat=<latitude>]
	 * : New center latitude.
	 *
	 * [--lng=<longitude>]
	 * : New center longitude.
	 *
	 * [--age-min=<age>]
	 * : New minimum age.
	 *
	 * [--age-max=<age>]
	 * : New maximum age.
	 *
	 * [--interests=<list>]
	 * : Replace all interests (comma-separated exact names or numeric IDs).
	 *
	 * [--add-interests=<list>]
	 * : Add interests (exact names or numeric IDs).
	 *
	 * [--remove-interests=<list>]
	 * : Remove interests by exact name or ID.
	 *
	 * @subcommand ads-update
	 */
	public function ads_update( $args, $assoc_args ) {
		$split = static fn( $key ) => isset( $assoc_args[ $key ] ) ? array_map( 'trim', explode( ',', (string) $assoc_args[ $key ] ) ) : array();
		$input = array_filter(
			array(
				'campaign_id'  => $args[0],
				'budget'       => $assoc_args['budget'] ?? null,
				'end_time'     => $assoc_args['end'] ?? null,
				'radius_miles' => $assoc_args['radius'] ?? null,
				'latitude'     => $assoc_args['lat'] ?? null,
				'longitude'    => $assoc_args['lng'] ?? null,
				'age_min'      => $assoc_args['age-min'] ?? null,
				'age_max'      => $assoc_args['age-max'] ?? null,
			),
			static fn( $v ) => null !== $v
		) + array(
			'interests'        => $split( 'interests' ),
			'add_interests'    => $split( 'add-interests' ),
			'remove_interests' => $split( 'remove-interests' ),
		);

			$result    = $this->run_ads_ability( 'datamachine/facebook-ads-update', $input );
			$action_id = (string) ( $result['action_id'] ?? '' );

			WP_CLI::success( 'Edit staged. Nothing changes until it is approved.' );
			WP_CLI::log( wp_json_encode( $result['preview'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			WP_CLI::log( '' );
			if ( ! empty( $result['links']['approve_url'] ) ) {
				WP_CLI::log( 'Approve link: ' . $result['links']['approve_url'] );
				WP_CLI::log( 'Reject link:  ' . $result['links']['reject_url'] );
				WP_CLI::log( '' );
			}
			WP_CLI::log( 'Approve: wp datamachine pending-actions accept ' . $action_id );
			WP_CLI::log( 'Reject:  wp datamachine pending-actions reject ' . $action_id . '   (the promotion keeps running unchanged)' );
	}

	/**
	 * Spend, reach and link clicks for promotions.
	 *
	 * ## OPTIONS
	 *
	 * [<campaign_id>]
	 * : One campaign (default: all in the configured ad account).
	 *
	 * @subcommand ads-report
	 */
	public function ads_report( $args, $assoc_args ) {
		$result = $this->run_ads_ability( 'datamachine/facebook-ads-report', array( 'campaign_id' => $args[0] ?? '' ) );
		foreach ( $result['campaigns'] as $campaign ) {
			$i = $campaign['insights']['data'][0] ?? array();
			WP_CLI::log( sprintf( '  %s  %-8s  spend=%s reach=%s clicks=%s  %s', $campaign['id'] ?? '', $campaign['effective_status'] ?? '', $i['spend'] ?? '0', $i['reach'] ?? '0', $i['inline_link_clicks'] ?? '0', $campaign['name'] ?? '' ) );
			if ( empty( $args[0] ) ) {
				continue;
			}
			WP_CLI::log( sprintf( '    objective=%s  start=%s  stop=%s  cpc=%s', $campaign['objective'] ?? '', $campaign['start_time'] ?? '', $campaign['stop_time'] ?? '', $i['cpc'] ?? '' ) );
			foreach ( (array) ( $campaign['adsets']['data'] ?? array() ) as $adset ) {
				$t = (array) ( $adset['targeting_summary'] ?? array() );
				WP_CLI::log( sprintf( '    budget=%s (%s) remaining=%s  goal=%s  destination=%s  ages=%s-%s', $adset['budget'] ?? '', $adset['budget_type'] ?? '', $adset['remaining_budget'] ?? 'n/a', $adset['optimization_goal'] ?? '', $adset['destination_type'] ?? '', $t['age_min'] ?? '', $t['age_max'] ?? '' ) );
				WP_CLI::log( '    interests=' . implode( ', ', (array) ( $t['interests'] ?? array() ) ) );
			}
			foreach ( (array) ( $campaign['ads']['data'] ?? array() ) as $ad ) {
				WP_CLI::log( sprintf( '    ad %s -> %s  (source %s)', $ad['id'] ?? '', $ad['destination_url'] ?? 'n/a', $ad['source_post']['instagram_permalink_url'] ?? 'n/a' ) );
			}
		}
	}

	/**
	 * Execute an ads ability or exit with its error.
	 *
	 * @param string $slug  Ability slug.
	 * @param array  $input Ability input.
	 * @return array
	 */
	private function run_ads_ability( string $slug, array $input ): array {
		$ability = wp_get_ability( $slug );
		if ( ! $ability ) {
			WP_CLI::error( $slug . ' ability not registered.' );
		}
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		return (array) $result;
	}
}
