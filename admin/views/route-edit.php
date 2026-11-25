<?php
/**
 * View of the route edit form.
 *
 * @package Rockfire_Rest_Api_Cache
 *
 * @var \Rockfire\Rest_Api_Cache\Services\Route_View $route
 * @var \Rockfire\Rest_Api_Cache\Config\Route_Rule   $rule
 * @var bool                                         $read_only
 * @var string                                       $list_url
 * @var array<string,string>                         $post_types
 * @var array<string,string>                         $taxonomies
 * @var string                                       $routes_const
 * @var array<string,list<string>>                   $arguments
 * @var int                                          $default_ttl
 * @var int                                          $max_ttl
 * @var int                                          $max_params
 * @var list<string>                                 $global_ignore
 * @var list<string>                                 $global_vary
 */

use Rockfire\Rest_Api_Cache\Admin\Admin_Url;
use Rockfire\Rest_Api_Cache\Admin\Pages\Routes_Page;
use Rockfire\Rest_Api_Cache\Config\Route_Rule;

defined( 'ABSPATH' ) || exit;
?>
<p><a href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'Back to the routes', 'rockfire-rest-api-cache' ); ?></a></p>

<?php if ( $route->locked ) : ?>
	<div class="notice notice-info inline"><p>
		<?php
		printf(
			/* translators: %s: constant name. */
			esc_html__( 'Locked by %s in wp-config.php', 'rockfire-rest-api-cache' ),
			'<code>' . esc_html( $routes_const ) . '</code>'
		);
		?>
	</p></div>
<?php elseif ( $route->is_denied ) : ?>
	<div class="notice notice-info inline"><p><?php esc_html_e( 'This route is always bypassed for security (cart/checkout, current user, batch).', 'rockfire-rest-api-cache' ); ?></p></div>
<?php elseif ( ! $route->is_registered ) : ?>
	<div class="notice notice-warning inline"><p><?php esc_html_e( 'This route is not registered any more. Its stored rule can only be reset.', 'rockfire-rest-api-cache' ); ?></p></div>
<?php endif; ?>

<?php if ( ! $route->is_denied && array() !== $route->custom_access ) : ?>
	<div class="notice <?php echo array() !== $route->cached_custom ? 'notice-warning' : 'notice-info'; ?> inline rfrac-custom-access-notice">
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: request methods such as GET, comma separated. */
					__( 'This route checks access with its own permission callback for %s.', 'rockfire-rest-api-cache' ),
					implode( ', ', array() !== $route->cached_custom ? $route->cached_custom : $route->custom_access )
				)
			);
			?>
		</p>
		<p><?php esc_html_e( 'Responses are only stored for visitors who are not logged in. But when the check relies on something the cache does not see, such as a secret request header or the visitor\'s IP address, a response one visitor was allowed to see is served to every visitor.', 'rockfire-rest-api-cache' ); ?></p>
		<p>
			<?php
			printf(
				'%1$s %2$s',
				array() !== $route->cached_custom
					? esc_html__( 'Responses of this route are cached: keep caching it only when the route is public to every visitor who is not logged in.', 'rockfire-rest-api-cache' )
					: esc_html__( 'Before you cache this route, make sure it is public to every visitor who is not logged in.', 'rockfire-rest-api-cache' ),
				sprintf(
					/* translators: 1: constant name, 2: filter name. */
					esc_html__( 'If the check relies on a request header, add that header to %1$s in wp-config.php or with the %2$s filter, so requests that carry it bypass the cache.', 'rockfire-rest-api-cache' ),
					'<code>RFRAC_BYPASS_HEADERS</code>',
					'<code>rfrac/bypass_headers</code>'
				)
			);
			?>
		</p>
	</div>
<?php endif; ?>

