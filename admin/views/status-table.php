<?php
/**
 * Status table, shared by the Tools page (`full` mode, the default) and the Settings page (`storage` mode).
 *
 * @package Rockfire_Rest_Api_Cache
 *
 * @var \Rockfire\Rest_Api_Cache\Services\Status $status Status snapshot.
 * @var string|null                               $rfrac_mode Optional: `full` (default) or `storage`.
 */

use Rockfire\Rest_Api_Cache\Admin\Admin_Url;
use Rockfire\Rest_Api_Cache\Admin\Mu_Help;
use Rockfire\Rest_Api_Cache\Install\Mu_Installer;
use Rockfire\Rest_Api_Cache\Install\Mu_Status;

defined( 'ABSPATH' ) || exit;

if ( 'storage' === ( $rfrac_mode ?? 'full' ) ) {
	$rfrac_store_labels = array(
		'object_cache' => __( 'Object cache', 'rockfire-rest-api-cache' ),
		'database'     => __( 'Database', 'rockfire-rest-api-cache' ),
	);
	$rfrac_store        = $rfrac_store_labels[ $status->store_type ] ?? $status->store_type;
	?>
<table class="widefat striped rfrac-status-table">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Active store', 'rockfire-rest-api-cache' ); ?></th>
			<td><?php echo esc_html( $rfrac_store ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Persistent object cache', 'rockfire-rest-api-cache' ); ?></th>
			<td>
				<?php if ( $status->object_cache_detected ) : ?>
					<?php esc_html_e( 'Yes', 'rockfire-rest-api-cache' ); ?>
					<span class="rfrac-help"><?php esc_html_e( 'Payloads are stored in the object cache automatically.', 'rockfire-rest-api-cache' ); ?></span>
				<?php else : ?>
					<?php esc_html_e( 'No', 'rockfire-rest-api-cache' ); ?>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Must-use mode', 'rockfire-rest-api-cache' ); ?></th>
			<td>
				<?php
				echo esc_html(
					'early' === $status->mu_mode
						? __( 'Early (must-use file installed)', 'rockfire-rest-api-cache' )
						: __( 'Fallback (served later in the request)', 'rockfire-rest-api-cache' )
				);
				?>
			</td>
		</tr>
	</tbody>
</table>
	<?php
	return;
}

$rfrac_datetime = static function ( ?int $timestamp ): string {
	return null === $timestamp || $timestamp <= 0
		? __( 'Never', 'rockfire-rest-api-cache' )
		: (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
};
$rfrac_yes_no   = static fn( bool $value ): string => $value ? __( 'Yes', 'rockfire-rest-api-cache' ) : __( 'No', 'rockfire-rest-api-cache' );

if ( Mu_Status::CURRENT === $status->mu_status ) {
	$rfrac_mode_label = __( 'Early', 'rockfire-rest-api-cache' );
	$rfrac_mode_class = 'success';
} elseif ( Mu_Status::OUTDATED === $status->mu_status ) {
	$rfrac_mode_label = __( 'Outdated', 'rockfire-rest-api-cache' );
	$rfrac_mode_class = 'warning';
} else {
	$rfrac_mode_label = __( 'Fallback', 'rockfire-rest-api-cache' );
	$rfrac_mode_class = 'warning';
}
$rfrac_not_early   = Mu_Status::CURRENT !== $status->mu_status;
$rfrac_last        = $status->last_maintenance;
$rfrac_file_mods   = Mu_Installer::file_mods_allowed();
$rfrac_can_install = Mu_Installer::current_user_can_install();
?>
<table class="widefat striped rfrac-status-table">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Must-use mode', 'rockfire-rest-api-cache' ); ?></th>
			<td>
				<span class="rfrac-badge rfrac-badge--<?php echo esc_attr( $rfrac_mode_class ); ?>"><?php echo esc_html( $rfrac_mode_label ); ?></span>
				<?php if ( $rfrac_not_early ) : ?>
					<?php if ( Mu_Status::DISABLED === $status->mu_status ) : ?>
						<p class="rfrac-help"><?php echo esc_html( Mu_Help::reason( 'disabled' ) ); ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'Install the must-use file to serve cache hits early.', 'rockfire-rest-api-cache' ); ?></p>
						<?php Mu_Help::render_manual_instructions( $status->mu_template_path, $status->mu_target_path ); ?>
						<?php if ( ! $rfrac_file_mods ) : ?>
							<p class="rfrac-help"><?php echo esc_html( Mu_Help::reason( 'file_mods_disallowed' ) ); ?></p>
						<?php elseif ( ! $rfrac_can_install ) : ?>
							<p class="rfrac-help"><?php esc_html_e( 'Only a user who can manage plugins (a network administrator on multisite) can install it from here.', 'rockfire-rest-api-cache' ); ?></p>
						<?php elseif ( $status->mu_dir_writable ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php Admin_Url::form_fields( 'install_mu' ); ?>
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Install must-use file', 'rockfire-rest-api-cache' ); ?></button>
							</form>
						<?php endif; ?>
					<?php endif; ?>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Store', 'rockfire-rest-api-cache' ); ?></th>
			<td><?php echo esc_html( $status->store_type ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Persistent object cache detected', 'rockfire-rest-api-cache' ); ?></th>
			<td><?php echo esc_html( $rfrac_yes_no( $status->object_cache_detected ) ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Cached responses', 'rockfire-rest-api-cache' ); ?></th>
			<td>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: active entries, 2: flushed entries, 3: expired entries, 4: total entries, 5: size. */
						__( '%1$d active, %2$d flushed, %3$d expired, %4$d total (%5$s)', 'rockfire-rest-api-cache' ),
						$status->counts['active'],
						$status->counts['flushed'],
						$status->counts['expired'],
						$status->counts['total'],
						(string) size_format( $status->total_size )
					)
				);
				?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Maximum entries', 'rockfire-rest-api-cache' ); ?></th>
			<td><?php echo esc_html( number_format_i18n( $status->max_entries ) ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Maintenance backlog', 'rockfire-rest-api-cache' ); ?></th>
			<td><?php echo esc_html( number_format_i18n( $status->backlog ) ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Last maintenance', 'rockfire-rest-api-cache' ); ?></th>
			<td><?php echo esc_html( $rfrac_datetime( null === $rfrac_last ? null : (int) ( $rfrac_last['finished_at'] ?? 0 ) ) ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Next maintenance', 'rockfire-rest-api-cache' ); ?></th>
			<td><?php echo esc_html( null === $status->next_maintenance ? __( 'Not scheduled', 'rockfire-rest-api-cache' ) : $rfrac_datetime( $status->next_maintenance ) ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'WP-Cron disabled', 'rockfire-rest-api-cache' ); ?></th>
			<td><?php echo esc_html( $rfrac_yes_no( $status->cron_disabled ) ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Database version', 'rockfire-rest-api-cache' ); ?></th>
			<td>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: installed version, 2: expected version. */
						__( 'Installed %1$d, expected %2$d', 'rockfire-rest-api-cache' ),
						$status->db_version,
						$status->db_version_expected
					)
				);
				?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Tables present', 'rockfire-rest-api-cache' ); ?></th>
			<td><?php echo esc_html( null === $status->tables_present ? __( 'Not checked', 'rockfire-rest-api-cache' ) : $rfrac_yes_no( $status->tables_present ) ); ?></td>
		</tr>
	</tbody>
</table>
<?php if ( $status->coexistence_blocked ) : ?>
	<div class="notice notice-warning inline"><p><?php esc_html_e( 'The original WP REST Cache plugin is active. Caching is paused until it is deactivated, so the two plugins do not conflict.', 'rockfire-rest-api-cache' ); ?></p></div>
<?php endif; ?>
