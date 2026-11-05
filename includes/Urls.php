<?php
/**
 * Serve images through Tachyon when they may not exist locally.
 *
 * @package TachyonR2
 */

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2;

/**
 * URL adjustments for offload, stream and skip-thumbnail setups.
 */
final class Urls {

	public static function init(): void {
		if ( ! Config::images_may_be_remote() ) {
			return;
		}
		// The admin (media library, editor) must also use Tachyon.
		add_filter( 'tachyon_disable_in_admin', '__return_false' );
		// Tachyon skips image_downsize for REST edit context; local sizes may not exist.
		add_filter( 'tachyon_override_image_downsize', '__return_false', PHP_INT_MAX );
		if ( 'sync' !== Config::mode() ) {
			add_filter( 'wp_get_attachment_url', [ self::class, 'attachment_url' ], 99, 2 );
		}
	}

	/**
	 * Full-size URL for images that are only in R2.
	 *
	 * @param string $url URL.
	 * @param int    $id  Attachment ID.
	 */
	public static function attachment_url( $url, $id ) {
		// Tachyon validates URLs against the uploads baseurl inside image_downsize.
		if ( ! is_string( $url ) || doing_filter( 'image_downsize' ) || ! function_exists( 'tachyon_url' ) ) {
			return $url;
		}
		$id = (int) $id;
		if ( ! Keys::supported( $url ) || ! get_post_meta( $id, Uploader::META, true ) ) {
			return $url;
		}
		$file = (string) get_attached_file( $id, true );
		if ( '' !== $file && file_exists( $file ) ) {
			return $url;
		}
		return tachyon_url( $url );
	}
}
