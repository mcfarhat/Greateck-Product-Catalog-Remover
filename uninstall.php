<?php
/**
 * Removes the plugin's own leftovers when it is deleted.
 *
 * Products are never touched here. This only clears the job state the plugin
 * stores while a removal is running.
 *
 * @package WooProductRemover
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wpr_job' );

// Multisite: the option is per-site, so clear it on every site in the network.
if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		delete_option( 'wpr_job' );
		restore_current_blog();
	}
}
