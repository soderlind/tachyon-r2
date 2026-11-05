<?php
/**
 * Stream wrapper for tachyon-r2:// paths.
 *
 * @package TachyonR2
 */

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2;

/**
 * Lets core write uploads and image sub-sizes straight to R2 (stream mode).
 *
 * Reads buffer the whole object in php://temp; writes buffer and PUT on close.
 */
final class StreamWrapper {

	/** @var resource|null Set by PHP. */
	public $context;

	private static ?Storage $storage = null;

	/** @var array<string, array<string, mixed>|false> Per-request stat cache. */
	private static array $stat_cache = [];

	/** @var array<string, true> Keys written during this request. */
	private static array $written = [];

	/** @var resource|null */
	private $buffer = null;

	private string $key = '';

	private bool $writable = false;

	private bool $dirty = false;

	/** @var string[] */
	private array $entries = [];

	/**
	 * Register the wrapper.
	 *
	 * @param Storage $storage Storage backend.
	 */
	public static function register( Storage $storage ): void {
		self::$storage = $storage;
		if ( in_array( Keys::SCHEME, stream_get_wrappers(), true ) ) {
			stream_wrapper_unregister( Keys::SCHEME );
		}
		stream_wrapper_register( Keys::SCHEME, self::class, STREAM_IS_URL );
	}

	/**
	 * Whether a key was written through the wrapper in this request.
	 *
	 * @param string $key Object key.
	 */
	public static function wrote( string $key ): bool {
		return isset( self::$written[ $key ] );
	}

	/** Reset request state (tests). */
	public static function reset(): void {
		self::$stat_cache = [];
		self::$written    = [];
	}

	private static function storage(): Storage {
		if ( null === self::$storage ) {
			self::$storage = Client::from_config();
		}
		return self::$storage;
	}

