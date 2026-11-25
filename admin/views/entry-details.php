<?php
/**
 * Caches page: the details of one entry. Everything cached is printed escaped, never as markup.
 *
 * @package Rockfire_Rest_Api_Cache
 *
 * @var \Rockfire\Rest_Api_Cache\Storage\Entry_Row $row               Entry.
 * @var string                                     $state             `active`, `flushed` or `expired`.
 * @var string                                     $headers           Response headers as pretty JSON.
 * @var string[]                                   $tags              Tags.
 * @var string|null                                $payload           Payload, null when not available.
 * @var string                                     $payload_text      Payload to show (pretty-printed or truncated).
 * @var bool                                       $payload_truncated Whether the payload was cut.
 * @var int                                        $hit_sampling      Hit sampling rate.
 * @var bool                                       $can_flush         Whether the entry can be flushed.
 * @var string                                     $list_url          URL of the list.
 */

use Rockfire\Rest_Api_Cache\Admin\Admin_Url;
use Rockfire\Rest_Api_Cache\Admin\Tables\Entries_Table;

defined( 'ABSPATH' ) || exit;

$rfrac_badge = Entries_Table::state_badge( $state );
?>
<p><a href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'Back to the caches', 'rockfire-rest-api-cache' ); ?></a></p>

<h2><code class="rfrac-path"><?php echo esc_html( $row->request ); ?></code></h2>

<div class="rfrac-entry-actions">
	<?php if ( $can_flush ) : ?>
		<form method="post" class="rfrac-inline-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php Admin_Url::form_fields( 'flush_entry', (string) $row->id ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'Flush', 'rockfire-rest-api-cache' ); ?></button>
		</form>
	<?php endif; ?>
	<form method="post" class="rfrac-inline-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php Admin_Url::form_fields( 'delete_entry', (string) $row->id ); ?>
		<button type="submit" class="button button-link-delete" data-rfrac-confirm="<?php esc_attr_e( 'Delete this cache entry?', 'rockfire-rest-api-cache' ); ?>"><?php esc_html_e( 'Delete', 'rockfire-rest-api-cache' ); ?></button>
	</form>
</div>

<h3><?php esc_html_e( 'Entry', 'rockfire-rest-api-cache' ); ?></h3>
<table class="widefat striped rfrac-details">
	<tbody>
		<tr><th scope="row"><?php esc_html_e( 'ID', 'rockfire-rest-api-cache' ); ?></th><td><?php echo esc_html( (string) $row->id ); ?></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Request', 'rockfire-rest-api-cache' ); ?></th><td><code><?php echo esc_html( $row->method . ' ' . $row->request ); ?></code></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Route', 'rockfire-rest-api-cache' ); ?></th><td><code><?php echo esc_html( $row->route ); ?></code></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Rule', 'rockfire-rest-api-cache' ); ?></th><td><code><?php echo esc_html( $row->rule_key ); ?></code></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'State', 'rockfire-rest-api-cache' ); ?></th><td><span class="rfrac-badge rfrac-badge--<?php echo esc_attr( $rfrac_badge[1] ); ?>"><?php echo esc_html( $rfrac_badge[0] ); ?></span></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Size', 'rockfire-rest-api-cache' ); ?></th><td><?php echo esc_html( Entries_Table::format_size( $row->size ) ); ?></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Hits', 'rockfire-rest-api-cache' ); ?></th><td><?php echo esc_html( ( $hit_sampling > 1 ? '≈' : '' ) . number_format_i18n( $row->hits ) ); ?></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Created', 'rockfire-rest-api-cache' ); ?></th><td><?php echo esc_html( Entries_Table::format_date( $row->created_at ) ); ?></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Expires', 'rockfire-rest-api-cache' ); ?></th><td><?php echo esc_html( Entries_Table::format_date( $row->expires_at ) ); ?></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Last hit', 'rockfire-rest-api-cache' ); ?></th><td><?php echo esc_html( Entries_Table::format_date( $row->last_hit_at ) ); ?></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Flushed', 'rockfire-rest-api-cache' ); ?></th><td><?php echo esc_html( Entries_Table::format_date( (string) $row->flushed_at ) ); ?></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Cache key (includes the Vary information)', 'rockfire-rest-api-cache' ); ?></th><td><code><?php echo esc_html( $row->cache_key ); ?></code></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'URL hash', 'rockfire-rest-api-cache' ); ?></th><td><code><?php echo esc_html( $row->url_hash ); ?></code></td></tr>
	</tbody>
</table>

<h3><?php esc_html_e( 'Tags', 'rockfire-rest-api-cache' ); ?></h3>
<?php if ( array() === $tags ) : ?>
	<p><?php esc_html_e( 'This entry has no tags.', 'rockfire-rest-api-cache' ); ?></p>
<?php else : ?>
	<p>
		<?php foreach ( $tags as $rfrac_tag ) : ?>
			<code><?php echo esc_html( $rfrac_tag ); ?></code>
		<?php endforeach; ?>
	</p>
<?php endif; ?>

<h3><?php esc_html_e( 'Headers', 'rockfire-rest-api-cache' ); ?></h3>
<pre class="rfrac-pre"><?php echo esc_html( $headers ); ?></pre>

<h3><?php esc_html_e( 'Payload', 'rockfire-rest-api-cache' ); ?></h3>
<?php if ( null === $payload ) : ?>
	<p><?php esc_html_e( 'Payload not available (evicted from object cache).', 'rockfire-rest-api-cache' ); ?></p>
<?php else : ?>
	<?php if ( $payload_truncated ) : ?>
		<div class="notice notice-warning inline"><p>
			<?php
			printf(
				/* translators: %s: size shown, e.g. 512 KB */
				esc_html__( 'The payload is large; only the first %s are shown.', 'rockfire-rest-api-cache' ),
				esc_html( (string) size_format( \Rockfire\Rest_Api_Cache\Admin\Pages\Caches_Page::MAX_PAYLOAD_BYTES ) )
			);
			?>
		</p></div>
	<?php endif; ?>
	<pre class="rfrac-pre"><?php echo esc_html( $payload_text ); ?></pre>
<?php endif; ?>
