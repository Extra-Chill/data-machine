<?php
/**
 * Workspace scoped tools for handler-bound AI operations.
 *
 * Exposes workspace read/mutate primitives in a scoped manner where handler
 * configuration defines repository and path constraints.
 *
 * @package DataMachine\Core\Steps\Workspace\Tools
 * @since   0.37.0
 */

namespace DataMachine\Core\Steps\Workspace\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;

defined( 'ABSPATH' ) || exit;

class WorkspaceScopedTools extends BaseTool {

	/**
	 * Dispatch scoped tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @param array $tool_def   Tool definition.
	 * @return array
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$operation      = $tool_def['operation'] ?? '';
		$handler_config = $tool_def['handler_config'] ?? array();

		return match ( $operation ) {
			'fetch_ls'      => $this->handleFetchLs( $parameters, $handler_config ),
			'fetch_read'    => $this->handleFetchRead( $parameters, $handler_config ),
			'publish_write' => $this->handlePublishWrite( $parameters, $handler_config ),
			'publish_edit'  => $this->handlePublishEdit( $parameters, $handler_config ),
			'git_add'       => $this->handleGitAdd( $parameters, $handler_config ),
			'git_commit'    => $this->handleGitCommit( $parameters, $handler_config ),
			'git_push'      => $this->handleGitPush( $parameters, $handler_config ),
			'git_pull'      => $this->handleGitPull( $parameters, $handler_config ),
			default         => $this->buildErrorResponse( 'Unknown workspace scoped operation.', 'workspace_scoped_tools' ),
		};
	}

	/**
	 * Handle scoped workspace ls for fetch handler.
	 */
	private function handleFetchLs( array $parameters, array $handler_config ): array {
		$repo = $this->getRequiredRepo( $handler_config );
		if ( is_wp_error( $repo ) ) {
			return $this->buildErrorResponse( $repo->get_error_message(), 'workspace_fetch_ls' );
		}

		$path    = (string) ( $parameters['path'] ?? '' );
		$allowed = $this->getAllowedPaths( $handler_config, 'paths' );

		if ( ! $this->isPathWithinAllowlist( $path, $allowed ) ) {
			return $this->buildErrorResponse( 'Path is outside handler allowlist.', 'workspace_fetch_ls' );
		}

		$ability = wp_get_ability( 'datamachine/workspace-ls' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Workspace ls ability not available.', 'workspace_fetch_ls' );
		}

		$result = $ability->execute(
			array(
				'repo' => $repo,
				'path' => $path,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'workspace_fetch_ls' );
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( $this->getAbilityError( $result, 'Failed to list workspace directory.' ), 'workspace_fetch_ls' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'workspace_fetch_ls',
		);
	}

	/**
	 * Handle scoped workspace read for fetch handler.
	 */
	private function handleFetchRead( array $parameters, array $handler_config ): array {
		$repo = $this->getRequiredRepo( $handler_config );
		if ( is_wp_error( $repo ) ) {
			return $this->buildErrorResponse( $repo->get_error_message(), 'workspace_fetch_read' );
		}

		$path    = (string) ( $parameters['path'] ?? '' );
		$allowed = $this->getAllowedPaths( $handler_config, 'paths' );

		if ( '' === $path ) {
			return $this->buildErrorResponse( 'Parameter "path" is required.', 'workspace_fetch_read' );
		}

		if ( ! $this->isPathWithinAllowlist( $path, $allowed ) ) {
			return $this->buildErrorResponse( 'Path is outside handler allowlist.', 'workspace_fetch_read' );
		}

		$ability = wp_get_ability( 'datamachine/workspace-read' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Workspace read ability not available.', 'workspace_fetch_read' );
		}

		$input = array(
			'repo' => $repo,
			'path' => $path,
		);

		if ( isset( $parameters['offset'] ) ) {
			$input['offset'] = (int) $parameters['offset'];
		}
		if ( isset( $parameters['limit'] ) ) {
			$input['limit'] = (int) $parameters['limit'];
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'workspace_fetch_read' );
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( $this->getAbilityError( $result, 'Failed to read workspace file.' ), 'workspace_fetch_read' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'workspace_fetch_read',
		);
	}

	/**
	 * Handle scoped workspace write for publish handler.
	 */
	private function handlePublishWrite( array $parameters, array $handler_config ): array {
		$repo = $this->getRequiredRepo( $handler_config );
		if ( is_wp_error( $repo ) ) {
			return $this->buildErrorResponse( $repo->get_error_message(), 'workspace_write' );
		}

		$path    = (string) ( $parameters['path'] ?? '' );
		$content = (string) ( $parameters['content'] ?? '' );
		$allowed = $this->getAllowedPaths( $handler_config, 'writable_paths' );

		if ( '' === $path || '' === $content ) {
			return $this->buildErrorResponse( 'Parameters "path" and "content" are required.', 'workspace_write' );
		}

		if ( ! $this->isPathWithinAllowlist( $path, $allowed ) ) {
			return $this->buildErrorResponse( 'Write path is outside handler writable allowlist.', 'workspace_write' );
		}

		$ability = wp_get_ability( 'datamachine/workspace-write' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Workspace write ability not available.', 'workspace_write' );
		}

		$result = $ability->execute(
			array(
				'repo'    => $repo,
				'path'    => $path,
				'content' => $content,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'workspace_write' );
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( $this->getAbilityError( $result, 'Failed to write workspace file.' ), 'workspace_write' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'workspace_write',
		);
	}

	/**
	 * Handle scoped workspace edit for publish handler.
	 */
	private function handlePublishEdit( array $parameters, array $handler_config ): array {
		$repo = $this->getRequiredRepo( $handler_config );
		if ( is_wp_error( $repo ) ) {
			return $this->buildErrorResponse( $repo->get_error_message(), 'workspace_edit' );
		}

		$path       = (string) ( $parameters['path'] ?? '' );
		$old_string = (string) ( $parameters['old_string'] ?? '' );
		$new_string = (string) ( $parameters['new_string'] ?? '' );
		$allowed    = $this->getAllowedPaths( $handler_config, 'writable_paths' );

		if ( '' === $path || '' === $old_string ) {
			return $this->buildErrorResponse( 'Parameters "path" and "old_string" are required.', 'workspace_edit' );
		}

		if ( ! $this->isPathWithinAllowlist( $path, $allowed ) ) {
			return $this->buildErrorResponse( 'Edit path is outside handler writable allowlist.', 'workspace_edit' );
		}

		$ability = wp_get_ability( 'datamachine/workspace-edit' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Workspace edit ability not available.', 'workspace_edit' );
		}

		$result = $ability->execute(
			array(
				'repo'        => $repo,
				'path'        => $path,
				'old_string'  => $old_string,
				'new_string'  => $new_string,
				'replace_all' => ! empty( $parameters['replace_all'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'workspace_edit' );
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( $this->getAbilityError( $result, 'Failed to edit workspace file.' ), 'workspace_edit' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'workspace_edit',
		);
	}

	/**
	 * Handle scoped git add.
	 */
	private function handleGitAdd( array $parameters, array $handler_config ): array {
		$repo = $this->getRequiredRepo( $handler_config );
		if ( is_wp_error( $repo ) ) {
			return $this->buildErrorResponse( $repo->get_error_message(), 'workspace_git_add' );
		}

		$paths = $parameters['paths'] ?? array();
		if ( ! is_array( $paths ) || empty( $paths ) ) {
			return $this->buildErrorResponse( 'Parameter "paths" is required and must be an array.', 'workspace_git_add' );
		}

		$allowed = $this->getAllowedPaths( $handler_config, 'writable_paths' );
		foreach ( $paths as $path ) {
			if ( ! $this->isPathWithinAllowlist( (string) $path, $allowed ) ) {
				return $this->buildErrorResponse( sprintf( 'Path outside writable allowlist: %s', (string) $path ), 'workspace_git_add' );
			}
		}

		$ability = wp_get_ability( 'datamachine/workspace-git-add' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Workspace git add ability not available.', 'workspace_git_add' );
		}

		$result = $ability->execute(
			array(
				'name'  => $repo,
				'paths' => array_values( array_map( 'strval', $paths ) ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'workspace_git_add' );
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( $this->getAbilityError( $result, 'Failed to stage git paths.' ), 'workspace_git_add' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'workspace_git_add',
		);
	}

	/**
	 * Handle scoped git commit.
	 */
	private function handleGitCommit( array $parameters, array $handler_config ): array {
		$repo = $this->getRequiredRepo( $handler_config );
		if ( is_wp_error( $repo ) ) {
			return $this->buildErrorResponse( $repo->get_error_message(), 'workspace_git_commit' );
		}

		$message = trim( (string) ( $parameters['message'] ?? '' ) );
		if ( '' === $message ) {
			return $this->buildErrorResponse( 'Parameter "message" is required.', 'workspace_git_commit' );
		}

		$ability = wp_get_ability( 'datamachine/workspace-git-commit' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Workspace git commit ability not available.', 'workspace_git_commit' );
		}

		$result = $ability->execute(
			array(
				'name'    => $repo,
				'message' => $message,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'workspace_git_commit' );
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( $this->getAbilityError( $result, 'Failed to commit git changes.' ), 'workspace_git_commit' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'workspace_git_commit',
		);
	}

	/**
	 * Handle scoped git push.
	 */
	private function handleGitPush( array $parameters, array $handler_config ): array {
		$repo = $this->getRequiredRepo( $handler_config );
		if ( is_wp_error( $repo ) ) {
			return $this->buildErrorResponse( $repo->get_error_message(), 'workspace_git_push' );
		}

		if ( empty( $handler_config['push_enabled'] ) ) {
			return $this->buildErrorResponse( 'Push is disabled in handler configuration.', 'workspace_git_push' );
		}

		$ability = wp_get_ability( 'datamachine/workspace-git-push' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Workspace git push ability not available.', 'workspace_git_push' );
		}

		$input = array(
			'name'   => $repo,
			'remote' => (string) ( $parameters['remote'] ?? 'origin' ),
		);

		if ( ! empty( $handler_config['fixed_branch'] ) ) {
			$input['branch'] = (string) $handler_config['fixed_branch'];
		} elseif ( ! empty( $parameters['branch'] ) ) {
			$input['branch'] = (string) $parameters['branch'];
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'workspace_git_push' );
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( $this->getAbilityError( $result, 'Failed to push git changes.' ), 'workspace_git_push' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'workspace_git_push',
		);
	}

	/**
	 * Handle scoped git pull.
	 */
	private function handleGitPull( array $parameters, array $handler_config ): array {
		$repo = $this->getRequiredRepo( $handler_config );
		if ( is_wp_error( $repo ) ) {
			return $this->buildErrorResponse( $repo->get_error_message(), 'workspace_git_pull' );
		}

		$ability = wp_get_ability( 'datamachine/workspace-git-pull' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Workspace git pull ability not available.', 'workspace_git_pull' );
		}

		$result = $ability->execute(
			array(
				'name'        => $repo,
				'allow_dirty' => ! empty( $parameters['allow_dirty'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'workspace_git_pull' );
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( $this->getAbilityError( $result, 'Failed to pull git changes.' ), 'workspace_git_pull' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'workspace_git_pull',
		);
	}

	/**
	 * Extract required repo from handler config.
	 */
	private function getRequiredRepo( array $handler_config ) {
		$repo = trim( (string) ( $handler_config['repo'] ?? '' ) );
		if ( '' === $repo ) {
			return new \WP_Error( 'workspace_repo_missing', 'Workspace handler config is missing required repo.' );
		}

		return $repo;
	}

	/**
	 * Get allowed paths from handler configuration field.
	 */
	private function getAllowedPaths( array $handler_config, string $field ): array {
		$value = $handler_config[ $field ] ?? array();

		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$paths = array();
		foreach ( $value as $item ) {
			$normalized = trim( (string) $item );
			if ( '' === $normalized ) {
				continue;
			}

			$normalized = ltrim( str_replace( '\\', '/', $normalized ), '/' );
			$normalized = rtrim( $normalized, '/' );
			$paths[]    = $normalized;
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Check whether a path is within allowlist roots.
	 */
	private function isPathWithinAllowlist( string $path, array $allowlist ): bool {
		$normalized = ltrim( str_replace( '\\', '/', trim( $path ) ), '/' );

		if ( '' === $normalized ) {
			return true;
		}

		if ( empty( $allowlist ) ) {
			return false;
		}

		foreach ( $allowlist as $root ) {
			if ( '' === $root ) {
				continue;
			}

			if ( $normalized === $root || str_starts_with( $normalized, $root . '/' ) ) {
				return true;
			}
		}

		return false;
	}
}
