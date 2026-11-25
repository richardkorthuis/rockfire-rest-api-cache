<?php
/**
 * Caches page: the entries list.
 *
 * @package Rockfire_Rest_Api_Cache
 *
 * @var \Rockfire\Rest_Api_Cache\Admin\Tables\Entries_Table $table         List table with its items loaded.
 * @var string                                              $list_url      URL of the page without filters.
 * @var string                                              $view          Current state view.
 * @var array{id:string,route:string}|null                  $route_filter  Active route filter.
 * @var bool                                                $unknown_route Whether a `route_id` matched no route.
 */

defined( 'ABSPATH' ) || exit;

?>
<?php if ( null !== $route_filter ) : ?>
	<p class="rfrac-filter">
		<?php
		printf(
			/* translators: %s: route pattern */
			esc_html__( 'Showing the entries of the route %s.', 'rockfire-rest-api-cache' ),
			'<code class="rfrac-path">' . esc_html( $route_filter['route'] ) . '</code>'
		);
		?>
		<a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Show all routes', 'rockfire-rest-api-cache' ); ?></a>
	</p>
<?php elseif ( $unknown_route ) : ?>
	<div class="notice notice-warning inline"><p><?php esc_html_e( 'The route filter does not match a known route and was ignored.', 'rockfire-rest-api-cache' ); ?></p></div>
<?php endif; ?>

<?php $table->views(); ?>

<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
	<input type="hidden" name="page" value="<?php echo esc_attr( \Rockfire\Rest_Api_Cache\Admin\Pages\Caches_Page::SLUG ); ?>" />
	<?php if ( 'all' !== $view ) : ?>
		<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />
	<?php endif; ?>
	<?php if ( null !== $route_filter ) : ?>
		<input type="hidden" name="route_id" value="<?php echo esc_attr( $route_filter['id'] ); ?>" />
	<?php endif; ?>
	<?php
	$table->search_box( __( 'Search requests', 'rockfire-rest-api-cache' ), 'rfrac-entries' );
	$table->display();
	?>
</form>
