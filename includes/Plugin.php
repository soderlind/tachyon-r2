<?php
/**
 * Bootstrap.
 *
 * @package TachyonR2
 */

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2;

/**
 * Wires the components.
 */
final class Plugin {

	private static ?Storage $storage = null;

	public static function boot(): void {
		Settings::init();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'tachyon-r2', CLI::class );
		}
		if ( ! Config::is_configured() ) {
			return;
		}
		StreamWrapper::register( self::storage() );
		Uploader::init();
		Router::init();
		Sizes::init();
		Urls::init();
	}

	public static function storage(): Storage {
		if ( null === self::$storage ) {
			self::$storage = Client::from_config();
		}
		return self::$storage;
	}

	/**
	 * Replace the storage backend (tests).
	 *
	 * @param Storage|null $storage Storage.
	 */
	public static function set_storage( ?Storage $storage ): void {
		self::$storage = $storage;
	}
}
