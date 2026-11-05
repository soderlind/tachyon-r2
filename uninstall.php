<?php
/**
 * Remove settings. Objects in R2 and attachment meta are left alone.
 *
 * @package TachyonR2
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_site_option( 'tachyon_r2' );
delete_option( 'tachyon_r2' );
