<?php

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2\Tests;

use Soderlind\Plugin\TachyonR2\Storage;
use WP_Error;

/**
 * In-memory Storage for unit tests.
 */
final class MemoryStorage implements Storage {

	/** @var array<string, string> */
	public array $objects = [];

	/** @var string[] */
	public array $calls = [];

	public function put( string $key, string $body, string $content_type = '' ) {
		$this->calls[]         = "PUT $key";
		$this->objects[ $key ] = $body;
		return true;
	}

	public function put_file( string $key, string $path, string $content_type = '' ) {
		return $this->put( $key, (string) file_get_contents( $path ) );
	}

	public function get( string $key ) {
		$this->calls[] = "GET $key";
		return $this->objects[ $key ] ?? new WP_Error( 'not_found', 'NoSuchKey' );
	}

	public function get_to_file( string $key, string $path ) {
		$body = $this->get( $key );
		if ( $body instanceof WP_Error ) {
			return $body;
		}
		file_put_contents( $path, $body );
		return true;
	}

	public function head( string $key ) {
		$this->calls[] = "HEAD $key";
		return isset( $this->objects[ $key ] )
			? [ 'size' => strlen( $this->objects[ $key ] ), 'mtime' => 1, 'type' => '' ]
			: new WP_Error( 'not_found', 'Not Found' );
	}

	public function delete( string $key ) {
		$this->calls[] = "DELETE $key";
		unset( $this->objects[ $key ] );
		return true;
	}

	public function copy( string $from, string $to ) {
		if ( ! isset( $this->objects[ $from ] ) ) {
			return new WP_Error( 'not_found', 'NoSuchKey' );
		}
		$this->objects[ $to ] = $this->objects[ $from ];
		return true;
	}

	public function list( string $prefix, string $delimiter = '', int $max = 1000, ?string $token = null ) {
		$this->calls[] = "LIST $prefix";
		$keys          = [];
		$prefixes      = [];
		foreach ( $this->objects as $key => $body ) {
			if ( ! str_starts_with( $key, $prefix ) ) {
				continue;
			}
			$rest = substr( $key, strlen( $prefix ) );
			if ( '' !== $delimiter && false !== ( $pos = strpos( $rest, $delimiter ) ) ) {
				$prefixes[ $prefix . substr( $rest, 0, $pos + 1 ) ] = true;
				continue;
			}
			$keys[] = [ 'key' => $key, 'size' => strlen( $body ), 'mtime' => 1 ];
		}
		return [ 'keys' => array_slice( $keys, 0, $max ), 'prefixes' => array_keys( $prefixes ), 'next' => null ];
	}
}
