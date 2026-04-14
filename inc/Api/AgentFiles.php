<?php
/**
 * REST API Agent Files Endpoint
 *
 * Routes for agent memory files and daily memory.
 * Delegates to AgentFileAbilities and DailyMemoryAbilities.
 *
 * @package DataMachine\Api
 * @since   0.38.0
 */

namespace DataMachine\Api;

use DataMachine\Abilities\DailyMemoryAbilities;
use DataMachine\Abilities\File\AgentFileAbilities;
use DataMachine\Abilities\File\FileConstants;
use DataMachine\Abilities\PermissionHelper;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AgentFiles {

	private static ?AgentFileAbilities $abilities = null;

	private static function getAbilities(): AgentFileAbilities {
		if ( null === self::$abilities ) {
			self::$abilities = new AgentFileAbilities();
		}
		return self::$abilities;
	}

	/**
	 * Register REST API routes.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register /datamachine/v1/files/agent endpoints.
	 */
	public static function register_routes() {
		$user_id_arg = array(
			'required'          => false,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
		);

		$filename_arg = array(
			'required'          => true,
			'type'              => 'string',
			'sanitize_callback' => function ( $param ) {
				return sanitize_file_name( $param );
			},
		);

		// GET /files/agent — List agent files.
		register_rest_route(
			'datamachine/v1',
			'/files/agent',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'list_agent_files' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'user_id' => $user_id_arg,
				),
			)
		);

		// GET /files/agent/{filename} — Get agent file content.
		register_rest_route(
			'datamachine/v1',
			'/files/agent/(?P<filename>[^/]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_agent_file' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'filename' => $filename_arg,
					'user_id'  => $user_id_arg,
				),
			)
		);

		// PUT /files/agent/{filename} — Write/update agent file content.
		register_rest_route(
			'datamachine/v1',
			'/files/agent/(?P<filename>[^/]+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( self::class, 'put_agent_file' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'filename' => $filename_arg,
					'user_id'  => $user_id_arg,
				),
			)
		);

		// DELETE /files/agent/{filename} — Delete agent file.
		register_rest_route(
			'datamachine/v1',
			'/files/agent/(?P<filename>[^/]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( self::class, 'delete_agent_file' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'filename' => $filename_arg,
					'user_id'  => $user_id_arg,
				),
			)
		);

		// Context memory file routes.
		$context_slug_arg = array(
			'required'          => true,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_title',
			'validate_callback' => function ( $value ) {
				return (bool) preg_match( '/^[a-z0-9][a-z0-9-]*$/', $value );
			},
		);

		// GET /files/agent/contexts — List context memory files.
		register_rest_route(
			'datamachine/v1',
			'/files/agent/contexts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'list_context_files' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array( 'user_id' => $user_id_arg ),
			)
		);

		// GET /files/agent/contexts/{slug} — Read a context file.
		register_rest_route(
			'datamachine/v1',
			'/files/agent/contexts/(?P<context_slug>[a-z0-9][a-z0-9-]*)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_context_file' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'context_slug' => $context_slug_arg,
					'user_id'      => $user_id_arg,
				),
			)
		);

		// PUT /files/agent/contexts/{slug} — Create or update a context file.
		register_rest_route(
			'datamachine/v1',
			'/files/agent/contexts/(?P<context_slug>[a-z0-9][a-z0-9-]*)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( self::class, 'put_context_file' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'context_slug' => $context_slug_arg,
					'user_id'      => $user_id_arg,
				),
			)
		);

		// DELETE /files/agent/contexts/{slug} — Delete a context file.
		register_rest_route(
			'datamachine/v1',
			'/files/agent/contexts/(?P<context_slug>[a-z0-9][a-z0-9-]*)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( self::class, 'delete_context_file' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'context_slug' => $context_slug_arg,
					'user_id'      => $user_id_arg,
				),
			)
		);

		// Daily memory file routes.
		$daily_date_args = array(
			'user_id' => $user_id_arg,
			'year'    => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => function ( $value ) {
					return (bool) preg_match( '/^\d{4}$/', $value );
				},
			),
			'month'   => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => function ( $value ) {
					return (bool) preg_match( '/^\d{2}$/', $value ) && (int) $value >= 1 && (int) $value <= 12;
				},
			),
			'day'     => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => function ( $value ) {
					return (bool) preg_match( '/^\d{2}$/', $value ) && (int) $value >= 1 && (int) $value <= 31;
				},
			),
		);

		// GET /files/agent/daily — List daily memory files.
		register_rest_route(
			'datamachine/v1',
			'/files/agent/daily',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'list_daily_files' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'user_id' => $user_id_arg,
				),
			)
		);

		// GET /files/agent/daily/{year}/{month}/{day}
		register_rest_route(
			'datamachine/v1',
			'/files/agent/daily/(?P<year>\d{4})/(?P<month>\d{2})/(?P<day>\d{2})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_daily_file' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => $daily_date_args,
			)
		);

		// PUT /files/agent/daily/{year}/{month}/{day}
		register_rest_route(
			'datamachine/v1',
			'/files/agent/daily/(?P<year>\d{4})/(?P<month>\d{2})/(?P<day>\d{2})',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( self::class, 'put_daily_file' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => $daily_date_args,
			)
		);

		// DELETE /files/agent/daily/{year}/{month}/{day}
		register_rest_route(
			'datamachine/v1',
			'/files/agent/daily/(?P<year>\d{4})/(?P<month>\d{2})/(?P<day>\d{2})',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( self::class, 'delete_daily_file' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => $daily_date_args,
			)
		);
	}

	// =========================================================================
	// Permission
	// =========================================================================

	public static function check_permission( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You must be logged in to manage files.', 'data-machine' ),
				array( 'status' => 401 )
			);
		}

		$requested_user_id = self::resolve_scoped_user_id( $request );
		$current_user_id   = get_current_user_id();

		if ( $requested_user_id === $current_user_id ) {
			return true;
		}

		if ( PermissionHelper::can( 'manage_agents' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to access another user\'s files.', 'data-machine' ),
			array( 'status' => 403 )
		);
	}

	private static function resolve_scoped_user_id( WP_REST_Request $request ): int {
		$user_id = $request->get_param( 'user_id' );

		if ( null !== $user_id && '' !== $user_id ) {
			return (int) $user_id;
		}

		return get_current_user_id();
	}

	// =========================================================================
	// Agent file handlers
	// =========================================================================

	public static function list_agent_files( WP_REST_Request $request ) {
		$input = array(
			'user_id' => self::resolve_scoped_user_id( $request ),
		);

		$agent_id = $request->get_param( 'agent_id' );
		if ( null !== $agent_id && '' !== $agent_id ) {
			$input['agent_id'] = (int) $agent_id;
		}

		$result = self::getAbilities()->executeListAgentFiles( $input );

		if ( ! $result['success'] ) {
			return new WP_Error( 'list_agent_files_error', $result['error'], array( 'status' => 500 ) );
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => $result['files'],
		) );
	}

	public static function get_agent_file( WP_REST_Request $request ) {
		$input = array(
			'filename' => sanitize_file_name( wp_unslash( $request['filename'] ) ),
			'user_id'  => self::resolve_scoped_user_id( $request ),
		);

		$agent_id = $request->get_param( 'agent_id' );
		if ( null !== $agent_id && '' !== $agent_id ) {
			$input['agent_id'] = (int) $agent_id;
		}

		$result = self::getAbilities()->executeGetAgentFile( $input );

		if ( ! $result['success'] ) {
			$status = false !== strpos( $result['error'] ?? '', 'not found' ) ? 404 : 400;
			return new WP_Error( 'get_agent_file_error', $result['error'], array( 'status' => $status ) );
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => $result['file'],
		) );
	}

	public static function put_agent_file( WP_REST_Request $request ) {
		$filename = sanitize_file_name( wp_unslash( $request['filename'] ) );

		// Defense-in-depth: block writes to non-editable files at the REST layer.
		if ( ! \DataMachine\Engine\AI\MemoryFileRegistry::is_editable( $filename ) ) {
			$edit_cap = \DataMachine\Engine\AI\MemoryFileRegistry::get_edit_capability( $filename );
			$message  = false === $edit_cap
				? sprintf( 'File %s is read-only and cannot be edited.', $filename )
				: sprintf( 'You do not have permission to edit %s.', $filename );
			return new WP_Error( 'put_agent_file_error', $message, array( 'status' => 403 ) );
		}

		// Content may arrive as JSON body param (admin UI) or raw body (API clients).
		$content = $request->get_param( 'content' );
		if ( null === $content ) {
			$content = $request->get_body();
		}

		$input = array(
			'filename' => $filename,
			'content'  => $content,
			'user_id'  => self::resolve_scoped_user_id( $request ),
		);

		$agent_id = $request->get_param( 'agent_id' );
		if ( null !== $agent_id && '' !== $agent_id ) {
			$input['agent_id'] = (int) $agent_id;
		}

		$result = self::getAbilities()->executeWriteAgentFile( $input );

		if ( ! $result['success'] ) {
			$status = 400;
			if ( false !== strpos( $result['error'] ?? '', 'Filesystem' ) || false !== strpos( $result['error'] ?? '', 'Failed' ) ) {
				$status = 500;
			}
			return new WP_Error( 'put_agent_file_error', $result['error'], array( 'status' => $status ) );
		}

		return rest_ensure_response( array(
			'success'  => true,
			'filename' => $result['filename'],
		) );
	}

	public static function delete_agent_file( WP_REST_Request $request ) {
		$filename = sanitize_file_name( wp_unslash( $request['filename'] ) );

		// Defense-in-depth: block deletion of protected files at the REST layer.
		if ( FileConstants::is_protected( $filename ) ) {
			return new WP_Error(
				'delete_agent_file_error',
				sprintf( 'Cannot delete protected file: %s', $filename ),
				array( 'status' => 403 )
			);
		}

		$input = array(
			'filename' => $filename,
			'user_id'  => self::resolve_scoped_user_id( $request ),
		);

		$agent_id = $request->get_param( 'agent_id' );
		if ( null !== $agent_id && '' !== $agent_id ) {
			$input['agent_id'] = (int) $agent_id;
		}

		$result = self::getAbilities()->executeDeleteAgentFile( $input );

		if ( ! $result['success'] ) {
			$status = false !== strpos( $result['error'] ?? '', 'not found' ) ? 404 : 400;
			return new WP_Error( 'delete_agent_file_error', $result['error'], array( 'status' => $status ) );
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => array( 'message' => $result['message'] ),
		) );
	}

	// =========================================================================
	// Daily memory handlers (delegate to DailyMemoryAbilities)
	// =========================================================================

	public static function list_daily_files( WP_REST_Request $request ) {
		$input = array(
			'user_id' => self::resolve_scoped_user_id( $request ),
		);

		$agent_id = $request->get_param( 'agent_id' );
		if ( null !== $agent_id && '' !== $agent_id ) {
			$input['agent_id'] = (int) $agent_id;
		}

		$result = DailyMemoryAbilities::listDaily( $input );

		return rest_ensure_response( array(
			'success' => true,
			'data'    => $result['months'],
		) );
	}

	public static function get_daily_file( WP_REST_Request $request ) {
		$date  = sprintf( '%s-%s-%s', $request['year'], $request['month'], $request['day'] );
		$input = array(
			'date'    => $date,
			'user_id' => self::resolve_scoped_user_id( $request ),
		);

		$agent_id = $request->get_param( 'agent_id' );
		if ( null !== $agent_id && '' !== $agent_id ) {
			$input['agent_id'] = (int) $agent_id;
		}

		$result = DailyMemoryAbilities::readDaily( $input );

		if ( ! $result['success'] ) {
			return new WP_Error( 'daily_file_not_found', $result['message'], array( 'status' => 404 ) );
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'date'    => $result['date'],
				'content' => $result['content'],
			),
		) );
	}

	public static function put_daily_file( WP_REST_Request $request ) {
		$date = sprintf( '%s-%s-%s', $request['year'], $request['month'], $request['day'] );

		// Content may arrive as JSON body param (admin UI) or raw body (API clients).
		$content = $request->get_param( 'content' );
		if ( null === $content ) {
			$content = $request->get_body();
		}

		$input = array(
			'date'    => $date,
			'content' => $content,
			'mode'    => 'write',
			'user_id' => self::resolve_scoped_user_id( $request ),
		);

		$agent_id = $request->get_param( 'agent_id' );
		if ( null !== $agent_id && '' !== $agent_id ) {
			$input['agent_id'] = (int) $agent_id;
		}

		$result = DailyMemoryAbilities::writeDaily( $input );

		if ( ! $result['success'] ) {
			$status = false !== strpos( $result['message'] ?? '', 'disabled' ) ? 403 : 500;
			return new WP_Error( 'daily_file_write_error', $result['message'], array( 'status' => $status ) );
		}

		return rest_ensure_response( array(
			'success' => true,
			'date'    => $date,
			'message' => $result['message'],
		) );
	}

	public static function delete_daily_file( WP_REST_Request $request ) {
		$date  = sprintf( '%s-%s-%s', $request['year'], $request['month'], $request['day'] );
		$input = array(
			'date'    => $date,
			'user_id' => self::resolve_scoped_user_id( $request ),
		);

		$agent_id = $request->get_param( 'agent_id' );
		if ( null !== $agent_id && '' !== $agent_id ) {
			$input['agent_id'] = (int) $agent_id;
		}

		$result = DailyMemoryAbilities::deleteDaily( $input );

		if ( ! $result['success'] ) {
			$status = false !== strpos( $result['message'] ?? '', 'disabled' ) ? 403 : 404;
			return new WP_Error( 'daily_file_delete_error', $result['message'], array( 'status' => $status ) );
		}

		return rest_ensure_response( array(
			'success' => true,
			'message' => $result['message'],
		) );
	}

	// =========================================================================
	// Context memory file handlers
	// =========================================================================

	/**
	 * Resolve the contexts directory for the current agent.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return string Full path to the contexts directory.
	 */
	private static function resolve_contexts_dir( WP_REST_Request $request ): string {
		$dm = new \DataMachine\Core\FilesRepository\DirectoryManager();
		$user_id = $dm->get_effective_user_id( self::resolve_scoped_user_id( $request ) );

		$context = array( 'user_id' => $user_id );

		$agent_id = $request->get_param( 'agent_id' );
		if ( null !== $agent_id && '' !== $agent_id ) {
			$context['agent_id'] = (int) $agent_id;
		}

		return $dm->get_contexts_directory( $context );
	}

	/**
	 * List all context memory files.
	 */
	public static function list_context_files( WP_REST_Request $request ) {
		$dir = self::resolve_contexts_dir( $request );

		$files = array();
		if ( is_dir( $dir ) ) {
			foreach ( glob( trailingslashit( $dir ) . '*.md' ) as $filepath ) {
				$filename = basename( $filepath );
				$slug     = pathinfo( $filename, PATHINFO_FILENAME );
				$files[]  = array(
					'slug'     => $slug,
					'filename' => $filename,
					'size'     => filesize( $filepath ),
					'modified' => gmdate( 'c', filemtime( $filepath ) ),
				);
			}
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => $files,
		) );
	}

	/**
	 * Read a context memory file.
	 */
	public static function get_context_file( WP_REST_Request $request ) {
		$dir      = self::resolve_contexts_dir( $request );
		$slug     = sanitize_title( $request['context_slug'] );
		$filepath = trailingslashit( $dir ) . $slug . '.md';

		if ( ! file_exists( $filepath ) ) {
			return new WP_Error( 'not_found', "Context file '{$slug}.md' not found.", array( 'status' => 404 ) );
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			\WP_Filesystem();
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'slug'     => $slug,
				'filename' => $slug . '.md',
				'content'  => $wp_filesystem->get_contents( $filepath ),
				'size'     => filesize( $filepath ),
				'modified' => gmdate( 'c', filemtime( $filepath ) ),
			),
		) );
	}

	/**
	 * Create or update a context memory file.
	 */
	public static function put_context_file( WP_REST_Request $request ) {
		$dir  = self::resolve_contexts_dir( $request );
		$slug = sanitize_title( $request['context_slug'] );

		// Ensure the contexts directory exists.
		$dm = new \DataMachine\Core\FilesRepository\DirectoryManager();
		if ( ! $dm->ensure_directory_exists( $dir ) ) {
			return new WP_Error( 'create_dir_failed', 'Failed to create contexts directory.', array( 'status' => 500 ) );
		}

		$content = $request->get_param( 'content' );
		if ( null === $content ) {
			$content = $request->get_body();
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			\WP_Filesystem();
		}

		$filepath = trailingslashit( $dir ) . $slug . '.md';
		$written  = $wp_filesystem->put_contents( $filepath, $content, FS_CHMOD_FILE );

		if ( ! $written ) {
			return new WP_Error( 'write_failed', "Failed to write context file '{$slug}.md'.", array( 'status' => 500 ) );
		}

		return rest_ensure_response( array(
			'success'  => true,
			'slug'     => $slug,
			'filename' => $slug . '.md',
		) );
	}

	/**
	 * Delete a context memory file.
	 */
	public static function delete_context_file( WP_REST_Request $request ) {
		$dir      = self::resolve_contexts_dir( $request );
		$slug     = sanitize_title( $request['context_slug'] );
		$filepath = trailingslashit( $dir ) . $slug . '.md';

		if ( ! file_exists( $filepath ) ) {
			return new WP_Error( 'not_found', "Context file '{$slug}.md' not found.", array( 'status' => 404 ) );
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			\WP_Filesystem();
		}

		if ( ! $wp_filesystem->delete( $filepath ) ) {
			return new WP_Error( 'delete_failed', "Failed to delete context file '{$slug}.md'.", array( 'status' => 500 ) );
		}

		return rest_ensure_response( array(
			'success' => true,
			'message' => "Context file '{$slug}.md' deleted.",
		) );
	}

	/**
	 * Get file context array from flow ID.
	 *
	 * Kept here for backward compat — other code may reference Files::get_file_context().
	 *
	 * @param int|string $flow_id Flow ID or 'direct' for ephemeral workflows.
	 * @return array Context array with pipeline_id and flow_id.
	 */
	public static function get_file_context( int|string $flow_id ): array {
		return FlowFiles::get_file_context( $flow_id );
	}
}
