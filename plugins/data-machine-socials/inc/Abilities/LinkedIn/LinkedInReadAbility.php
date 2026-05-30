<?php
/**
 * LinkedIn Read Ability
 *
 * Abilities API primitive for reading posts via LinkedIn Posts API.
 * Supports listing author posts and fetching individual posts by URN.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\LinkedIn
 * @since      0.5.0
 */

namespace DataMachineSocials\Abilities\LinkedIn;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachineSocials\Handlers\LinkedIn\LinkedInAuth;
use DataMachineSocials\Abilities\AbstractSocialAbility;

defined( 'ABSPATH' ) || exit;

class LinkedInReadAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/linkedin-read',
				array(
					'label'               => __( 'Read LinkedIn Posts', 'data-machine-socials' ),
					'description'         => __( 'List recent posts or get a specific post from LinkedIn', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'  => array(
								'type'        => 'string',
								'enum'        => array( 'list', 'get' ),
								'default'     => 'list',
								'description' => __( 'Action: list (author posts) or get (single post by URN)', 'data-machine-socials' ),
							),
							'post_id' => array(
								'type'        => 'string',
								'description' => __( 'Post URN (required for get action)', 'data-machine-socials' ),
							),
							'limit'   => array(
								'type'        => 'integer',
								'default'     => 10,
								'description' => __( 'Number of posts to return (max 100)', 'data-machine-socials' ),
							),
							'start'   => array(
								'type'        => 'integer',
								'default'     => 0,
								'description' => __( 'Pagination start index', 'data-machine-socials' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'data'    => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};
	}

	public function checkPermission(): bool {
		return PermissionHelper::can( 'use_tools' );
	}

	public function execute( array $input ): array|\WP_Error {
		$action = $input['action'] ?? 'list';

		$provider = $this->getAuthProvider();
		if ( ! $provider ) {
			return new \WP_Error( 'missing_auth', 'LinkedIn auth provider not available', array( 'status' => 401 ) );
		}

		if ( ! $provider->is_authenticated() ) {
			return new \WP_Error( 'missing_auth', 'LinkedIn not authenticated', array( 'status' => 401 ) );
		}

		switch ( $action ) {
			case 'list':
				return $this->listPosts( $provider, $input );

			case 'get':
				if ( empty( $input['post_id'] ) ) {
					return new \WP_Error( 'missing_param', 'post_id is required for the get action', array( 'status' => 400 ) );
				}
				return $this->getPost( $provider, $input['post_id'] );

			default:
				return new \WP_Error( 'api_error', "Unknown action: {$action}. Use list or get.", array( 'status' => 500 ) );
		}
	}

	/**
	 * List posts by the authenticated author.
	 *
	 * @param LinkedInAuth $provider Auth provider.
	 * @param array        $input    Input parameters.
	 * @return array Response.
	 */
	private function listPosts( LinkedInAuth $provider, array $input ): array|\WP_Error {
		$person_urn = $provider->get_person_urn();
		if ( empty( $person_urn ) ) {
			return new \WP_Error( 'missing_auth', 'LinkedIn person URN not available. Try re-authenticating.', array( 'status' => 401 ) );
		}

		$count = min( absint( $input['limit'] ?? 10 ), 100 );
		$start = absint( $input['start'] ?? 0 );

		$encoded_urn = rawurlencode( $person_urn );
		$url         = LinkedInAuth::API_BASE . "/rest/posts?author={$encoded_urn}&q=author&count={$count}&start={$start}&sortBy=LAST_MODIFIED";

		$result = $provider->api_request(
			'GET',
			$url,
			array(
				'headers' => array( 'X-RestLi-Method' => 'FINDER' ),
				'context' => 'LinkedIn Read Posts',
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error( 'api_error', $result['error'] ?? 'Failed to fetch LinkedIn posts', array( 'status' => 500 ) );
		}

		$data     = json_decode( $result['data'], true );
		$elements = $data['elements'] ?? array();
		$paging   = $data['paging'] ?? array();

		$posts = array_map(
			function ( $post ) {
				return array(
					'id'             => $post['id'] ?? '',
					'commentary'     => $post['commentary'] ?? '',
					'visibility'     => $post['visibility'] ?? '',
					'lifecycleState' => $post['lifecycleState'] ?? '',
					'createdAt'      => $post['createdAt'] ?? null,
					'lastModifiedAt' => $post['lastModifiedAt'] ?? null,
					'content'        => $post['content'] ?? null,
				);
			},
			$elements
		);

		return array(
			'success' => true,
			'data'    => array(
				'posts'    => $posts,
				'count'    => count( $posts ),
				'start'    => $paging['start'] ?? $start,
				'has_next' => ! empty( $paging['links'] ),
			),
		);
	}

	/**
	 * Get a single post by URN.
	 *
	 * @param LinkedInAuth $provider Auth provider.
	 * @param string       $post_id  Post URN.
	 * @return array Response.
	 */
	private function getPost( LinkedInAuth $provider, string $post_id ): array|\WP_Error {
		$encoded_id = rawurlencode( $post_id );
		$url        = LinkedInAuth::API_BASE . "/rest/posts/{$encoded_id}";

		$result = $provider->api_request(
			'GET',
			$url,
			array( 'context' => 'LinkedIn Get Post' )
		);

		if ( ! $result['success'] ) {
			return new \WP_Error( 'api_error', $result['error'] ?? 'Failed to fetch LinkedIn post', array( 'status' => 500 ) );
		}

		$data = json_decode( $result['data'], true );

		return array(
			'success' => true,
			'data'    => array(
				'id'             => $data['id'] ?? '',
				'commentary'     => $data['commentary'] ?? '',
				'visibility'     => $data['visibility'] ?? '',
				'lifecycleState' => $data['lifecycleState'] ?? '',
				'createdAt'      => $data['createdAt'] ?? null,
				'lastModifiedAt' => $data['lastModifiedAt'] ?? null,
				'author'         => $data['author'] ?? '',
				'content'        => $data['content'] ?? null,
			),
		);
	}

	private function getAuthProvider(): ?LinkedInAuth {
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'linkedin' );

		if ( $provider instanceof LinkedInAuth ) {
			return $provider;
		}

		return null;
	}
}
