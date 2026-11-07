<?php
/**
 * Plugin status shared by WP-CLI and the Abilities API.
 *
 * @package TachyonR2
 */

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2;

/**
 * Reads configuration and checks the R2 bucket connection.
 */
final class Status {

	/**
	 * Get plugin and bucket status.
	 *
	 * @return array<string, bool|string|null>
	 */
	public static function get(): array {
		$configured = Config::is_configured();
		$connection = $configured ? Client::from_config()->check() : null;
		$failed     = is_wp_error( $connection );

		return [
			'tachyon'          => Config::tachyon_ready() ? (string) TACHYON_URL : null,
			'configured'       => $configured,
			'endpoint'         => Config::endpoint(),
			'bucket'           => (string) Config::get( 'bucket' ),
			'key_prefix'       => Config::key_prefix(),
			'mode'             => Config::mode(),
			'configured_mode'  => (string) Config::get( 'mode' ),
			'skip_thumbnails'  => Config::skip_thumbnails(),
			'connection'       => ! $configured ? 'not_configured' : ( $failed ? 'failed' : 'connected' ),
			'connection_error' => $failed ? $connection->get_error_message() : null,
		];
	}
}
