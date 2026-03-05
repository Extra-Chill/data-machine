<?php
/**
 * Workspace Tools — AI agent tools for workspace read operations.
 *
 * Exposes non-mutating workspace capabilities as global tools so pipelines,
 * system agents, and chat agents can inspect repositories safely.
 *
 * @package DataMachine\Engine\AI\Tools\Global
 * @since   0.37.0
 */

namespace DataMachine\Engine\AI\Tools\Global;

use DataMachine\Engine\AI\Tools\BaseTool;

defined( 'ABSPATH' ) || exit;

class WorkspaceTools extends BaseTool {

	/**
	 * Constructor — register all workspace read tools as global tools.
	 */
	public function __construct() {
		$this->registerGlobalTool( 'workspace_path', array( $this, 'getPathDefinition' ) );
		$this->registerGlobalTool( 'workspace_list', array( $this, 'getListDefinition' ) );
		$this->registerGlobalTool( 'workspace_show', array( $this, 'getShowDefinition' ) );
		$this->registerGlobalTool( 'workspace_ls', array( $this, 'getLsDefinition' ) );
		$this->registerGlobalTool( 'workspace_read', array( $this, 'getReadDefinition' ) );
	}

	/**
	 * Dispatch tool calls to specific handlers.
	 *
	 * @param array $parameters Tool parameters.
	 * @param array $tool_def   Tool definition with method key.
	 * @return array
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$method = $tool_def['method'] ?? '';

		if ( ! method_exists( $this, $method ) ) {
			return $this->buildErrorResponse( "Unknown workspace tool method: {$method}", 'workspace_tools' );
		}

		return $this->{$method}( $parameters, $tool_def );
	}

	/**
	 * Handle workspace_path tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array
	 */
	public function handlePath( array $parameters ): array {
		$ability = wp_get_ability( 'datamachine/workspace-path' );

		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Workspace path ability not available.', 'workspace_path' );
		}

		$result = $ability->execute(
			array(
				'ensure' => ! empty( $parameters['ensure'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'workspace_path' );
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse(
				$this->getAbilityError( $result, 'Failed to get workspace path.' ),
				'workspace_path'
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'workspace_path',
		);
	}

	/**
	 * Handle workspace_list tool call.
	 *
	 * @return array
	 */
	public function handleList(): array {
		$ability = wp_get_ability( 'datamachine/workspace-list' );

		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Workspace list ability not available.', 'workspace_list' );
		}

		$result = $ability->execute( array() );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'workspace_list' );
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse(
				$this->getAbilityError( $result, 'Failed to list workspace repositories.' ),
				'workspace_list'
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'workspace_list',
		);
	}

	/**
	 * Handle workspace_show tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array
	 */
	public function handleShow( array $parameters ): array {
		$ability = wp_get_ability( 'datamachine/workspace-show' );

		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Workspace show ability not available.', 'workspace_show' );
		}

		$result = $ability->execute(
			array(
				'name' => $parameters['name'] ?? '',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'workspace_show' );
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse(
				$this->getAbilityError( $result, 'Failed to get workspace repository details.' ),
				'workspace_show'
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'workspace_show',
		);
	}

	/**
	 * Handle workspace_ls tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array
	 */
	public function handleLs( array $parameters ): array {
		$ability = wp_get_ability( 'datamachine/workspace-ls' );

		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Workspace ls ability not available.', 'workspace_ls' );
		}

		$result = $ability->execute(
			array(
				'repo' => $parameters['repo'] ?? '',
				'path' => $parameters['path'] ?? '',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'workspace_ls' );
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse(
				$this->getAbilityError( $result, 'Failed to list workspace directory.' ),
				'workspace_ls'
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'workspace_ls',
		);
	}

	/**
	 * Handle workspace_read tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array
	 */
	public function handleRead( array $parameters ): array {
		$ability = wp_get_ability( 'datamachine/workspace-read' );

		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Workspace read ability not available.', 'workspace_read' );
		}

		$input = array(
			'repo' => $parameters['repo'] ?? '',
			'path' => $parameters['path'] ?? '',
		);

		if ( isset( $parameters['max_size'] ) ) {
			$input['max_size'] = (int) $parameters['max_size'];
		}

		if ( isset( $parameters['offset'] ) ) {
			$input['offset'] = (int) $parameters['offset'];
		}

		if ( isset( $parameters['limit'] ) ) {
			$input['limit'] = (int) $parameters['limit'];
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'workspace_read' );
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse(
				$this->getAbilityError( $result, 'Failed to read workspace file.' ),
				'workspace_read'
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'workspace_read',
		);
	}

	/**
	 * Tool definition for workspace_path.
	 *
	 * @return array
	 */
	public function getPathDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handlePath',
			'description' => 'Get the Data Machine workspace path. Optionally ensure it exists.',
			'parameters'  => array(
				'ensure' => array(
					'type'        => 'boolean',
					'required'    => false,
					'description' => 'Create the workspace directory if it does not exist (default false).',
				),
			),
		);
	}

	/**
	 * Tool definition for workspace_list.
	 *
	 * @return array
	 */
	public function getListDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleList',
			'description' => 'List repositories currently present in the Data Machine workspace.',
			'parameters'  => array(),
		);
	}

	/**
	 * Tool definition for workspace_show.
	 *
	 * @return array
	 */
	public function getShowDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleShow',
			'description' => 'Show detailed information about a workspace repository (branch, remote, latest commit, dirty count).',
			'parameters'  => array(
				'name' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Workspace repository directory name.',
				),
			),
		);
	}

	/**
	 * Tool definition for workspace_ls.
	 *
	 * @return array
	 */
	public function getLsDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleLs',
			'description' => 'List directory contents within a workspace repository.',
			'parameters'  => array(
				'repo' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Workspace repository directory name.',
				),
				'path' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Optional relative directory path inside the repo.',
				),
			),
		);
	}

	/**
	 * Tool definition for workspace_read.
	 *
	 * @return array
	 */
	public function getReadDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleRead',
			'description' => 'Read a text file from a workspace repository. Supports optional max_size, offset, and limit for large files.',
			'parameters'  => array(
				'repo'     => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Workspace repository directory name.',
				),
				'path'     => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Relative file path inside the repository.',
				),
				'max_size' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Maximum readable size in bytes (default 1MB).',
				),
				'offset'   => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Line offset to start reading from (1-indexed).',
				),
				'limit'    => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Maximum number of lines to return.',
				),
			),
		);
	}
}