<table class="form-table">
	<tr>
		<th scope="row"><?php esc_html_e( 'Route', 'rockfire-rest-api-cache' ); ?></th>
		<td><code class="rfrac-path"><?php echo esc_html( $route->route ); ?></code></td>
	</tr>
	<tr>
		<th scope="row"><?php esc_html_e( 'Namespace', 'rockfire-rest-api-cache' ); ?></th>
		<td><?php echo '' === $route->namespace ? '&mdash;' : esc_html( $route->namespace ); ?></td>
	</tr>
	<?php foreach ( $arguments as $rfrac_method => $rfrac_names ) : ?>
	<tr>
		<th scope="row">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: request method such as GET or POST. */
					__( 'Registered %s arguments', 'rockfire-rest-api-cache' ),
					$rfrac_method
				)
			);
			?>
		</th>
		<td><?php echo array() === $rfrac_names ? '&mdash;' : '<code>' . esc_html( implode( ', ', $rfrac_names ) ) . '</code>'; ?></td>
	</tr>
	<?php endforeach; ?>
	<tr>
		<th scope="row"><?php esc_html_e( 'Cached by default', 'rockfire-rest-api-cache' ); ?></th>
		<td><?php echo $route->is_default ? esc_html__( 'Yes', 'rockfire-rest-api-cache' ) : esc_html__( 'No', 'rockfire-rest-api-cache' ); ?></td>
	</tr>
</table>

