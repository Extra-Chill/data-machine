<?php
/**
 * Injectable Memory Files Ability
 *
 * Exposes — as a queryable list — the memory files registered for injection
 * for a given agent, resolved to absolute on-disk paths plus metadata. This is
 * the same "which registered files should be in agent context?" answer that
 * CoreMemoryFilesDirective computes internally for Data Machine's own AI calls,
 * surfaced so other consumers can ask for it.
 *
 * AGNOSTIC BY CONTRACT: this ability answers only a question about Data
 * Machine's OWN registry state. It knows nothing about how the list is
 * consumed — no session host, runtime, or config-file format. It emits generic
 * resolved paths + metadata; the consumer decides what to do with them.
 *
 * @package DataMachine\Abilities
 * @see https://github.com/Extra-Chill/data-machine/issues/2568
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\FilesRepository\AgentMemory;
use DataMachine\Engine\AI\MemoryFileRegistry;

defined( 'ABSPATH' ) || exit;

class InjectableMemoryFilesAbility {

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbility();
		self::$registered = true;
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/list-injectable-memory-files',
				array(
					'label'               => 'List Injectable Memory Files',
					'description'         => 'List the memory files registered for injection for an agent, resolved to absolute on-disk paths plus layer metadata. Agnostic to how the list is consumed.',
					'category'            => 'datamachine-memory',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'agent_id' => array(
								'type'        => array( 'integer', 'null' ),
								'description' => 'Agent ID. Takes priority over user_id when provided.',
							),
							'user_id'  => array(
								'type'        => 'integer',
								'description' => 'WordPress user ID for multi-agent scoping. Defaults to 0 (shared agent).',
								'default'     => 0,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'files'   => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'filename' => array( 'type' => 'string' ),
										'layer'    => array( 'type' => 'string' ),
										'path'     => array( 'type' => 'string' ),
										'priority' => array( 'type' => 'integer' ),
									),
								),
							),
						),
					),
					'execute_callback'    => array( self::class, 'listInjectableFiles' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Resolve the agent's injectable memory files to absolute paths.
	 *
	 * Queries the registry for files marked always-injected across the
	 * interactive injection contexts (agent identity, agent memory, user
	 * profile) plus all-mode shared/network files — the same union
	 * CoreMemoryFilesDirective would inject — then resolves each to an
	 * absolute path and keeps only those that exist on disk.
	 *
	 * @param array $input Input parameters.
	 * @return array{success: bool, files: array<int, array{filename: string, layer: string, path: string, priority: int}>}
	 */
	public static function listInjectableFiles( array $input ): array {
		$agent_id = (int) ( $input['agent_id'] ?? 0 );
		$user_id  = (int) ( $input['user_id'] ?? 0 );

		$injection_contexts = array(
			MemoryFileRegistry::INJECTION_AGENT_IDENTITY,
			MemoryFileRegistry::INJECTION_AGENT_MEMORY,
			MemoryFileRegistry::INJECTION_USER_PROFILE,
		);

		// Pass the all-mode marker so shared/network files registered with
		// modes => array( MODE_ALL ) are included alongside the
		// injection-context-gated agent/user files.
		$registered = MemoryFileRegistry::get_for_modes(
			array( MemoryFileRegistry::MODE_ALL ),
			$injection_contexts
		);

		$files = array();
		foreach ( $registered as $filename => $meta ) {
			$layer  = $meta['layer'] ?? MemoryFileRegistry::LAYER_AGENT;
			$memory = new AgentMemory( $user_id, $agent_id, $filename, $layer );
			$path   = $memory->get_file_path();

			if ( '' === $path || ! file_exists( $path ) ) {
				continue;
			}

			$files[] = array(
				'filename' => $filename,
				'layer'    => $layer,
				'path'     => $path,
				'priority' => (int) ( $meta['priority'] ?? 50 ),
			);
		}

		// Stable order by registry priority (lower loads first), matching
		// the directive's load order.
		usort(
			$files,
			static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority']
		);

		return array(
			'success' => true,
			'files'   => $files,
		);
	}
}
