<?php
/**
 * Copy existing images to R2 in the background.
 *
 * @package TachyonR2
 */

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2;

use WP_Error;
use WP_REST_Request;

/**
 * Resumable WP-Cron job, REST progress endpoint, admin notice and panel.
 *
 * Images without the Uploader::META flag are pending. The job walks them
 * newest first; an open admin tab also drives batches through the REST API.
 */
final class Backfill {

	public const OPTION    = 'tachyon_r2_backfill';
	public const DISMISSED = 'tachyon_r2_backfill_dismissed';
	public const LOCK      = 'tachyon_r2_backfill_lock';
	public const HOOK      = 'tachyon_r2_backfill';
	public const PAGE      = 'tachyon-r2-images';
	public const PENDING   = 'tachyon_r2_pending';
	public const MIMES     = [ 'image/gif', 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ];

	private const LOCK_TTL = 60;

	private const DEFAULTS = [
		'status'       => 'idle',
		'delete_local' => false,
		'total'        => 0,
		'processed'    => 0,
		'uploaded'     => 0,
		'failed'       => 0,
		'cursor'       => 0,
		'started_at'   => 0,
		'finished_at'  => 0,
	];

	public static function init(): void {
		add_action( self::HOOK, [ self::class, 'run' ] );
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
		add_action( 'admin_notices', [ self::class, 'notice' ] );
		add_action( 'admin_post_tachyon_r2_backfill_dismiss', [ self::class, 'dismiss' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		if ( Config::SCOPE_NETWORK === Config::scope() ) {
			add_action( 'admin_menu', [ self::class, 'menu' ] );
		}
	}

	/** @return array<string, mixed> */
	public static function state(): array {
		wp_cache_delete( self::OPTION, 'options' );
		$state = get_option( self::OPTION, [] );
		return array_merge( self::DEFAULTS, is_array( $state ) ? $state : [] );
	}

	/** @param array<string, mixed> $state State. */
	private static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/** Deleting local files only makes sense when Tachyon serves the images. */
	public static function can_delete_local(): bool {
		return 'sync' !== Config::mode();
	}

	/**
	 * @return array{images: int, in_r2: int, pending: int, failed: int}
	 */
	public static function counts(): array {
		global $wpdb;
		[ $mimes, $args ] = self::mime_sql();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS images, SUM(m.meta_id IS NOT NULL) AS in_r2, SUM(m.meta_id IS NULL AND e.meta_id IS NOT NULL) AS failed
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s
				WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.post_mime_type IN ($mimes)",
				Uploader::META,
				Uploader::ERROR,
				...$args
			),
			ARRAY_A
		);
		// phpcs:enable
		$images = (int) ( $row['images'] ?? 0 );
		$in_r2  = (int) ( $row['in_r2'] ?? 0 );
		$counts = [
			'images'  => $images,
			'in_r2'   => $in_r2,
			'pending' => max( 0, $images - $in_r2 ),
			'failed'  => (int) ( $row['failed'] ?? 0 ),
		];
		set_transient( self::PENDING, $counts['pending'], 5 * MINUTE_IN_SECONDS );
		return $counts;
	}

	/** Cached pending count for the notice. */
	public static function pending(): int {
		$pending = get_transient( self::PENDING );
		return false === $pending ? self::counts()['pending'] : (int) $pending;
	}