<?php if ( $route->is_registered ) : ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rfrac-route-form">
	<?php Admin_Url::form_fields( 'save_route', $route->id ); ?>

	<fieldset class="rfrac-section"<?php echo $read_only ? ' disabled="disabled"' : ''; ?>>
		<legend><strong><?php esc_html_e( 'Status', 'rockfire-rest-api-cache' ); ?></strong></legend>
		<?php
		$rfrac_statuses = array(
			Route_Rule::STATUS_DEFAULT  => __( 'Default', 'rockfire-rest-api-cache' ),
			Route_Rule::STATUS_ENABLED  => __( 'Enabled', 'rockfire-rest-api-cache' ),
			Route_Rule::STATUS_EXCLUDED => __( 'Excluded', 'rockfire-rest-api-cache' ),
		);
		foreach ( $rfrac_statuses as $rfrac_value => $rfrac_label ) :
			?>
			<label><input type="radio" name="status" value="<?php echo esc_attr( $rfrac_value ); ?>" <?php checked( $rule->status, $rfrac_value ); ?> /> <?php echo esc_html( $rfrac_label ); ?></label><br />
		<?php endforeach; ?>
		<p class="description"><?php esc_html_e( 'Default follows the plugin: core content routes are cached, other routes are not until you enable them. Excluded routes are never cached.', 'rockfire-rest-api-cache' ); ?></p>
	</fieldset>

	<fieldset class="rfrac-section"<?php echo $read_only ? ' disabled="disabled"' : ''; ?>>
		<legend><strong><?php esc_html_e( 'Methods', 'rockfire-rest-api-cache' ); ?></strong></legend>
		<input type="hidden" name="<?php echo esc_attr( Routes_Page::METHODS_SHOWN_FIELD ); ?>" value="1" />
		<?php foreach ( array_keys( $arguments ) as $rfrac_method ) : ?>
			<label><input type="checkbox" name="methods[]" value="<?php echo esc_attr( $rfrac_method ); ?>"<?php checked( in_array( $rfrac_method, $rule->methods, true ) ); ?><?php disabled( $read_only ); ?> /> <code><?php echo esc_html( $rfrac_method ); ?></code></label><br />
		<?php endforeach; ?>
		<p class="description"><?php esc_html_e( 'The request methods that are cached. Only methods the route registers can be chosen; a request with another method is never cached.', 'rockfire-rest-api-cache' ); ?></p>
		<?php if ( array() !== array_diff( array_keys( $arguments ), Route_Rule::DEFAULT_METHODS ) ) : // A non-GET method can be enabled. ?>
			<div class="notice notice-warning inline" data-rfrac-show-if="methods[]:POST|PUT|PATCH"><p><?php esc_html_e( 'A cached POST, PUT or PATCH response is served without running the route\'s handler: anything the handler does besides returning data does not happen on a cache hit. Enable these methods only for routes that only read data.', 'rockfire-rest-api-cache' ); ?></p></div>
		<?php endif; ?>
	</fieldset>

	<fieldset class="rfrac-section"<?php echo $read_only ? ' disabled="disabled"' : ''; ?>>
		<legend><strong><?php esc_html_e( 'Lifetime', 'rockfire-rest-api-cache' ); ?></strong></legend>
		<p>
			<label for="rfrac-ttl"><?php esc_html_e( 'TTL', 'rockfire-rest-api-cache' ); ?></label>
			<input type="number" id="rfrac-ttl" name="ttl" min="60" max="<?php echo esc_attr( (string) $max_ttl ); ?>" step="1" value="<?php echo esc_attr( null === $rule->ttl ? '' : (string) $rule->ttl ); ?>" class="small-text" aria-describedby="rfrac-ttl-description" /> <?php esc_html_e( 'seconds', 'rockfire-rest-api-cache' ); ?>
		</p>
		<p class="description" id="rfrac-ttl-description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: site default TTL in seconds. */
					__( 'Leave empty to use the site default (%d seconds).', 'rockfire-rest-api-cache' ),
					$default_ttl
				)
			);
			?>
		</p>
	</fieldset>

	<fieldset class="rfrac-section"<?php echo $read_only ? ' disabled="disabled"' : ''; ?>>
		<legend><strong><?php esc_html_e( 'Parameters', 'rockfire-rest-api-cache' ); ?></strong></legend>
		<label><input type="radio" name="param_mode" value="<?php echo esc_attr( Route_Rule::PARAM_REGISTERED ); ?>" <?php checked( $rule->param_mode, Route_Rule::PARAM_REGISTERED ); ?> /> <?php esc_html_e( 'Registered args only (recommended)', 'rockfire-rest-api-cache' ); ?></label><br />
		<label><input type="radio" name="param_mode" value="<?php echo esc_attr( Route_Rule::PARAM_ALLOW_ALL ); ?>" <?php checked( $rule->param_mode, Route_Rule::PARAM_ALLOW_ALL ); ?> />
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: maximum number of query parameters. */
					__( 'Allow all parameters (max %d)', 'rockfire-rest-api-cache' ),
					$max_params
				)
			);
			?>
		</label>
		<p class="description" data-rfrac-show-if="param_mode:allow_all"><?php esc_html_e( 'Every distinct query string is cached separately; requests with more parameters than the maximum are not cached.', 'rockfire-rest-api-cache' ); ?></p>

		<p data-rfrac-show-if="param_mode:registered">
			<label for="rfrac-ignore-params"><?php esc_html_e( 'Ignore these parameters', 'rockfire-rest-api-cache' ); ?></label><br />
			<textarea id="rfrac-ignore-params" name="ignore_params" rows="3" cols="40" class="large-text code" aria-describedby="rfrac-ignore-params-description<?php echo array() === $global_ignore ? '' : ' rfrac-ignore-params-global'; ?>"><?php echo esc_textarea( implode( "\n", $rule->ignore_params ) ); ?></textarea><br />
			<span class="description" id="rfrac-ignore-params-description"><?php esc_html_e( 'One per line, for example utm_source. They do not change the cache key.', 'rockfire-rest-api-cache' ); ?></span>
			<?php if ( array() !== $global_ignore ) : ?>
				<br /><span class="description" id="rfrac-ignore-params-global">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: comma separated list of names. */
							__( 'Also applied to every route: %s', 'rockfire-rest-api-cache' ),
							implode( ', ', $global_ignore )
						)
					);
					?>
				</span>
			<?php endif; ?>
		</p>
		<p>
			<label for="rfrac-bypass-params"><?php esc_html_e( 'Always bypass when present', 'rockfire-rest-api-cache' ); ?></label><br />
			<textarea id="rfrac-bypass-params" name="bypass_params" rows="3" cols="40" class="large-text code" aria-describedby="rfrac-bypass-params-description"><?php echo esc_textarea( implode( "\n", $rule->bypass_params ) ); ?></textarea><br />
			<span class="description" id="rfrac-bypass-params-description"><?php esc_html_e( 'One per line. A request with one of these parameters is never cached or served from the cache.', 'rockfire-rest-api-cache' ); ?></span>
		</p>
	</fieldset>

	<fieldset class="rfrac-section"<?php echo $read_only ? ' disabled="disabled"' : ''; ?>>
		<legend><strong><?php esc_html_e( 'Vary headers', 'rockfire-rest-api-cache' ); ?></strong></legend>
		<p>
			<label for="rfrac-vary-headers"><?php esc_html_e( 'Request headers that change the response', 'rockfire-rest-api-cache' ); ?></label><br />
			<textarea id="rfrac-vary-headers" name="vary_headers" rows="2" cols="40" class="large-text code" aria-describedby="rfrac-vary-headers-description<?php echo array() === $global_vary ? '' : ' rfrac-vary-headers-global'; ?>"><?php echo esc_textarea( implode( "\n", $rule->vary_headers ) ); ?></textarea><br />
			<span class="description" id="rfrac-vary-headers-description"><?php esc_html_e( 'One per line, for example Accept-Language. Authorization, Cookie and Set-Cookie are not allowed.', 'rockfire-rest-api-cache' ); ?></span>
			<?php if ( array() !== $global_vary ) : ?>
				<br /><span class="description" id="rfrac-vary-headers-global">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: comma separated list of names. */
							__( 'Also applied to every route: %s', 'rockfire-rest-api-cache' ),
							implode( ', ', $global_vary )
						)
					);
					?>
				</span>
			<?php endif; ?>
		</p>
	</fieldset>

	<fieldset class="rfrac-section"<?php echo $read_only ? ' disabled="disabled"' : ''; ?>>
		<legend><strong><?php esc_html_e( 'Flush when these change', 'rockfire-rest-api-cache' ); ?></strong></legend>
		<p class="description"><?php esc_html_e( 'Cached responses of this route are flushed automatically when content they contain changes. Add more triggers here.', 'rockfire-rest-api-cache' ); ?></p>
		<fieldset>
			<legend><?php esc_html_e( 'Post types', 'rockfire-rest-api-cache' ); ?></legend>
			<?php foreach ( $post_types as $rfrac_name => $rfrac_label ) : ?>
				<label><input type="checkbox" name="flush_post_types[]" value="<?php echo esc_attr( $rfrac_name ); ?>" <?php checked( in_array( $rfrac_name, $rule->flush_post_types, true ) ); ?> /> <?php echo esc_html( $rfrac_label ); ?> <code><?php echo esc_html( $rfrac_name ); ?></code></label><br />
			<?php endforeach; ?>
		</fieldset>
		<fieldset>
			<legend><?php esc_html_e( 'Taxonomies', 'rockfire-rest-api-cache' ); ?></legend>
			<?php foreach ( $taxonomies as $rfrac_name => $rfrac_label ) : ?>
				<label><input type="checkbox" name="flush_taxonomies[]" value="<?php echo esc_attr( $rfrac_name ); ?>" <?php checked( in_array( $rfrac_name, $rule->flush_taxonomies, true ) ); ?> /> <?php echo esc_html( $rfrac_label ); ?> <code><?php echo esc_html( $rfrac_name ); ?></code></label><br />
			<?php endforeach; ?>
		</fieldset>
	</fieldset>

	<?php if ( ! $read_only ) : ?>
		<p class="submit">
			<?php submit_button( __( 'Save route', 'rockfire-rest-api-cache' ), 'primary', 'submit', false ); ?>
		</p>
	<?php endif; ?>
</form>
<?php endif; ?>

<?php if ( $route->enabled || ( ! $route->locked && null !== $route->rule ) ) : ?>
	<div class="rfrac-section rfrac-route-actions">
		<?php if ( $route->enabled ) : ?>
			<form method="post" class="rfrac-inline-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php Admin_Url::form_fields( 'flush_route_cache', $route->id ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Flush cached responses', 'rockfire-rest-api-cache' ); ?></button>
			</form>
		<?php endif; ?>
		<?php if ( ! $route->locked && null !== $route->rule ) : ?>
			<form method="post" class="rfrac-inline-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php Admin_Url::form_fields( 'reset_route', $route->id ); ?>
				<button type="submit" class="button" data-rfrac-confirm="<?php esc_attr_e( 'Reset this route to its default rule?', 'rockfire-rest-api-cache' ); ?>"><?php esc_html_e( 'Reset to defaults', 'rockfire-rest-api-cache' ); ?></button>
			</form>
		<?php endif; ?>
	</div>
<?php endif; ?>
