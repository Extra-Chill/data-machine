<?php
/**
 * Agent Tab Template
 *
 * AI controls including tool configuration and system prompts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

$datamachine_settings             = \DataMachine\Core\PluginSettings::all();
$datamachine_agent_soul           = $datamachine_settings['agent_soul'] ?? array();
$datamachine_site_context_enabled = $datamachine_settings['site_context_enabled'] ?? false;
$datamachine_default_provider     = $datamachine_settings['default_provider'] ?? '';
$datamachine_default_model        = $datamachine_settings['default_model'] ?? '';
$datamachine_enabled_tools        = $datamachine_settings['enabled_tools'] ?? array();
$datamachine_max_turns            = $datamachine_settings['max_turns'] ?? 12;

$datamachine_tool_manager = new \DataMachine\Engine\AI\Tools\ToolManager();
$datamachine_global_tools = $datamachine_tool_manager->get_global_tools();
?>

<table class="form-table">
	<tr>
		<th scope="row"><?php esc_html_e( 'Tool Configuration', 'data-machine' ); ?></th>
		<td>
			<?php if ( $datamachine_global_tools ) : ?>
				<div class="datamachine-tool-config-grid">
					<?php foreach ( $datamachine_global_tools as $datamachine_tool_name => $datamachine_tool_config ) : ?>
						<?php
						$datamachine_is_configured   = $datamachine_tool_manager->is_tool_configured( $datamachine_tool_name );
						$datamachine_is_enabled      = isset( $datamachine_enabled_tools[ $datamachine_tool_name ] );
						$datamachine_requires_config = $datamachine_tool_manager->requires_configuration( $datamachine_tool_name );
						$datamachine_tool_label      = $datamachine_tool_config['label'] ?? ucfirst( str_replace( '_', ' ', $datamachine_tool_name ) );
						?>
						<div class="datamachine-tool-config-item">
							<h4><?php echo esc_html( $datamachine_tool_label ); ?></h4>
							<p class="description"><?php echo esc_html( $datamachine_tool_config['description'] ?? '' ); ?></p>
							<div class="datamachine-tool-controls">
								<span class="datamachine-config-status <?php echo $datamachine_is_configured ? 'configured' : 'not-configured'; ?>">
									<?php echo $datamachine_is_configured ? esc_html__( 'Configured', 'data-machine' ) : esc_html__( 'Not Configured', 'data-machine' ); ?>
								</span>

								<?php if ( $datamachine_is_configured ) : ?>
									<!-- Show toggle for configured tools -->
									<label class="datamachine-tool-enabled-toggle">
										<input type="checkbox"
												name="datamachine_settings[enabled_tools][<?php echo esc_attr( $datamachine_tool_name ); ?>]"
												value="1"
												<?php checked( $datamachine_is_enabled, true ); ?>>
										<?php esc_html_e( 'Enable for agents', 'data-machine' ); ?>
									</label>
								<?php else : ?>
									<!-- Show disabled checkbox for unconfigured tools -->
									<label class="datamachine-tool-enabled-toggle datamachine-tool-disabled">
										<input type="checkbox" disabled>
										<span class="description"><?php esc_html_e( 'Configure to enable', 'data-machine' ); ?></span>
									</label>
								<?php endif; ?>

								<?php if ( $datamachine_requires_config ) : ?>
									<!-- Only show Configure button for tools that need configuration -->
									<button type="button"
											class="button datamachine-open-modal"
											data-modal-id="datamachine-modal-tool-config-<?php echo esc_attr( $datamachine_tool_name ); ?>">
										<?php esc_html_e( 'Configure', 'data-machine' ); ?>
									</button>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p><?php esc_html_e( 'No global tools are currently available.', 'data-machine' ); ?></p>
			<?php endif; ?>
		</td>
	</tr>

	<tr>
		<th scope="row"><?php esc_html_e( 'Agent Soul', 'data-machine' ); ?></th>
		<td>
			<p class="description" style="margin-bottom: 12px;">
				<?php esc_html_e( 'Define your agent\'s identity. These sections shape WHO the agent is across all pipelines and chat interactions. Empty sections are skipped.', 'data-machine' ); ?>
			</p>

			<details open style="margin-bottom: 10px;">
				<summary style="cursor: pointer; font-weight: 600; padding: 6px 0;">
					<?php esc_html_e( 'Identity', 'data-machine' ); ?>
				</summary>
				<textarea name="datamachine_settings[agent_soul][identity]"
							rows="4"
							cols="70"
							class="large-text code"
							placeholder="<?php esc_attr_e( 'Who is this agent? What site does it write for? What\'s its purpose?', 'data-machine' ); ?>"><?php echo esc_textarea( $datamachine_agent_soul['identity'] ?? '' ); ?></textarea>
			</details>

			<details style="margin-bottom: 10px;">
				<summary style="cursor: pointer; font-weight: 600; padding: 6px 0;">
					<?php esc_html_e( 'Voice & Tone', 'data-machine' ); ?>
				</summary>
				<textarea name="datamachine_settings[agent_soul][voice]"
							rows="4"
							cols="70"
							class="large-text code"
							placeholder="<?php esc_attr_e( 'Tone, personality, writing style. e.g. "Curious, warm, slightly chaotic" or "Professional, authoritative, concise"', 'data-machine' ); ?>"><?php echo esc_textarea( $datamachine_agent_soul['voice'] ?? '' ); ?></textarea>
			</details>

			<details style="margin-bottom: 10px;">
				<summary style="cursor: pointer; font-weight: 600; padding: 6px 0;">
					<?php esc_html_e( 'Rules', 'data-machine' ); ?>
				</summary>
				<textarea name="datamachine_settings[agent_soul][rules]"
							rows="4"
							cols="70"
							class="large-text code"
							placeholder="<?php esc_attr_e( 'Universal behavioral constraints. e.g. "Never include text in generated images", "Always use Oxford commas"', 'data-machine' ); ?>"><?php echo esc_textarea( $datamachine_agent_soul['rules'] ?? '' ); ?></textarea>
			</details>

			<details style="margin-bottom: 10px;">
				<summary style="cursor: pointer; font-weight: 600; padding: 6px 0;">
					<?php esc_html_e( 'Context', 'data-machine' ); ?>
				</summary>
				<textarea name="datamachine_settings[agent_soul][context]"
							rows="4"
							cols="70"
							class="large-text code"
							placeholder="<?php esc_attr_e( 'Audience, content philosophy, brand values, target demographics.', 'data-machine' ); ?>"><?php echo esc_textarea( $datamachine_agent_soul['context'] ?? '' ); ?></textarea>
			</details>
		</td>
	</tr>

	<tr>
		<th scope="row"><?php esc_html_e( 'Default AI Provider & Model', 'data-machine' ); ?></th>
		<td>
			<div class="datamachine-ai-provider-model-settings">
				<div class="datamachine-provider-field">
					<label for="default_provider"><?php esc_html_e( 'Default AI Provider', 'data-machine' ); ?></label>
					<select name="datamachine_settings[default_provider]"
							id="default_provider"
							class="regular-text">
						<option value=""><?php esc_html_e( 'Select Provider...', 'data-machine' ); ?></option>
					</select>
				</div>

				<div class="datamachine-model-field">
					<label for="default_model"><?php esc_html_e( 'Default AI Model', 'data-machine' ); ?></label>
					<select name="datamachine_settings[default_model]"
							id="default_model"
							class="regular-text">
						<option value=""><?php esc_html_e( 'Select provider first...', 'data-machine' ); ?></option>
					</select>
				</div>
			</div>
			<p class="description">
				<?php esc_html_e( 'Set the default AI provider and model for new AI steps and chat requests. These can be overridden on a per-step or per-request basis.', 'data-machine' ); ?>
			</p>
		</td>
	</tr>

	<tr>
		<th scope="row"><?php esc_html_e( 'Provide site context to agents', 'data-machine' ); ?></th>
		<td>
			<fieldset>
				<label for="site_context_enabled">
					<input type="checkbox"
							id="site_context_enabled"
							name="datamachine_settings[site_context_enabled]"
							value="1"
							<?php checked( $datamachine_site_context_enabled, true ); ?>>
					<?php esc_html_e( 'Include WordPress site context in AI requests', 'data-machine' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Automatically provides site information (post types, taxonomies, user stats) to AI agents for better context awareness.', 'data-machine' ); ?>
				</p>
			</fieldset>
		</td>
	</tr>

	<tr>
		<th scope="row"><?php esc_html_e( 'Maximum conversation turns', 'data-machine' ); ?></th>
		<td>
			<input type="number"
					name="datamachine_settings[max_turns]"
					value="<?php echo esc_attr( $datamachine_max_turns ); ?>"
					min="1"
					max="50"
					class="small-text">
			<p class="description">
				<?php esc_html_e( 'Maximum number of conversation turns allowed for AI agents (1-50). Applies to both pipeline and chat conversations.', 'data-machine' ); ?>
			</p>
		</td>
	</tr>
</table>
