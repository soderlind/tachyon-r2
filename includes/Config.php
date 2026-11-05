<?php
/**
 * Plugin configuration: stored option, overridden by constants.
 *
 * @package TachyonR2
 */

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2;

/**
 * Reads and writes the `tachyon_r2` option.
 */
final class Config {

	public const OPTION = 'tachyon_r2';

	public const MODES = [ 'sync', 'offload', 'stream' ];

	/** Field => constant. */
	public const CONSTANTS = [
		'account_id'        => 'TACHYON_R2_ACCOUNT_ID',
		'access_key_id'     => 'TACHYON_R2_ACCESS_KEY_ID',
		'secret_access_key' => 'TACHYON_R2_SECRET_ACCESS_KEY',
		'bucket'            => 'TACHYON_R2_BUCKET',
		'key_prefix'        => 'TACHYON_R2_KEY_PREFIX',
		'mode'              => 'TACHYON_R2_MODE',
		'skip_thumbnails'   => 'TACHYON_R2_SKIP_THUMBNAILS',
		'endpoint'          => 'TACHYON_R2_ENDPOINT',
	];

	public const DEFAULTS = [
		'account_id'        => '',
		'access_key_id'     => '',
		'secret_access_key' => '',
		'bucket'            => '',
		'key_prefix'        => null,
		'mode'              => 'sync',
		'skip_thumbnails'   => false,
		'endpoint'          => '',
	];

	/** @var array<string, mixed>|null */
	private static ?array $cache = null;

	/**
	 * All settings, constants applied.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$stored   = is_multisite() ? get_site_option( self::OPTION, [] ) : get_option( self::OPTION, [] );
		$settings = array_merge( self::DEFAULTS, is_array( $stored ) ? array_intersect_key( $stored, self::DEFAULTS ) : [] );
		foreach ( self::CONSTANTS as $field => $constant ) {
			if ( defined( $constant ) ) {
				$settings[ $field ] = constant( $constant );
			}
		}
		$settings['skip_thumbnails'] = (bool) filter_var( $settings['skip_thumbnails'], FILTER_VALIDATE_BOOLEAN );
		if ( ! in_array( $settings['mode'], self::MODES, true ) ) {
			$settings['mode'] = 'sync';
		}
		self::$cache = $settings;
		return $settings;
	}

	/**
	 * One setting.
	 *
	 * @param string $field Field name.
	 * @return mixed
	 */
	public static function get( string $field ) {
		return self::all()[ $field ] ?? null;
	}

	/**
	 * Whether a constant locks the field.
	 *
	 * @param string $field Field name.
	 */
	public static function locked( string $field ): bool {
		return isset( self::CONSTANTS[ $field ] ) && defined( self::CONSTANTS[ $field ] );
	}

	/**
	 * Persist stored settings (constants are never written).
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	public static function save( array $settings ): void {
		$settings = array_intersect_key( $settings, self::DEFAULTS );
		if ( is_multisite() ) {
			update_site_option( self::OPTION, $settings );
		} else {
			update_option( self::OPTION, $settings, false );
		}
		self::$cache = null;
	}

	/**
	 * Stored (not constant-derived) settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function stored(): array {
		$stored = is_multisite() ? get_site_option( self::OPTION, [] ) : get_option( self::OPTION, [] );
		return is_array( $stored ) ? $stored : [];
	}

	/** Drop the request cache. */
	public static function reset(): void {
		self::$cache = null;
	}

	/** Account, keys and bucket are set. */
	public static function is_configured(): bool {
		$s = self::all();
		return '' !== (string) $s['bucket']
			&& '' !== (string) $s['access_key_id']
			&& '' !== (string) $s['secret_access_key']
			&& ( '' !== (string) $s['account_id'] || '' !== (string) $s['endpoint'] );
	}

	/** tachyon-plugin is active and TACHYON_URL is defined. */
	public static function tachyon_ready(): bool {
		return function_exists( 'tachyon_url' ) && defined( 'TACHYON_URL' ) && '' !== (string) TACHYON_URL;
	}

	/** Mode in effect: anything but sync needs Tachyon to serve images. */
	public static function mode(): string {
		$mode = (string) self::get( 'mode' );
		return self::tachyon_ready() ? $mode : 'sync';
	}

	/** Skip thumbnails in effect. */
	public static function skip_thumbnails(): bool {
		return self::tachyon_ready() && (bool) self::get( 'skip_thumbnails' );
	}

	/** Images may be missing locally, so URLs must go through Tachyon. */
	public static function images_may_be_remote(): bool {
		return 'sync' !== self::mode() || self::skip_thumbnails();
	}

	/** Key prefix; defaults to the path of TACHYON_URL. */
	public static function key_prefix(): string {
		$prefix = self::get( 'key_prefix' );
		if ( null === $prefix ) {
			$prefix = defined( 'TACHYON_URL' ) ? (string) wp_parse_url( (string) TACHYON_URL, PHP_URL_PATH ) : '';
		}
		return trim( (string) $prefix, '/' );
	}

	/** S3 endpoint URL. */
	public static function endpoint(): string {
		$endpoint = (string) self::get( 'endpoint' );
		if ( '' === $endpoint ) {
			$endpoint = sprintf( 'https://%s.r2.cloudflarestorage.com', (string) self::get( 'account_id' ) );
		}
		return untrailingslashit( $endpoint );
	}
}
