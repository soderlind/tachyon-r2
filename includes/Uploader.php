<?php
/**
 * Copy attachment files to R2, remove local copies, and clean up on delete.
 *
 * @package TachyonR2
 */

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2;

use WP_Error;

/**
 * Upload pipeline for sync and offload modes; bookkeeping for all modes.
 */
final class Uploader {

	public const META  = '_tachyon_r2';
	public const ERROR = '_tachyon_r2_error';

	/** @var array<string, true> Keys uploaded this request. */
	private static array $uploaded = [];

	/** @var string[] Local files to delete at shutdown. */
	private static array $delete_local = [];

	/** @var string[] Temp files to delete at shutdown. */
	private static array $temp = [];

	public static function init(): void {
		add_filter( 'wp_update_attachment_metadata', [ self::class, 'on_metadata' ], 999, 2 );
		add_action( 'delete_attachment', [ self::class, 'on_delete' ] );
		add_filter( 'load_image_to_edit_path', [ self::class, 'edit_path' ], 10, 2 );
		add_action( 'shutdown', [ self::class, 'shutdown' ] );
	}

	/**
	 * Upload after each metadata update (core saves after every sub-size).
	 *
	 * @param mixed $data Metadata.
	 * @param int   $id   Attachment ID.
	 * @return mixed
	 */
	public static function on_metadata( $data, $id ) {
		$id = (int) $id;
		if ( ! Config::is_configured() || ! Keys::supported( (string) get_attached_file( $id, true ) ) ) {
			return $data;
		}
		// Make Keys::files() see the metadata being saved.
		$filter = static fn ( $meta, $post_id ) => (int) $post_id === $id && is_array( $data ) ? $data : $meta;
		add_filter( 'wp_get_attachment_metadata', $filter, 10, 2 );
		$result = self::sync( $id, 'sync' === Config::mode() );
		remove_filter( 'wp_get_attachment_metadata', $filter, 10 );

		if ( is_wp_error( $result ) ) {
			update_post_meta( $id, self::ERROR, $result->get_error_message() );
		}
		return $data;
	}

	/**
	 * Upload every file of an attachment that exists locally.
	 *
	 * @param int  $id         Attachment ID.
	 * @param bool $keep_local Keep local copies.
	 * @param bool $force      Upload even if already uploaded this request.
	 * @return int|\WP_Error Number of files uploaded.
	 */
	public static function sync( int $id, bool $keep_local = true, bool $force = false ) {
		$storage = Plugin::storage();
		$basedir = (string) wp_get_upload_dir()['basedir'];
		$prefix  = Config::key_prefix();
		$count   = 0;
		$errors  = [];
		$in_r2   = false;

		foreach ( Keys::files( $id ) as $path ) {
			$key = Keys::for_path( $path, $basedir, $prefix );
			if ( null === $key ) {
				continue;
			}
			if ( Keys::is_wrapper( $path ) || StreamWrapper::wrote( $key ) || ( isset( self::$uploaded[ $key ] ) && ! $force ) ) {
				$in_r2 = true;
				continue;
			}
			if ( ! file_exists( $path ) ) {
				continue;
			}
			$res = $storage->put_file( $key, $path, Client::mime( $key ) );
			if ( is_wp_error( $res ) ) {
				$errors[] = $key . ': ' . $res->get_error_message();
				continue;
			}
			self::$uploaded[ $key ] = true;
			$in_r2                  = true;
			++$count;
			if ( ! $keep_local ) {
				self::$delete_local[ $path ] = $path;
			}
		}

		if ( $in_r2 ) {
			update_post_meta( $id, self::META, 1 );
		}
		if ( [] !== $errors ) {
			return new WP_Error( 'tachyon_r2_upload', implode( '; ', $errors ) );
		}
		delete_post_meta( $id, self::ERROR );
		return $count;
	}

	/**
	 * Download every file of an attachment that is missing locally.
	 *
	 * @param int $id Attachment ID.
	 * @return int|\WP_Error Number of files downloaded.
	 */
	public static function restore( int $id ) {
		$storage = Plugin::storage();
		$basedir = (string) wp_get_upload_dir()['basedir'];
		$prefix  = Config::key_prefix();
		$count   = 0;
		$errors  = [];
		foreach ( Keys::files( $id ) as $path ) {
			$key = Keys::for_path( $path, $basedir, $prefix );
			if ( null === $key || Keys::is_wrapper( $path ) || file_exists( $path ) ) {
				continue;
			}
			wp_mkdir_p( dirname( $path ) );
			$res = $storage->get_to_file( $key, $path );
			if ( is_wp_error( $res ) ) {
				// Virtual sizes (skip thumbnails) never exist in R2.
				if ( 'not_found' !== $res->get_error_code() ) {
					$errors[] = $key . ': ' . $res->get_error_message();
				}
				continue;
			}
			unset( self::$delete_local[ $path ] );
			++$count;
		}
		return [] === $errors ? $count : new WP_Error( 'tachyon_r2_restore', implode( '; ', $errors ) );
	}

	/**
	 * Delete R2 objects with the attachment.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function on_delete( $id ): void {
		$id = (int) $id;
		if ( ! get_post_meta( $id, self::META, true ) || ! Config::is_configured() ) {
			return;
		}
		$storage = Plugin::storage();
		$basedir = (string) wp_get_upload_dir()['basedir'];
		$prefix  = Config::key_prefix();
		foreach ( Keys::files( $id ) as $path ) {
			$key = Keys::for_path( $path, $basedir, $prefix );
			if ( null !== $key ) {
				$storage->delete( $key );
			}
			unset( self::$delete_local[ $path ] );
		}
	}

	/**
	 * Fetch the original from R2 when the image editor needs it and it is not local.
	 *
	 * @param string|false $path Path.
	 * @param int          $id   Attachment ID.
	 * @return string|false
	 */
	public static function edit_path( $path, $id ) {
		$id   = (int) $id;
		$file = (string) get_attached_file( $id );
		if ( '' === $file || file_exists( $file ) || ! get_post_meta( $id, self::META, true ) || ! Config::is_configured() ) {
			return $path;
		}
		$key = Keys::for_path( $file, (string) wp_get_upload_dir()['basedir'], Config::key_prefix() );
		if ( null === $key ) {
			return $path;
		}
		$tmp = wp_tempnam( basename( $file ) );
		if ( ! $tmp ) {
			return $path;
		}
		// Keep the extension so editors detect the type.
		$typed = $tmp . '.' . pathinfo( $file, PATHINFO_EXTENSION );
		@rename( $tmp, $typed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( is_wp_error( Plugin::storage()->get_to_file( $key, $typed ) ) ) {
			wp_delete_file( $typed );
			return $path;
		}
		self::$temp[] = $typed;
		return $typed;
	}

	/** Remove offloaded local copies and temp files. */
	public static function shutdown(): void {
		foreach ( array_merge( self::$delete_local, self::$temp ) as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		self::$delete_local = [];
		self::$temp         = [];
	}
}
