<?php
/**
 * Abstract Social Chat Tool
 *
 * Base class for the social chat-tool wrappers in inc/Chat/Tools. Centralizes
 * the duplicated tool-registration boilerplate and the auth-guard (provider
 * resolution + the two diagnostic error blocks) that every social chat tool
 * repeated verbatim.
 *
 * Subclasses supply:
 * - $tool_name      The chat tool identifier (e.g. 'publish_threads').
 * - $platform       The auth provider key (e.g. 'threads').
 * - getToolDefinition()  The tool schema for the AI agent.
 * - handle_tool_call()   The per-tool validation, input build, ability
 *                        dispatch and result shaping.
 *
 * The auth-guard is invoked from handle_tool_call() via guardAuth(); it returns
 * null when the platform is authenticated (and caches the resolved provider on
 * $this->provider) or a diagnostic error response array when it is not.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.6.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract base for social chat tools.
 */
abstract class AbstractSocialTool extends BaseTool {

	/**
	 * Chat tool identifier (e.g. 'publish_threads').
	 *
	 * @var string
	 */
	protected string $tool_name = '';

	/**
	 * Auth provider key for this platform (e.g. 'threads').
	 *
	 * @var string
	 */
	protected string $platform = '';

	/**
	 * Human-readable platform label for diagnostic messages (e.g. 'Threads').
	 *
	 * @var string
	 */
	protected string $platform_label = '';

	/**
	 * Resolved auth provider, populated by guardAuth().
	 *
	 * @var mixed
	 */
	protected $provider = null;

	/**
	 * Constructor registers the tool in the 'chat' mode.
	 */
	public function __construct() {
		$this->registerTool( $this->tool_name, array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	/**
	 * Tool definition for the AI agent.
	 *
	 * @return array Tool definition.
	 */
	abstract public function getToolDefinition(): array;

	/**
	 * Handle the chat tool call.
	 *
	 * @param array $parameters Tool parameters from the AI agent.
	 * @param array $tool_def   Tool definition context.
	 * @return array Result for the AI agent.
	 */
	abstract public function handle_tool_call( array $parameters, array $tool_def = array() ): array;

	/**
	 * Get the platform label used in diagnostic messages.
	 *
	 * Defaults to a title-cased platform key when not explicitly set.
	 *
	 * @return string
	 */
	protected function platformLabel(): string {
		if ( '' !== $this->platform_label ) {
			return $this->platform_label;
		}

		return ucfirst( $this->platform );
	}

	/**
	 * Resolve and authenticate the platform provider.
	 *
	 * Returns null when the provider is available and authenticated (with the
	 * provider cached on $this->provider), or a diagnostic error response array
	 * to be returned from handle_tool_call() when it is not.
	 *
	 * @param bool $require_authenticated When true, also enforces is_authenticated().
	 * @return array|null Diagnostic error response, or null on success.
	 */
	protected function guardAuth( bool $require_authenticated = true ): ?array {
		$label          = $this->platformLabel();
		$auth_abilities = new AuthAbilities();
		$this->provider = $auth_abilities->getProvider( $this->platform );

		if ( ! $this->provider ) {
			return $this->buildDiagnosticErrorResponse(
				$label . ' auth provider not available',
				'prerequisite_missing',
				$this->tool_name,
				array(
					'provider' => $this->platform,
					'status'   => 'not_registered',
				),
				array(
					'action'    => 'configure_' . $this->platform . '_auth',
					'message'   => $label . ' OAuth needs to be configured in Data Machine Settings > Auth.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		if ( $require_authenticated && ! $this->provider->is_authenticated() ) {
			return $this->buildDiagnosticErrorResponse(
				$label . ' is not authenticated',
				'prerequisite_missing',
				$this->tool_name,
				array(
					'provider' => $this->platform,
					'status'   => 'not_authenticated',
				),
				array(
					'action'    => 'authenticate_' . $this->platform,
					'message'   => $label . ' OAuth needs to be connected. Go to Data Machine Settings > Auth > ' . $label . '.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		return null;
	}
}
