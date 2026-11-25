<?php
/**
 * Table of findings of the Import page, in reading order: what needs attention first.
 *
 * @package Rockfire_Rest_Api_Cache
 *
 * @var list<\Rockfire\Rest_Api_Cache\Legacy\Finding> $rfrac_table_findings The findings to list.
 */

use Rockfire\Rest_Api_Cache\Legacy\Import_Page;

defined( 'ABSPATH' ) || exit;
?>
<table class="widefat striped rfrac-import-table">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Status', 'rockfire-rest-api-cache' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Setting', 'rockfire-rest-api-cache' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Details', 'rockfire-rest-api-cache' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( Import_Page::ordered( $rfrac_table_findings ) as $rfrac_finding ) : ?>
			<tr>
				<td><?php echo esc_html( Import_Page::status_label( $rfrac_finding->status ) ); ?></td>
				<td>
					<?php if ( '' !== $rfrac_finding->subject ) : ?>
						<code><?php echo esc_html( $rfrac_finding->subject ); ?></code>
					<?php else : ?>
						&ndash;
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $rfrac_finding->message ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
