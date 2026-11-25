<?php
/**
 * View of the Settings page.
 *
 * @package Rockfire_Rest_Api_Cache
 *
 * @var \Rockfire\Rest_Api_Cache\Services\Status $status Status snapshot.
 */

defined( 'ABSPATH' ) || exit;

// Without a setting: also core's "Settings saved." (the `general` message of options.php), which this top-level page must print itself.
settings_errors();
?>
<form action="options.php" method="post">
	<?php
	settings_fields( 'rfrac_settings' );
	do_settings_sections( 'rfrac-settings' );
	submit_button();
	?>
</form>

<h2><?php esc_html_e( 'Storage', 'rockfire-rest-api-cache' ); ?></h2>
<?php
$rfrac_mode = 'storage';
require RFRAC_DIR . 'admin/views/status-table.php';
