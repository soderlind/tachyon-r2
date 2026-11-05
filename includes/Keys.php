<?php
/**
 * Map local upload paths to R2 object keys.
 *
 * @package TachyonR2
 */

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2;

/**
 * Key helpers. Keys mirror the Tachyon URL path: <prefix>/<path below the uploads root>.
 */
final class Keys {

	public const SCHEME = 'tachyon-r2';

	/** Extensions the Worker serves. */
	public const EXTENSIONS = [ 'gif', 'jpg', 'jpeg', 'png', 'webp', 'avif' ];

	/**
	 * Whether the Worker can serve this file.
	 *
	 * @param string $path File name or path.
	 */
	public static function supported( string $path ): bool {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $ext, self::EXTENSIONS, true );
	}

	/**
	 * Network uploads root: basedir without a trailing /sites/N.
	 *
	 * Tachyon strips /sites/N from the uploads baseurl only, so keys keep it.
	 *
	 * @param string $basedir Uploads basedir.
	 */
	public static function root( string $basedir ): string {
		$basedir = rtrim( str_replace( '\\', '/', $basedir ), '/' );
		return (string) preg_replace( '#/sites/\d+$#', '', $basedir );
	}

	/**
	 * Object key for a local or wrapper path.
	 *
	 * @param string $path    Absolute path or tachyon-r2:// path.
	 * @param string $basedir Uploads basedir.
	 * @param string $prefix  Key prefix.
	 */
	public static function for_path( string $path, string $basedir, string $prefix ): ?string {
		$path = str_replace( '\\', '/', $path );
		if ( self::is_wrapper( $path ) ) {
			return self::from_wrapper( $path );
		}
		$root = self::root( $basedir ) . '/';
		if ( ! str_starts_with( $path, $root ) ) {
			return null;
		}
		return self::join( $prefix, substr( $path, strlen( $root ) ) );
	}

	/**
	 * Join prefix and relative path.
	 *
	 * @param string $prefix   Prefix.
	 * @param string $relative Relative path.
	 */
	public static function join( string $prefix, string $relative ): string {
		$prefix   = trim( $prefix, '/' );
		$relative = ltrim( $relative, '/' );
		return '' === $prefix ? $relative : $prefix . '/' . $relative;
	}

	/**
	 * Wrapper base for a basedir, e.g. tachyon-r2://uploads/sites/3.
	 *
	 * @param string $basedir Uploads basedir.
	 * @param string $prefix  Key prefix.
	 */
	public static function wrapper_base( string $basedir, string $prefix ): string {
		$basedir = rtrim( str_replace( '\\', '/', $basedir ), '/' );
		$root    = self::root( $basedir );
		return rtrim( self::SCHEME . '://' . self::join( $prefix, substr( $basedir, strlen( $root ) ) ), '/' );
	}

	/** @param string $path Path. */
	public static function is_wrapper( string $path ): bool {
		return str_starts_with( $path, self::SCHEME . '://' );
	}

	/** @param string $path tachyon-r2:// path. */
	public static function from_wrapper( string $path ): string {
		return ltrim( substr( $path, strlen( self::SCHEME . '://' ) ), '/' );
	}

	/**
	 * Every file of an attachment: the attached file, sizes, original and backups.
	 *
	 * @param int $id Attachment ID.
	 * @return string[] Absolute (or wrapper) paths.
	 */
	public static function files( int $id ): array {
		$file = (string) get_attached_file( $id, true );
		if ( '' === $file ) {
			return [];
		}
		$dir   = dirname( $file );
		$files = [ $file ];
		$meta  = wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) ) {
			foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $dir . '/' . $size['file'];
				}
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$files[] = $dir . '/' . $meta['original_image'];
			}
		}
		$backups = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
		if ( is_array( $backups ) ) {
			foreach ( $backups as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $dir . '/' . $size['file'];
				}
			}
		}
		return array_values( array_unique( $files ) );
	}
}
