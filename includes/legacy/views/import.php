<?php
/**
 * Import page view.
 *
 * @package Rockfire_Rest_Api_Cache
 *
 * @var array{time:int, findings:list<\Rockfire\Rest_Api_Cache\Legacy\Finding>, meta:array<string,mixed>, truncated:int}|null $stored          The stored import, null before the first import.
 * @var \Rockfire\Rest_Api_Cache\Legacy\Import_Report|null                                                                       $preview         The dry run, null once an import is stored.
 * @var list<array{id:string,title:string,findings:list<\Rockfire\Rest_Api_Cache\Legacy\Finding>,truncated:int}>                  $groups          The other stored groups with findings.
 * @var string                                                                                                                   $offer           One of the `Import_Page::OFFER_*` constants.
 * @var string|null                                                                                                              $basename        Basename of the original plugin when it is active on this site, else null.
 * @var bool                                                                                                                     $original_active Whether the original plugin is active.
 */

use Rockfire\Rest_Api_Cache\Admin\Admin_Url;
use Rockfire\Rest_Api_Cache\Legacy\Import_Page;
use Rockfire\Rest_Api_Cache\Legacy\Import_Report;

defined( 'ABSPATH' ) || exit;

$rfrac_post_url = admin_url( 'admin-post.php' );
?>
<?php if ( null !== $stored ) : ?>
	<?php
	$rfrac_result = isset( $stored['meta']['result'] ) && is_string( $stored['meta']['result'] ) ? $stored['meta']['result'] : '';
	?>
	<div class="rfrac-section">
		<h2><?php esc_html_e( 'Import report', 'rockfire-rest-api-cache' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %s: date and time of the import. */
				esc_html__( 'The settings of WP REST Cache were imported on %s.', 'rockfire-rest-api-cache' ),
				esc_html( Import_Page::format_time( $stored['time'] ) )
			);
			?>
		</p>
		<?php if ( '' !== $rfrac_result ) : ?>
			<p><strong><?php esc_html_e( 'Result:', 'rockfire-rest-api-cache' ); ?></strong> <?php echo esc_html( Import_Page::result_label( $rfrac_result ) ); ?></p>
		<?php endif; ?>
		<p><?php echo esc_html( Import_Page::summary( Import_Page::counts_of( $stored['meta'] ), $rfrac_result, Import_Page::attention( $stored['findings'] ) ) ); ?></p>

		<?php if ( array() !== $stored['findings'] ) : ?>
			<?php
			$rfrac_table_findings = $stored['findings'];
			require RFRAC_DIR . 'includes/legacy/views/findings.php';
			?>
		<?php endif; ?>
		<?php if ( $stored['truncated'] > 0 ) : ?>
			<p class="rfrac-help">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of findings that are not listed. */
						_n( '%d more finding is not listed.', '%d more findings are not listed.', $stored['truncated'], 'rockfire-rest-api-cache' ),
						$stored['truncated']
					)
				);
				?>
			</p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( $rfrac_post_url ); ?>">
			<?php Admin_Url::form_fields( 'import_legacy' ); ?>
			<p class="submit">
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Run the import again', 'rockfire-rest-api-cache' ); ?></button>
			</p>
		</form>
		<p class="rfrac-help"><?php esc_html_e( 'Values you changed in this plugin since are kept; only values that still hold their default are imported again.', 'rockfire-rest-api-cache' ); ?></p>
	</div>
<?php elseif ( null !== $preview ) : ?>
	<div class="rfrac-section">
		<h2><?php esc_html_e( 'Preview', 'rockfire-rest-api-cache' ); ?></h2>
		<?php if ( Import_Report::RESULT_NOTHING_FOUND === $preview->result ) : ?>
			<p><?php esc_html_e( 'No settings of WP REST Cache were found on this site, so there is nothing to import.', 'rockfire-rest-api-cache' ); ?></p>
		<?php else : ?>
			<p><?php esc_html_e( 'This is what importing the settings of WP REST Cache would do. Nothing is changed until you import.', 'rockfire-rest-api-cache' ); ?></p>
			<?php if ( array() !== $preview->findings ) : ?>
				<?php
				$rfrac_table_findings = $preview->findings;
				require RFRAC_DIR . 'includes/legacy/views/findings.php';
				?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( $rfrac_post_url ); ?>">
				<?php Admin_Url::form_fields( 'import_legacy' ); ?>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Import settings', 'rockfire-rest-api-cache' ); ?></button>
				</p>
			</form>
			<p class="rfrac-help"><?php esc_html_e( 'Values you already changed in this plugin are kept: only values that still hold their default are imported.', 'rockfire-rest-api-cache' ); ?></p>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php foreach ( $groups as $rfrac_group ) : ?>
	<div class="rfrac-section">
		<h2><?php echo esc_html( $rfrac_group['title'] ); ?></h2>
		<?php if ( array() !== $rfrac_group['findings'] ) : ?>
			<?php
			$rfrac_table_findings = $rfrac_group['findings'];
			require RFRAC_DIR . 'includes/legacy/views/findings.php';
			?>
		<?php endif; ?>
		<?php if ( $rfrac_group['truncated'] > 0 ) : ?>
			<p class="rfrac-help">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of findings that are not listed. */
						_n( '%d more finding is not listed.', '%d more findings are not listed.', $rfrac_group['truncated'], 'rockfire-rest-api-cache' ),
						$rfrac_group['truncated']
					)
				);
				?>
			</p>
		<?php endif; ?>
	</div>
<?php endforeach; ?>

<?php if ( $original_active && Import_Page::OFFER_NONE !== $offer ) : ?>
	<div class="rfrac-section">
		<h2><?php esc_html_e( 'Deactivate WP REST Cache', 'rockfire-rest-api-cache' ); ?></h2>
		<p><?php esc_html_e( 'Caching stays paused while WP REST Cache is active. Deactivate it when you are done here; its settings stay in the database.', 'rockfire-rest-api-cache' ); ?></p>
		<?php if ( Import_Page::OFFER_NETWORK === $offer ) : ?>
			<p>
				<?php esc_html_e( 'WP REST Cache is active on the whole network, so it is deactivated on the network Plugins screen.', 'rockfire-rest-api-cache' ); ?>
				<a href="<?php echo esc_url( network_admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Go to the network Plugins screen', 'rockfire-rest-api-cache' ); ?></a>
			</p>
		<?php elseif ( Import_Page::OFFER_FORM === $offer && null !== $basename ) : ?>
			<p class="submit">
				<a class="button button-secondary" href="<?php echo esc_url( Import_Page::deactivate_url( $basename ) ); ?>"><?php esc_html_e( 'Deactivate WP REST Cache', 'rockfire-rest-api-cache' ); ?></a>
			</p>
		<?php endif; ?>
	</div>
<?php endif; ?>