	/**
	 * @param string      $path        Path.
	 * @param string      $mode        Mode.
	 * @param int         $options     Options.
	 * @param string|null $opened_path Opened path.
	 */
	public function stream_open( string $path, string $mode, int $options, ?string &$opened_path ): bool {
		$this->key      = Keys::from_wrapper( $path );
		$this->buffer   = fopen( 'php://temp', 'w+b' );
		$base           = str_replace( [ 'b', 't' ], '', $mode );
		$this->writable = '' !== trim( $base, 'r' ) || str_contains( $base, '+' );
		$report         = (bool) ( $options & STREAM_REPORT_ERRORS );

		if ( 'r' === $base[0] || 'a' === $base[0] || ( 'c' === $base[0] ) ) {
			$body = self::storage()->get( $this->key );
			if ( is_wp_error( $body ) ) {
				if ( 'r' === $base[0] ) {
					if ( $report ) {
						trigger_error( esc_html( $body->get_error_message() ), E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
					}
					return false;
				}
			} else {
				fwrite( $this->buffer, $body );
				if ( 'a' !== $base[0] ) {
					rewind( $this->buffer );
				}
			}
		} elseif ( 'x' === $base[0] && false !== $this->url_stat( $path, STREAM_URL_STAT_QUIET ) ) {
			return false;
		}
		if ( 'w' === $base[0] || 'x' === $base[0] ) {
			$this->dirty = true;
		}
		return true;
	}

	public function stream_read( int $count ): string|false {
		return fread( $this->buffer, $count );
	}

	public function stream_write( string $data ): int {
		if ( ! $this->writable ) {
			return 0;
		}
		$this->dirty = true;
		return (int) fwrite( $this->buffer, $data );
	}

	public function stream_eof(): bool {
		return feof( $this->buffer );
	}

	public function stream_tell(): int {
		return (int) ftell( $this->buffer );
	}

	public function stream_seek( int $offset, int $whence = SEEK_SET ): bool {
		return 0 === fseek( $this->buffer, $offset, $whence );
	}

	public function stream_flush(): bool {
		if ( ! $this->dirty ) {
			return true;
		}
		$pos = ftell( $this->buffer );
		rewind( $this->buffer );
		$body = (string) stream_get_contents( $this->buffer );
		fseek( $this->buffer, (int) $pos );
		$res = self::storage()->put( $this->key, $body );
		if ( is_wp_error( $res ) ) {
			trigger_error( esc_html( $res->get_error_message() ), E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return false;
		}
		$this->dirty                       = false;
		self::$written[ $this->key ]       = true;
		self::$stat_cache[ $this->key ]    = self::file_stat( strlen( $body ), time() );
		return true;
	}

	public function stream_close(): void {
		$this->stream_flush();
		if ( is_resource( $this->buffer ) ) {
			fclose( $this->buffer );
		}
		$this->buffer = null;
	}

	/** @return array<int|string, int>|false */
	public function stream_stat(): array|false {
		$stat = fstat( $this->buffer );
		return false === $stat ? false : self::file_stat( (int) $stat['size'], time() );
	}

	/**
	 * Locking is a no-op; objects are written atomically.
	 *
	 * @param int $operation Operation.
	 */
	public function stream_lock( int $operation ): bool {
		return true;
	}

	/**
	 * @param int $option Option.
	 * @param int $arg1   Arg.
	 * @param int $arg2   Arg.
	 */
	public function stream_set_option( int $option, int $arg1, ?int $arg2 ): bool {
		return false;
	}

	/**
	 * @param int $cast_as Cast type.
	 * @return resource|false
	 */
	public function stream_cast( int $cast_as ) {
		return false;
	}

	/**
	 * @param string $path  Path.
	 * @param int    $flags Flags.
	 * @return array<int|string, int>|false
	 */
	public function url_stat( string $path, int $flags ): array|false {
		$key = rtrim( Keys::from_wrapper( $path ), '/' );
		if ( array_key_exists( $key, self::$stat_cache ) ) {
			return self::$stat_cache[ $key ];
		}
		$stat = false;
		if ( '' === $key ) {
			$stat = self::dir_stat();
		} else {
			$head = self::storage()->head( $key );
			if ( ! is_wp_error( $head ) ) {
				$stat = self::file_stat( $head['size'], $head['mtime'] );
			} else {
				$list = self::storage()->list( $key . '/', '', 1 );
				if ( ! is_wp_error( $list ) && ( [] !== $list['keys'] || [] !== $list['prefixes'] ) ) {
					$stat = self::dir_stat();
				}
			}
		}
		self::$stat_cache[ $key ] = $stat;
		return $stat;
	}

	/** @param string $path Path. */
	public function unlink( string $path ): bool {
		$key = Keys::from_wrapper( $path );
		unset( self::$stat_cache[ $key ], self::$written[ $key ] );
		return ! is_wp_error( self::storage()->delete( $key ) );
	}

	/**
	 * @param string $from From path.
	 * @param string $to   To path.
	 */
	public function rename( string $from, string $to ): bool {
		$src = Keys::from_wrapper( $from );
		$dst = Keys::from_wrapper( $to );
		if ( is_wp_error( self::storage()->copy( $src, $dst ) ) ) {
			return false;
		}
		self::storage()->delete( $src );
		unset( self::$stat_cache[ $src ], self::$stat_cache[ $dst ], self::$written[ $src ] );
		self::$written[ $dst ] = true;
		return true;
	}

	/**
	 * Directories are implicit in R2.
	 *
	 * @param string $path    Path.
	 * @param int    $mode    Mode.
	 * @param int    $options Options.
	 */
	public function mkdir( string $path, int $mode, int $options ): bool {
		self::$stat_cache[ rtrim( Keys::from_wrapper( $path ), '/' ) ] = self::dir_stat();
		return true;
	}

	/**
	 * @param string $path    Path.
	 * @param int    $options Options.
	 */
	public function rmdir( string $path, int $options ): bool {
		unset( self::$stat_cache[ rtrim( Keys::from_wrapper( $path ), '/' ) ] );
		return true;
	}

	/**
	 * chmod/touch/chown are no-ops.
	 *
	 * @param string $path   Path.
	 * @param int    $option Option.
	 * @param mixed  $value  Value.
	 */
	public function stream_metadata( string $path, int $option, mixed $value ): bool {
		return true;
	}

	/**
	 * @param string $path    Path.
	 * @param int    $options Options.
	 */
	public function dir_opendir( string $path, int $options ): bool {
		$prefix = rtrim( Keys::from_wrapper( $path ), '/' );
		$prefix = '' === $prefix ? '' : $prefix . '/';
		$token  = null;
		$this->entries = [];
		do {
			$page = self::storage()->list( $prefix, '/', 1000, $token );
			if ( is_wp_error( $page ) ) {
				return false;
			}
			foreach ( $page['keys'] as $item ) {
				$name = substr( $item['key'], strlen( $prefix ) );
				if ( '' !== $name ) {
					$this->entries[] = $name;
				}
			}
			foreach ( $page['prefixes'] as $sub ) {
				$this->entries[] = rtrim( substr( $sub, strlen( $prefix ) ), '/' );
			}
			$token = $page['next'];
		} while ( null !== $token );
		return true;
	}

	public function dir_readdir(): string|false {
		$entry = current( $this->entries );
		next( $this->entries );
		return false === $entry ? false : (string) $entry;
	}

	public function dir_rewinddir(): bool {
		reset( $this->entries );
		return true;
	}

	public function dir_closedir(): bool {
		$this->entries = [];
		return true;
	}

	/** @return array<int|string, int> */
	private static function file_stat( int $size, int $mtime ): array {
		return self::stat( 0100666, $size, $mtime );
	}

	/** @return array<int|string, int> */
	private static function dir_stat(): array {
		return self::stat( 040777, 0, 0 );
	}

	/** @return array<int|string, int> */
	private static function stat( int $mode, int $size, int $mtime ): array {
		$values = [ 0, 0, $mode, 1, 0, 0, 0, $size, $mtime, $mtime, $mtime, -1, -1 ];
		$names  = [ 'dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size', 'atime', 'mtime', 'ctime', 'blksize', 'blocks' ];
		return array_merge( $values, array_combine( $names, $values ) );
	}
}