	/**
	 * Pending attachment IDs below the cursor, newest first.
	 *
	 * @param int $cursor Exclusive upper bound; 0 for none.
	 * @param int $limit  Max IDs.
	 * @return int[]
	 */
	public static function pending_ids( int $cursor, int $limit ): array {
		global $wpdb;
		[ $mimes, $args ] = self::mime_sql();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.post_mime_type IN ($mimes)
				AND m.meta_id IS NULL AND p.ID < %d
				ORDER BY p.ID DESC LIMIT %d",
				Uploader::META,
				...[ ...$args, $cursor > 0 ? $cursor : PHP_INT_MAX, $limit ]
			)
		);
		// phpcs:enable
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Images that failed to upload, newest first.
	 *
	 * @return array<int, array{id: int, title: string, error: string, url: string}>
	 */
	public static function failures( int $limit = 20 ): array {
		global $wpdb;
		[ $mimes, $args ] = self::mime_sql();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id, p.post_title AS title, e.meta_value AS error FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.post_mime_type IN ($mimes)
				AND m.meta_id IS NULL
				ORDER BY p.ID DESC LIMIT %d",
				Uploader::ERROR,
				Uploader::META,
				...[ ...$args, $limit ]
			),
			ARRAY_A
		);
		// phpcs:enable
		return array_map(
			static fn ( array $row ): array => [
				'id'    => (int) $row['id'],
				'title' => (string) $row['title'],
				'error' => (string) $row['error'],
				'url'   => (string) get_edit_post_link( (int) $row['id'], 'raw' ),
			],
			(array) $rows
		);
	}

	/** @return array{0: string, 1: string[]} */
	private static function mime_sql(): array {
		return [ implode( ', ', array_fill( 0, count( self::MIMES ), '%s' ) ), self::MIMES ];
	}

	/**
	 * Start a new run over every pending image.
	 *
	 * @param bool $delete_local Delete local copies after a successful upload.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function start( bool $delete_local = false ) {
		if ( ! Config::is_configured() ) {
			return new WP_Error( 'tachyon_r2_config', __( 'Configure R2 first.', 'tachyon-r2' ), [ 'status' => 400 ] );
		}
		$state = array_merge(
			self::DEFAULTS,
			[
				'status'       => 'running',
				'delete_local' => $delete_local && self::can_delete_local(),
				'total'        => self::counts()['pending'],
				'started_at'   => time(),
			]
		);
		if ( 0 === $state['total'] ) {
			$state['status']      = 'done';
			$state['finished_at'] = time();
		}
		self::save( $state );
		self::schedule();
		return $state;
	}

	/** @return array<string, mixed> */
	public static function pause(): array {
		return self::transition( [ 'running' ], 'paused' );
	}

	/** @return array<string, mixed> */
	public static function resume(): array {
		$state = self::transition( [ 'paused' ], 'running' );
		self::schedule();
		return $state;
	}

	/** @return array<string, mixed> */
	public static function cancel(): array {
		$state = self::transition( [ 'running', 'paused' ], 'cancelled' );
		wp_clear_scheduled_hook( self::HOOK );
		return $state;
	}

	/**
	 * @param string[] $from Allowed current statuses.
	 * @param string   $to   New status.
	 * @return array<string, mixed>
	 */
	private static function transition( array $from, string $to ): array {
		$state = self::state();
		if ( in_array( $state['status'], $from, true ) ) {
			$state['status'] = $to;
			if ( 'cancelled' === $to ) {
				$state['finished_at'] = time();
			}
			self::save( $state );
		}
		return $state;
	}

	/** Queue the next batch when the job is running. */
	public static function schedule(): void {
		if ( 'running' === self::state()['status'] && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	/** Cron handler. */
	public static function run(): void {
		self::step( 20 );
		if ( 'running' === self::state()['status'] ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
	}

	/**
	 * Process one time-boxed batch.
	 *
	 * @param int $seconds Time budget.
	 * @return array<string, mixed>
	 */
	public static function step( int $seconds = 10 ): array {
		$state = self::state();
		if ( 'running' !== $state['status'] || ! self::lock() ) {
			return $state;
		}
		try {
			$deadline = microtime( true ) + max( 1, $seconds );
			$batch    = max( 1, (int) apply_filters( 'tachyon_r2_backfill_batch_size', 20 ) );
			$ids      = self::pending_ids( (int) $state['cursor'], $batch );
			$done     = count( $ids ) < $batch;

			foreach ( $ids as $i => $id ) {
				if ( $i > 0 && ( microtime( true ) > $deadline || 'running' !== self::state()['status'] ) ) {
					$done = false;
					break;
				}
				$state = self::process( $id, $state );
				// Keep pause/cancel made by another request during this item.
				$state['status'] = self::state()['status'];
				self::save( $state );
			}

			if ( $done && 'running' === $state['status'] ) {
				$state['status']      = 'done';
				$state['finished_at'] = time();
				self::save( $state );
				delete_transient( self::PENDING );
			}
		} finally {
			self::unlock();
			Uploader::shutdown();
		}
		return $state;
	}

	/**
	 * Upload one attachment and update the counters.
	 *
	 * @param int                  $id    Attachment ID.
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	public static function process( int $id, array $state ): array {
		$state['cursor'] = $id;
		++$state['processed'];

		$error = null;
		if ( ! Keys::supported( (string) get_attached_file( $id, true ) ) ) {
			$error = __( 'Unsupported file type.', 'tachyon-r2' );
		} else {
			$res = Uploader::sync( $id, true, true );
			if ( is_wp_error( $res ) ) {
				$error = $res->get_error_message();
			} elseif ( ! get_post_meta( $id, Uploader::META, true ) ) {
				$error = __( 'No local files found.', 'tachyon-r2' );
			} else {
				$state['uploaded'] += $res;
				if ( $state['delete_local'] && self::can_delete_local() ) {
					self::delete_local( $id );
				}
			}
		}

		if ( null !== $error ) {
			update_post_meta( $id, Uploader::ERROR, $error );
			++$state['failed'];
		}
		return $state;
	}

	/**
	 * Delete local copies once every file of the attachment is in R2.
	 *
	 * @param int $id Attachment ID.
	 */
	private static function delete_local( int $id ): void {
		$basedir = (string) wp_get_upload_dir()['basedir'];
		$prefix  = Config::key_prefix();
		foreach ( Keys::files( $id ) as $path ) {
			if ( ! Keys::is_wrapper( $path ) && null !== Keys::for_path( $path, $basedir, $prefix ) && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	private static function lock(): bool {
		if ( add_option( self::LOCK, time(), '', false ) ) {
			return true;
		}
		wp_cache_delete( self::LOCK, 'options' );
		if ( (int) get_option( self::LOCK, 0 ) < time() - self::LOCK_TTL ) {
			update_option( self::LOCK, time(), false );
			return true;
		}
		return false;
	}

	private static function unlock(): void {
		delete_option( self::LOCK );
	}

	public static function routes(): void {
		$permission = static fn () => current_user_can( Settings::capability() );
		register_rest_route(
			'tachyon-r2/v1',
			'/backfill',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'rest_get' ],
					'permission_callback' => $permission,
				],
				[
					'methods'             => 'POST',
					'callback'            => [ self::class, 'rest_post' ],
					'permission_callback' => $permission,
					'args'                => [
						'action'       => [
							'type'     => 'string',
							'enum'     => [ 'start', 'pause', 'resume', 'cancel', 'step' ],
							'required' => true,
						],
						'delete_local' => [
							'type'    => 'boolean',
							'default' => false,
						],
					],
				],
			]
		);
	}

	/** @return array<string, mixed> */
	public static function rest_get(): array {
		self::schedule();
		return self::payload();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function rest_post( $request ) {
		$result = match ( (string) $request['action'] ) {
			'start'  => self::start( (bool) $request['delete_local'] ),
			'pause'  => self::pause(),
			'resume' => self::resume(),
			'cancel' => self::cancel(),
			default  => self::step( 10 ),
		};
		return is_wp_error( $result ) ? $result : self::payload();
	}

	/** @return array<string, mixed> */
	public static function payload(): array {
		return [
			'state'            => self::state(),
			'counts'           => self::counts(),
			'failures'         => self::failures(),
			'can_delete_local' => self::can_delete_local(),
			'cron_disabled'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'cli'              => self::cli(),
		];
	}

	public static function cli(): string {
		return 'wp tachyon-r2 sync --all --keep-local' . ( is_multisite() ? ' --url=' . home_url() : '' );
	}

	public static function page_url(): string {
		return Config::SCOPE_NETWORK === Config::scope()
			? admin_url( 'upload.php?page=' . self::PAGE )
			: Settings::page_url() . '#tachyon-r2-backfill';
	}

	private static function on_panel_page(): bool {
		$page = sanitize_key( (string) ( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		return ! is_network_admin() && in_array( $page, [ Settings::SLUG, self::PAGE ], true );
	}

	public static function menu(): void {
		add_media_page(
			__( 'Tachyon R2', 'tachyon-r2' ),
			__( 'Tachyon R2', 'tachyon-r2' ),
			Settings::capability(),
			self::PAGE,
			[ self::class, 'render_page' ]
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Tachyon R2', 'tachyon-r2' ) . '</h1>';
		self::render_panel();
		echo '</div>';
	}

	/** Server-rendered summary; assets/backfill.js takes over. */
	public static function render_panel(): void {
		$counts = self::counts();
		?>
		<div id="tachyon-r2-backfill" class="tachyon-r2-backfill">
			<h2><?php esc_html_e( 'Existing images', 'tachyon-r2' ); ?></h2>
			<p class="tachyon-r2-summary">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: images in R2, 2: total images, 3: images not in R2 */
						__( '%1$s of %2$s images are in R2. %3$s are not yet.', 'tachyon-r2' ),
						number_format_i18n( $counts['in_r2'] ),
						number_format_i18n( $counts['images'] ),
						number_format_i18n( $counts['pending'] )
					)
				);
				?>
			</p>
			<div class="tachyon-r2-app"><noscript><p><?php esc_html_e( 'JavaScript is required. Use WP-CLI instead:', 'tachyon-r2' ); ?> <code><?php echo esc_html( self::cli() ); ?></code></p></noscript></div>
		</div>
		<?php
	}

	/**
	 * @param string $hook Admin page hook suffix.
	 */
	public static function enqueue( $hook ): void {
		unset( $hook );
		if ( ! self::on_panel_page() || ! current_user_can( Settings::capability() ) ) {
			return;
		}
		$file = dirname( __DIR__ ) . '/assets/backfill.js';
		wp_enqueue_script(
			'tachyon-r2-backfill',
			plugins_url( 'assets/backfill.js', dirname( __DIR__ ) . '/tachyon-r2.php' ),
			[ 'wp-api-fetch', 'wp-i18n' ],
			(string) ( file_exists( $file ) ? filemtime( $file ) : VERSION ),
			true
		);
		wp_set_script_translations( 'tachyon-r2-backfill', 'tachyon-r2' );
		wp_add_inline_script( 'tachyon-r2-backfill', 'window.tachyonR2Backfill = ' . wp_json_encode( self::payload() ) . ';', 'before' );
	}

	public static function notice(): void {
		if ( is_network_admin() || self::on_panel_page() || ! current_user_can( Settings::capability() ) ) {
			return;
		}
		if ( get_option( self::DISMISSED ) || 'running' === self::state()['status'] ) {
			return;
		}
		$pending = self::pending();
		if ( $pending < 1 ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=tachyon_r2_backfill_dismiss' ), 'tachyon_r2_backfill_dismiss' );
		?>
		<div class="notice notice-info">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: number of images */
						_n( 'Tachyon R2: %s existing image is not in R2 yet.', 'Tachyon R2: %s existing images are not in R2 yet.', $pending, 'tachyon-r2' ),
						number_format_i18n( $pending )
					)
				);
				?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Copy to R2', 'tachyon-r2' ); ?></a>
				<a class="button" href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Not now', 'tachyon-r2' ); ?></a>
			</p>
		</div>
		<?php
	}

	public static function dismiss(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'tachyon-r2' ), 403 );
		}
		check_admin_referer( 'tachyon_r2_backfill_dismiss' );
		update_option( self::DISMISSED, time(), false );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/** Stop the job when the plugin is deactivated. */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
		self::pause();
		delete_option( self::LOCK );
	}
}
