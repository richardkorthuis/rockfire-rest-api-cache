<?php
/**
 * Tools page view.
 *
 * @package Rockfire_Rest_Api_Cache
 *
 * @var \Rockfire\Rest_Api_Cache\Services\Status       $status Status snapshot.
 * @var \Rockfire\Rest_Api_Cache\Services\Route_View[] $routes Enabled routes.
 */

use Rockfire\Rest_Api_Cache\Admin\Admin_Url;

defined( 'ABSPATH' ) || exit;

$rfrac_post_url = admin_url( 'admin-post.php' );
?>
<div class="rfrac-section">
	<h2><?php esc_html_e( 'Status', 'rockfire-rest-api-cache' ); ?></h2>
	<?php require RFRAC_DIR . 'admin/views/status-table.php'; ?>
</div>

<div class="rfrac-section">
	<h2><?php esc_html_e( 'Flush', 'rockfire-rest-api-cache' ); ?></h2>

	<form method="post" class="rfrac-inline-form" action="<?php echo esc_url( $rfrac_post_url ); ?>">
		<?php Admin_Url::form_fields( 'flush_all' ); ?>
		<p>
			<button type="submit" class="button button-secondary" data-rfrac-confirm="<?php esc_attr_e( 'Flush every cached response of this site?', 'rockfire-rest-api-cache' ); ?>"><?php esc_html_e( 'Flush all', 'rockfire-rest-api-cache' ); ?></button>
		</p>
	</form>

	<form method="post" class="rfrac-inline-form" action="<?php echo esc_url( $rfrac_post_url ); ?>">
		<?php Admin_Url::form_fields( 'flush_tag' ); ?>
		<p>
			<label for="rfrac_tag"><strong><?php esc_html_e( 'Flush by tag', 'rockfire-rest-api-cache' ); ?></strong></label><br />
			<input type="text" id="rfrac_tag" name="rfrac_tag" class="regular-text" placeholder="post:123" aria-describedby="rfrac_tag-description" />
			<button type="submit" class="button"><?php esc_html_e( 'Flush tag', 'rockfire-rest-api-cache' ); ?></button>
		</p>
		<p class="description rfrac-help" id="rfrac_tag-description">
			<?php
			printf(
				/* translators: %s: example tags. */
				esc_html__( 'Tags look like %s. Separate several tags with commas.', 'rockfire-rest-api-cache' ),
				'<code>post:123</code>, <code>term:5</code>, <code>user:2</code>, <code>comment:9</code>, <code>list:post</code>'
			);
			?>
		</p>
	</form>

	<form method="post" class="rfrac-inline-form" action="<?php echo esc_url( $rfrac_post_url ); ?>">
		<?php Admin_Url::form_fields( 'flush_url' ); ?>
		<p>
			<label for="rfrac_url"><strong><?php esc_html_e( 'Flush by URL', 'rockfire-rest-api-cache' ); ?></strong></label><br />
			<input type="url" id="rfrac_url" name="rfrac_url" class="regular-text" placeholder="<?php echo esc_attr( rest_url( 'wp/v2/posts' ) ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Flush URL', 'rockfire-rest-api-cache' ); ?></button>
		</p>
	</form>

	<form method="post" class="rfrac-inline-form" action="<?php echo esc_url( $rfrac_post_url ); ?>">
		<?php Admin_Url::form_fields( 'flush_route' ); ?>
		<p>
			<label for="rfrac_route"><strong><?php esc_html_e( 'Flush a route', 'rockfire-rest-api-cache' ); ?></strong></label><br />
			<select id="rfrac_route" name="target">
				<?php foreach ( $routes as $rfrac_route ) : ?>
					<option value="<?php echo esc_attr( $rfrac_route->id ); ?>"><?php echo esc_html( $rfrac_route->route ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button" <?php disabled( array() === $routes ); ?>><?php esc_html_e( 'Flush route', 'rockfire-rest-api-cache' ); ?></button>
		</p>
	</form>
</div>

<div class="rfrac-section">
	<h2><?php esc_html_e( 'Maintenance', 'rockfire-rest-api-cache' ); ?></h2>
	<p><?php esc_html_e( 'Delete flushed and expired entries and evict the oldest entries above the limit. One bounded pass runs per click.', 'rockfire-rest-api-cache' ); ?></p>
	<?php if ( $status->cron_disabled ) : ?>
		<p class="rfrac-help"><?php esc_html_e( 'WP-Cron is disabled (DISABLE_WP_CRON); maintenance only runs when you click the button or from your own scheduler.', 'rockfire-rest-api-cache' ); ?></p>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( $rfrac_post_url ); ?>">
		<?php Admin_Url::form_fields( 'run_maintenance' ); ?>
		<button type="submit" class="button"><?php esc_html_e( 'Run now', 'rockfire-rest-api-cache' ); ?></button>
	</form>
</div>
