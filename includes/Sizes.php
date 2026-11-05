<?php
/**
 * Skip thumbnails: let Tachyon create sizes on the fly.
 *
 * @package TachyonR2
 */

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2;

/**
 * Stops core from writing sub-size files and records virtual sizes in metadata,
 * so srcset, image_downsize and the editor still know every size.
 */
final class Sizes {

	public static function init(): void {
		add_filter( 'intermediate_image_sizes_advanced', [ self::class, 'skip' ], 999, 3 );
		add_filter( 'wp_generate_attachment_metadata', [ self::class, 'virtual_sizes' ], 5, 3 );
	}

	/**
	 * @param array<string, mixed> $sizes    Sizes to create.
	 * @param array<string, mixed> $metadata Metadata.
	 * @param int                  $id       Attachment ID.
	 * @return array<string, mixed>
	 */
	public static function skip( $sizes, $metadata = [], $id = 0 ) {
		if ( ! Config::skip_thumbnails() ) {
			return $sizes;
		}
		$file = (string) ( $metadata['file'] ?? get_attached_file( (int) $id, true ) );
		return Keys::supported( $file ) ? [] : $sizes;
	}

	/**
	 * @param array<string, mixed> $metadata Metadata.
	 * @param int                  $id       Attachment ID.
	 * @param string               $context  Context.
	 * @return array<string, mixed>
	 */
	public static function virtual_sizes( $metadata, $id = 0, $context = 'create' ) {
		if ( ! Config::skip_thumbnails() || ! is_array( $metadata ) || empty( $metadata['file'] ) || ! Keys::supported( (string) $metadata['file'] ) ) {
			return $metadata;
		}
		return self::add( $metadata );
	}

	/**
	 * Add missing registered sizes as virtual entries.
	 *
	 * @param array<string, mixed> $metadata Metadata with width, height, file.
	 * @return array<string, mixed>
	 */
	public static function add( array $metadata ): array {
		$width  = (int) ( $metadata['width'] ?? 0 );
		$height = (int) ( $metadata['height'] ?? 0 );
		if ( $width < 1 || $height < 1 ) {
			return $metadata;
		}
		// Core names sub-sizes after the (possibly -scaled) attached file.
		$source = basename( (string) $metadata['file'] );
		$ext    = pathinfo( $source, PATHINFO_EXTENSION );
		$name   = pathinfo( $source, PATHINFO_FILENAME );
		$mime   = Client::mime( $source );

		$metadata['sizes'] = is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : [];
		foreach ( wp_get_registered_image_subsizes() as $size => $data ) {
			if ( isset( $metadata['sizes'][ $size ] ) ) {
				continue;
			}
			$dims = image_resize_dimensions( $width, $height, (int) $data['width'], (int) $data['height'], $data['crop'] );
			if ( ! $dims ) {
				continue;
			}
			$w = (int) $dims[4];
			$h = (int) $dims[5];
			$metadata['sizes'][ $size ] = [
				'file'      => "{$name}-{$w}x{$h}.{$ext}",
				'width'     => $w,
				'height'    => $h,
				'mime-type' => $mime,
				'filesize'  => 0,
			];
		}
		return $metadata;
	}
}
