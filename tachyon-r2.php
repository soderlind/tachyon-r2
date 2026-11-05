<?php
/**
 * Plugin Name:       Tachyon R2
 * Plugin URI:        https://github.com/soderlind/tachyon-r2
 * Description:       Store WordPress images in Cloudflare R2 for the Tachyon Edge Worker. Sync, offload or stream uploads.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Author:            Per Soderlind
 * Author URI:        https://soderlind.no
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       tachyon-r2
 *
 * @package TachyonR2
 */

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.1.0';

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( str_starts_with( $class, $prefix ) ) {
			$file = __DIR__ . '/includes/' . substr( $class, strlen( $prefix ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	}
);

// After all plugins, so tachyon-plugin's tachyon_url() is known.
add_action( 'plugins_loaded', [ Plugin::class, 'boot' ], 20 );
