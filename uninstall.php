<?php
/**
 * Remove settings and job state. Objects in R2 and attachment meta are left alone.
 *
 * @package TachyonR2
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$tachyon_r2_options = [ 'tachyon_r2', 'tachyon_r2_backfill', 'tachyon_r2_backfill_dismissed', 'tachyon_r2_backfill_lock' ];

delete_site_option( 'tachyon_r2' );
if ( is_multisite() ) {
	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $tachyon_r2_site_id ) {
		switch_to_blog( (int) $tachyon_r2_site_id );
		array_map( 'delete_option', $tachyon_r2_options );
		delete_transient( 'tachyon_r2_pending' );
		wp_clear_scheduled_hook( 'tachyon_r2_backfill' );
		restore_current_blog();
	}
} else {
	array_map( 'delete_option', $tachyon_r2_options );
	delete_transient( 'tachyon_r2_pending' );
	wp_clear_scheduled_hook( 'tachyon_r2_backfill' );
}
