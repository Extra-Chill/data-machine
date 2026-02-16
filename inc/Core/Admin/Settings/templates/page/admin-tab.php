<?php
/**
 * Admin Tab Template
 *
 * Controls for engine mode, admin pages, and job data cleanup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

$datamachine_settings            = \DataMachine\Core\PluginSettings::all();
$datamachine_enabled_pages       = $datamachine_settings['enabled_pages'] ?? array();
$datamachine_cleanup_enabled     = $datamachine_settings['cleanup_job_data_on_failure'] ?? true;
$datamachine_file_retention_days = $datamachine_settings['file_retention_days'] ?? 7;

$datamachine_all_pages = apply_filters( 'datamachine_admin_pages', array() );
?>

<table class="form-table">
	<tr>
		<th scope="row"><?php esc_html_e( 'Admin Pages', 'data-machine' ); ?></th>
		<td>
			<?php if ( $datamachine_all_pages ) : ?>
				<fieldset>
					<?php foreach ( $datamachine_all_pages as $datamachine_slug => $datamachine_page_config ) : ?>
						<?php
						$datamachine_page_title = $datamachine_page_config['menu_title'] ?? $datamachine_page_config['page_title'] ?? ucfirst( $datamachine_slug );
						$datamachine_is_enabled = ! $datamachine_enabled_pages || ( $datamachine_enabled_pages[ $datamachine_slug ] ?? false );
						?>
						<label class="datamachine-settings-page-item">
							<input type="checkbox" 
									name="datamachine_settings[enabled_pages][<?php echo esc_attr( $datamachine_slug ); ?>]" 
									value="1" 
									<?php checked( $datamachine_is_enabled, true ); ?> >
							<?php echo esc_html( $datamachine_page_title ); ?>
						</label>
					<?php endforeach; ?>

					<p class="description">
						<?php esc_html_e( 'Unchecked pages will not appear in the WordPress admin menu.', 'data-machine' ); ?>
					</p>
				</fieldset>
			<?php else : ?>
				<p><?php esc_html_e( 'No admin pages are currently registered.', 'data-machine' ); ?></p>
			<?php endif; ?>
		</td>
	</tr>
	
	<tr>
		<th scope="row"><?php esc_html_e( 'Clean up job data on failure', 'data-machine' ); ?></th>
		<td>
			<fieldset>
				<label for="cleanup_job_data_on_failure">
					<input type="checkbox" 
							id="cleanup_job_data_on_failure"
							name="datamachine_settings[cleanup_job_data_on_failure]" 
							value="1" 
							<?php checked( $datamachine_cleanup_enabled, true ); ?>>
					<?php esc_html_e( 'Remove job data files when jobs fail', 'data-machine' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Disable to preserve failed job data files for debugging purposes. Processed items in database are always cleaned up to allow retry.', 'data-machine' ); ?>
				</p>
			</fieldset>
		</td>
	</tr>

	<tr>
		<th scope="row"><?php esc_html_e( 'File retention (days)', 'data-machine' ); ?></th>
		<td>
			<fieldset>
				<input type="number"
						id="file_retention_days"
						name="datamachine_settings[file_retention_days]"
						value="<?php echo esc_attr( $datamachine_file_retention_days ); ?>"
						min="1"
						max="90">
				<p class="description">
					<?php esc_html_e( 'Automatically delete repository files older than this many days. Includes Reddit images, Files handler uploads, and other temporary workflow files.', 'data-machine' ); ?>
				</p>
			</fieldset>
		</td>
	</tr>

	<tr>
		<th scope="row">
			<label for="github_pat"><?php esc_html_e( 'GitHub Personal Access Token', 'data-machine' ); ?></label>
		</th>
		<td>
			<input type="password"
					id="github_pat"
					name="datamachine_settings[github_pat]"
					value="<?php echo esc_attr( $datamachine_settings['github_pat'] ?? '' ); ?>"
					class="regular-text">
			<p class="description">
				<?php esc_html_e( 'A GitHub PAT with the "repo" scope. Used by the System Agent to create issues.', 'data-machine' ); ?>
			</p>
		</td>
	</tr>

	<tr>
		<th scope="row">
			<label for="github_default_repo"><?php esc_html_e( 'Default GitHub Repository', 'data-machine' ); ?></label>
		</th>
		<td>
			<input type="text"
					id="github_default_repo"
					name="datamachine_settings[github_default_repo]"
					value="<?php echo esc_attr( $datamachine_settings['github_default_repo'] ?? '' ); ?>"
					class="regular-text"
					placeholder="owner/repo">
			<p class="description">
				<?php esc_html_e( 'Default repository for GitHub issue creation, e.g., owner/repo.', 'data-machine' ); ?>
			</p>
		</td>
	</tr>

</table>