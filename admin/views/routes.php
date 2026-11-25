<?php
/**
 * View of the Routes list.
 *
 * @package Rockfire_Rest_Api_Cache
 *
 * @var \Rockfire\Rest_Api_Cache\Admin\Tables\Routes_Table $table
 * @var string                                             $page_slug
 * @var string                                             $view      Current view.
 * @var array<string,array{label:string,routes:list<string>}> $presets Route presets: key => label and routes.
 * @var array<string,bool>                                     $enabled Route presets: key => whether the preset is enabled.
 */

use Rockfire\Rest_Api_Cache\Admin\Admin_Url;

defined( 'ABSPATH' ) || exit;

?>
<p class="rfrac-help">
	<?php esc_html_e( 'Every REST route that registers GET, POST, PUT or PATCH and the policy it gets. Denied routes are never cached (cart, checkout, orders, shopper lists, agentic checkout, private WooCommerce routes, current user, batch). Locked routes are defined by a constant in wp-config.php.', 'rockfire-rest-api-cache' ); ?>
</p>

<?php if ( array() !== $presets ) : ?>
	<div class="rfrac-route-presets">
		<?php foreach ( $presets as $rfrac_key => $rfrac_preset ) : ?>
			<?php $rfrac_preset_enabled = ! empty( $enabled[ $rfrac_key ] ); ?>
			<form method="post" class="rfrac-inline-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php Admin_Url::form_fields( $rfrac_preset_enabled ? 'disable_route_preset' : 'enable_route_preset', (string) $rfrac_key ); ?>
				<?php
				$rfrac_button = $rfrac_preset_enabled
					/* translators: %s: name of a group of routes. */
					? sprintf( __( 'Disable %s', 'rockfire-rest-api-cache' ), $rfrac_preset['label'] )
					/* translators: %s: name of a group of routes. */
					: sprintf( __( 'Enable %s', 'rockfire-rest-api-cache' ), $rfrac_preset['label'] );
				?>
				<button type="submit" class="button"><?php echo esc_html( $rfrac_button ); ?></button>
			</form>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<?php $table->views(); ?>

<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
	<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>" />
	<?php if ( 'all' !== $view ) : ?>
		<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />
	<?php endif; ?>
	<?php
	// The search box adds the current orderby and order as hidden fields.
	$table->search_box( __( 'Search routes', 'rockfire-rest-api-cache' ), 'rfrac-route' );
	$table->display();
	?>
</form>
