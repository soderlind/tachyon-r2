<?php
/**
 * WP-CLI commands.
 *
 * @package TachyonR2
 */

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2;

use WP_CLI;

/**
 * Manage Tachyon R2 media.
 */
final class CLI {

	/**
	 * Show configuration and check the bucket connection.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tachyon-r2 status
	 *
	 * @param array<int, string>    $args       Args.
	 * @param array<string, string> $assoc_args Assoc args.
	 */
	public function status( array $args, array $assoc_args ): void {
		$status = Status::get();
		$rows = [
			[ 'field' => 'tachyon', 'value' => $status['tachyon'] ?? 'not detected' ],
			[ 'field' => 'configured', 'value' => $status['configured'] ? 'yes' : 'no' ],
			[ 'field' => 'endpoint', 'value' => $status['endpoint'] ],
			[ 'field' => 'bucket', 'value' => $status['bucket'] ],
			[ 'field' => 'key_prefix', 'value' => $status['key_prefix'] ],
			[ 'field' => 'mode', 'value' => $status['mode'] . ( $status['mode'] !== $status['configured_mode'] ? ' (configured: ' . $status['configured_mode'] . ')' : '' ) ],
			[ 'field' => 'skip_thumbnails', 'value' => $status['skip_thumbnails'] ? 'yes' : 'no' ],
		];
		WP_CLI\Utils\format_items( 'table', $rows, [ 'field', 'value' ] );
		if ( ! $status['configured'] ) {
			WP_CLI::error( 'Not configured.' );
		}
		if ( 'failed' === $status['connection'] ) {
			WP_CLI::error( 'Connection failed: ' . $status['connection_error'] );
		}
		WP_CLI::success( 'Connected to the bucket.' );
	}

	/**
	 * Upload existing image attachments to R2.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs.
	 *
	 * [--all]
	 * : All image attachments on the current site.
	 *
	 * [--dry-run]
	 * : List what would be uploaded.
	 *
	 * [--keep-local]
	 * : Keep local files even in offload or stream mode.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tachyon-r2 sync --all
	 *     wp tachyon-r2 sync 12 13 --keep-local
	 *
	 * @param array<int, string>    $args       Args.
	 * @param array<string, string> $assoc_args Assoc args.
	 */
	public function sync( array $args, array $assoc_args ): void {
		$this->require_config();
		$ids        = $this->ids( $args, $assoc_args );
		$dry        = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$keep_local = 'sync' === Config::mode() || (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'keep-local', false );
		$basedir    = (string) wp_get_upload_dir()['basedir'];
		$failed     = 0;
		$total      = 0;

		foreach ( $ids as $id ) {
			if ( $dry ) {
				foreach ( Keys::files( $id ) as $path ) {
					if ( file_exists( $path ) ) {
						WP_CLI::log( sprintf( '%d: %s', $id, Keys::for_path( $path, $basedir, Config::key_prefix() ) ) );
					}
				}
				continue;
			}
			$res = Uploader::sync( $id, $keep_local, true );
			if ( is_wp_error( $res ) ) {
				++$failed;
				update_post_meta( $id, Uploader::ERROR, $res->get_error_message() );
				WP_CLI::warning( sprintf( '%d: %s', $id, $res->get_error_message() ) );
				continue;
			}
			$total += $res;
			WP_CLI::log( sprintf( '%d: %d file(s) uploaded', $id, $res ) );
		}
		Uploader::shutdown();
		if ( $dry ) {
			WP_CLI::success( sprintf( '%d attachment(s) checked (dry run).', count( $ids ) ) );
			return;
		}
		$failed
			? WP_CLI::error( sprintf( '%d attachment(s) failed.', $failed ) )
			: WP_CLI::success( sprintf( '%d file(s) uploaded from %d attachment(s).', $total, count( $ids ) ) );
	}

	/**
	 * Download image attachments from R2 to local disk.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs.
	 *
	 * [--all]
	 * : All image attachments on the current site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tachyon-r2 restore --all
	 *
	 * @param array<int, string>    $args       Args.
	 * @param array<string, string> $assoc_args Assoc args.
	 */
	public function restore( array $args, array $assoc_args ): void {
		$this->require_config();
		$failed = 0;
		$total  = 0;
		foreach ( $this->ids( $args, $assoc_args ) as $id ) {
			$res = Uploader::restore( $id );
			if ( is_wp_error( $res ) ) {
				++$failed;
				WP_CLI::warning( sprintf( '%d: %s', $id, $res->get_error_message() ) );
				continue;
			}
			$total += $res;
			WP_CLI::log( sprintf( '%d: %d file(s) restored', $id, $res ) );
		}
		$failed
			? WP_CLI::error( sprintf( '%d attachment(s) failed.', $failed ) )
			: WP_CLI::success( sprintf( '%d file(s) restored.', $total ) );
	}

	private function require_config(): void {
		if ( ! Config::is_configured() ) {
			WP_CLI::error( 'Not configured. Run `wp tachyon-r2 status`.' );
		}
	}

	/**
	 * @param array<int, string>    $args       Args.
	 * @param array<string, string> $assoc_args Assoc args.
	 * @return int[]
	 */
	private function ids( array $args, array $assoc_args ): array {
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false ) ) {
			$ids = get_posts( [
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'post_mime_type' => 'image',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			] );
		} else {
			$ids = $args;
		}
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( [] === $ids ) {
			WP_CLI::error( 'Pass attachment IDs or --all.' );
		}
		return array_values( array_filter( $ids, static fn ( int $id ) => Keys::supported( (string) get_attached_file( $id, true ) ) ) );
	}
}
