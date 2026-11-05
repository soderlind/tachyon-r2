<?php
/**
 * Stream mode: route image uploads to tachyon-r2:// paths.
 *
 * @package TachyonR2
 */

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2;

/**
 * Swaps the uploads basedir for the wrapper while a supported image is being uploaded.
 *
 * baseurl is unchanged, so attachment URLs and Tachyon detection keep working.
 */
final class Router {

	private static bool $active = false;

	public static function init(): void {
		add_filter( 'wp_handle_upload_prefilter', [ self::class, 'prefilter' ], 999 );
		add_filter( 'wp_handle_sideload_prefilter', [ self::class, 'prefilter' ], 999 );
		add_filter( 'upload_dir', [ self::class, 'upload_dir' ], 999 );
		add_filter( 'wp_handle_upload', [ self::class, 'handled' ], PHP_INT_MAX );
		add_filter( 'wp_generate_attachment_metadata', [ self::class, 'done' ], PHP_INT_MAX );
		add_action( 'shutdown', [ self::class, 'deactivate' ] );
	}

	public static function active(): bool {
		return self::$active;
	}

	public static function deactivate(): void {
		self::$active = false;
	}

	/**
	 * @param array<string, mixed> $file Uploaded file.
	 * @return array<string, mixed>
	 */
	public static function prefilter( $file ) {
		self::$active = is_array( $file )
			&& empty( $file['error'] )
			&& 'stream' === Config::mode()
			&& Config::is_configured()
			&& Keys::supported( (string) ( $file['name'] ?? '' ) );
		return $file;
	}

	/**
	 * @param array<string, mixed> $uploads wp_upload_dir() result.
	 * @return array<string, mixed>
	 */
	public static function upload_dir( $uploads ) {
		if ( ! self::$active || ! is_array( $uploads ) || ! empty( $uploads['error'] ) || Keys::is_wrapper( (string) $uploads['basedir'] ) ) {
			return $uploads;
		}
		$base               = Keys::wrapper_base( (string) $uploads['basedir'], Config::key_prefix() );
		$uploads['path']    = $base . (string) $uploads['subdir'];
		$uploads['basedir'] = $base;
		return $uploads;
	}

	/**
	 * A failed upload ends routing.
	 *
	 * @param array<string, mixed> $upload Upload result.
	 * @return array<string, mixed>
	 */
	public static function handled( $upload ) {
		if ( is_array( $upload ) && ! empty( $upload['error'] ) ) {
			self::$active = false;
		}
		return $upload;
	}

	/**
	 * Sub-sizes are created; stop routing.
	 *
	 * @param mixed $metadata Metadata.
	 * @return mixed
	 */
	public static function done( $metadata ) {
		self::$active = false;
		return $metadata;
	}
}
