<?php
/**
* Pinterest Publish Handler Settings
*
* Defines settings fields for Pinterest publish handler.
* Extends base publish handler settings with Pinterest-specific board configuration.
*
* @package DataMachineSocials\Handlers\Pinterest
* @since 0.3.0
*/

namespace DataMachineSocials\Handlers\Pinterest;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;

defined( 'ABSPATH' ) || exit;

/**
* Pinterest Settings Handler
*
* Provides settings fields for the Pinterest publish handler,
* including default board ID configuration.
*/
class PinterestSettings extends PublishHandlerSettings {

	/**
	* Get settings fields for Pinterest publish handler.
	*
	* @return array Associative array defining the settings fields.
	*/
	public static function get_fields(): array {
		return array_merge(
			parent::get_common_fields(),
			array(
				'board_id'             => array(
					'type'        => 'text',
					'label'       => __( 'Default Board ID', 'data-machine-socials' ),
					'description' => __( 'Pinterest board ID to pin to. Find in board URL.', 'data-machine-socials' ),
					'default'     => '',
				),
				'board_selection_mode' => array(
					'type'        => 'select',
					'label'       => __( 'Board Selection Mode', 'data-machine-socials' ),
					'description' => __( 'How to choose which Pinterest board to pin to.', 'data-machine-socials' ),
					'default'     => 'pre_selected',
					'options'     => array(
						'pre_selected'     => __( 'Pre-selected (use default board)', 'data-machine-socials' ),
						'ai_decides'       => __( 'AI Decides (agent picks from available boards)', 'data-machine-socials' ),
						'category_mapping' => __( 'Category Mapping (route by WordPress category)', 'data-machine-socials' ),
					),
				),
				'board_mapping'        => array(
					'type'        => 'textarea',
					'label'       => __( 'Category → Board Mapping', 'data-machine-socials' ),
					'description' => __( 'One mapping per line: category_slug=board_id (e.g., spirituality=123456789). Used when mode is Category Mapping.', 'data-machine-socials' ),
					'default'     => '',
				),
			)
		);
	}

	public function __construct() {
		parent::__construct( 'pinterest' );

		self::registerHandler(
			'pinterest_publish',
			'publish',
			self::class,
			'Pinterest',
			'Pin content to Pinterest with image and link back to source',
			true,
			PinterestAuth::class,
			self::class,
			function ( $tools, $handler_slug, $handler_config ) {
				if ( 'pinterest_publish' === $handler_slug ) {
					$board_id_description = 'Pinterest board ID override (uses default if omitted)';

					// Inject cached board names when AI decides mode is active.
					$mode = $handler_config['board_selection_mode'] ?? 'pre_selected';
					if ( 'ai_decides' === $mode ) {
						$cached_boards = PinterestBoardsAbility::get_cached_boards();
						if ( ! empty( $cached_boards ) ) {
							$board_list           = implode( ', ', array_map( function ( $b ) {
								return $b['name'] . ' (' . $b['id'] . ')';
							}, $cached_boards ) );
							$board_id_description = "Pinterest board ID. Available boards: {$board_list}";
						}
					}

					$tools['pinterest_publish'] = array(
						'class'       => self::class,
						'method'      => 'handle_tool_call',
						'handler'     => 'pinterest_publish',
						'description' => 'Pin content to Pinterest with image, title, description, and link.',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(
								'title'       => array(
									'type'        => 'string',
									'description' => 'Pin title (max 100 characters)',
								),
								'description' => array(
									'type'        => 'string',
									'description' => 'Pin description (max 500 characters)',
								),
								'board_id'    => array(
									'type'        => 'string',
									'description' => $board_id_description,
								),
							),
							'required'   => array( 'title', 'description' ),
						),
					);
				}
				return $tools;
			},
			'pinterest',
			array(
				'charLimit'          => 500,
				'maxImages'          => 1,
				'aspectRatios'       => array( '2:3' ),
				'defaultAspectRatio' => '2:3',
				'supportsCarousel'   => false,
				'capabilities'       => array(
					array(
						'slug'  => 'publish',
						'label' => 'Publish',
					),
				),
			)
		);
	}
}
