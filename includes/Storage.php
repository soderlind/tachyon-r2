<?php
/**
 * Object storage contract.
 *
 * @package TachyonR2
 */

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2;

/**
 * Minimal object store used by the plugin. Errors are WP_Error.
 */
interface Storage {

	/**
	 * Upload a body.
	 *
	 * @return true|\WP_Error
	 */
	public function put( string $key, string $body, string $content_type = '' );

	/**
	 * Upload a local file.
	 *
	 * @return true|\WP_Error
	 */
	public function put_file( string $key, string $path, string $content_type = '' );

	/**
	 * Download a body.
	 *
	 * @return string|\WP_Error
	 */
	public function get( string $key );

	/**
	 * Download to a local file.
	 *
	 * @return true|\WP_Error
	 */
	public function get_to_file( string $key, string $path );

	/**
	 * Object metadata: size, mtime, type.
	 *
	 * @return array{size:int, mtime:int, type:string}|\WP_Error
	 */
	public function head( string $key );

	/**
	 * Delete an object (missing objects are not an error).
	 *
	 * @return true|\WP_Error
	 */
	public function delete( string $key );

	/**
	 * Server-side copy.
	 *
	 * @return true|\WP_Error
	 */
	public function copy( string $from, string $to );

	/**
	 * List one page of keys.
	 *
	 * @return array{keys:array<int,array{key:string,size:int,mtime:int}>, prefixes:array<int,string>, next:?string}|\WP_Error
	 */
	public function list( string $prefix, string $delimiter = '', int $max = 1000, ?string $token = null );
}
