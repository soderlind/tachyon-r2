<?php
/**
 * Settings screen.
 *
 * @package TachyonR2
 */

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2;

/**
 * Settings > Tachyon R2. Network Admin > Settings when network-activated.
 *
 * On multisite only network administrators can change the settings.
 */
final class Settings {

	public const SLUG = 'tachyon-r2';

	public static function init(): void {
		add_action( self::network() ? 'network_admin_menu' : 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_post_tachyon_r2_save', [ self::class, 'save' ] );
		add_action( 'admin_post_tachyon_r2_check', [ self::class, 'check' ] );
	}

	private static function network(): bool {
		return Config::SCOPE_NETWORK === Config::scope();
	}

	public static function capability(): string {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	public static function page_url( array $args = [] ): string {
		$base = self::network() ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' );
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), $base );
	}

	public static function menu(): void {
		add_submenu_page(
			self::network() ? 'settings.php' : 'options-general.php',
			__( 'Tachyon R2', 'tachyon-r2' ),
			__( 'Tachyon R2', 'tachyon-r2' ),
			self::capability(),
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function save(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'tachyon-r2' ), 403 );
		}
		check_admin_referer( 'tachyon_r2_save' );

		$stored = Config::stored();
		$input  = wp_unslash( $_POST['tachyon_r2'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$input  = is_array( $input ) ? $input : [];

		foreach ( [ 'account_id', 'access_key_id', 'bucket' ] as $field ) {
			$stored[ $field ] = sanitize_text_field( (string) ( $input[ $field ] ?? '' ) );
		}
		$stored['key_prefix'] = trim( sanitize_text_field( (string) ( $input['key_prefix'] ?? '' ) ), '/' );
		if ( '' === $stored['key_prefix'] ) {
			unset( $stored['key_prefix'] );
		}
		$stored['endpoint']        = esc_url_raw( (string) ( $input['endpoint'] ?? '' ), [ 'https', 'http' ] );
		$mode                      = (string) ( $input['mode'] ?? 'sync' );
		$stored['mode']            = in_array( $mode, Config::MODES, true ) ? $mode : 'sync';
		$stored['skip_thumbnails'] = ! empty( $input['skip_thumbnails'] );
		$secret                    = trim( (string) ( $input['secret_access_key'] ?? '' ) );
		if ( '' !== $secret ) {
			$stored['secret_access_key'] = $secret;
		}

		Config::save( $stored );
		wp_safe_redirect( self::page_url( [ 'updated' => 1 ] ) );
		exit;
	}

	public static function check(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'tachyon-r2' ), 403 );
		}
		check_admin_referer( 'tachyon_r2_check' );
		$result = Config::is_configured()
			? Client::from_config()->check()
			: new \WP_Error( 'tachyon_r2_config', __( 'Account ID, access key, secret and bucket are required.', 'tachyon-r2' ) );
		set_transient( 'tachyon_r2_check_' . get_current_user_id(), is_wp_error( $result ) ? $result->get_error_message() : 'ok', 60 );
		wp_safe_redirect( self::page_url( [ 'checked' => 1 ] ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		$s     = Config::all();
		$check = isset( $_GET['checked'] ) ? get_transient( 'tachyon_r2_check_' . get_current_user_id() ) : false; // phpcs:ignore WordPress.Security.NonceVerification
		$modes = [
			'sync'    => __( 'Sync: keep local files and copy images to R2', 'tachyon-r2' ),
			'offload' => __( 'Offload: copy images to R2, then delete the local files', 'tachyon-r2' ),
			'stream'  => __( 'Stream: write images directly to R2, never to disk', 'tachyon-r2' ),
		];
		$uploads = wp_get_upload_dir();
		$example = Keys::join( Config::key_prefix(), substr( (string) $uploads['basedir'], strlen( Keys::root( (string) $uploads['basedir'] ) ) ) . '/2026/01/photo.jpg' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tachyon R2', 'tachyon-r2' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'tachyon-r2' ); ?></p></div>
			<?php endif; ?>
			<?php if ( false !== $check ) : ?>
				<div class="notice <?php echo 'ok' === $check ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p>
					<?php echo 'ok' === $check ? esc_html__( 'Connected to the bucket.', 'tachyon-r2' ) : esc_html( (string) $check ); ?>
				</p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Status', 'tachyon-r2' ); ?></h2>
			<table class="widefat striped" style="max-width:48rem">
				<tbody>
					<tr><th><?php esc_html_e( 'Tachyon', 'tachyon-r2' ); ?></th><td><?php echo Config::tachyon_ready() ? esc_html( (string) TACHYON_URL ) : esc_html__( 'Not detected. Activate Tachyon Edge Images or tachyon-plugin and define TACHYON_URL; until then only sync mode is used.', 'tachyon-r2' ); ?></td></tr>
					<?php if ( is_multisite() ) : ?>
						<tr><th><?php esc_html_e( 'Settings apply to', 'tachyon-r2' ); ?></th><td><?php echo esc_html( self::scope_label() ); ?></td></tr>
					<?php endif; ?>
					<tr><th><?php esc_html_e( 'Configured', 'tachyon-r2' ); ?></th><td><?php echo Config::is_configured() ? esc_html__( 'Yes', 'tachyon-r2' ) : esc_html__( 'No', 'tachyon-r2' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Mode in effect', 'tachyon-r2' ); ?></th><td><code><?php echo esc_html( Config::mode() ); ?></code><?php echo Config::skip_thumbnails() ? ' + ' . esc_html__( 'skip thumbnails', 'tachyon-r2' ) : ''; ?></td></tr>
					<tr><th><?php esc_html_e( 'Example key', 'tachyon-r2' ); ?></th><td><code><?php echo esc_html( $example ); ?></code></td></tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tachyon_r2_save">
				<?php wp_nonce_field( 'tachyon_r2_save' ); ?>
				<table class="form-table" role="presentation">
					<?php
					self::text( 'account_id', __( 'Account ID', 'tachyon-r2' ), (string) $s['account_id'] );
					self::text( 'access_key_id', __( 'Access key ID', 'tachyon-r2' ), (string) $s['access_key_id'] );
					self::text( 'secret_access_key', __( 'Secret access key', 'tachyon-r2' ), '', 'password', '' !== (string) $s['secret_access_key'] ? __( 'Stored. Leave blank to keep it. Prefer the TACHYON_R2_SECRET_ACCESS_KEY constant.', 'tachyon-r2' ) : __( 'Prefer the TACHYON_R2_SECRET_ACCESS_KEY constant in wp-config.php.', 'tachyon-r2' ) );
					self::text( 'bucket', __( 'Bucket', 'tachyon-r2' ), (string) $s['bucket'], 'text', __( 'The bucket the Tachyon Edge Worker reads from.', 'tachyon-r2' ) );
					self::text( 'key_prefix', __( 'Key prefix', 'tachyon-r2' ), null === $s['key_prefix'] ? '' : (string) $s['key_prefix'], 'text', __( 'Defaults to the path of TACHYON_URL.', 'tachyon-r2' ) );
					self::text( 'endpoint', __( 'Endpoint', 'tachyon-r2' ), (string) $s['endpoint'], 'url', __( 'Optional. Defaults to https://<account>.r2.cloudflarestorage.com.', 'tachyon-r2' ) );
					?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Upload method', 'tachyon-r2' ); ?></th>
						<td><fieldset>
							<?php foreach ( $modes as $value => $label ) : ?>
								<label><input type="radio" name="tachyon_r2[mode]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $s['mode'], $value ); ?> <?php disabled( Config::locked( 'mode' ) ); ?>> <?php echo esc_html( $label ); ?></label><br>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Only images the Worker serves (gif, jpg, jpeg, png, webp, avif) go to R2. Other files stay local.', 'tachyon-r2' ); ?></p>
						</fieldset></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Thumbnails', 'tachyon-r2' ); ?></th>
						<td><label><input type="checkbox" name="tachyon_r2[skip_thumbnails]" value="1" <?php checked( $s['skip_thumbnails'] ); ?> <?php disabled( Config::locked( 'skip_thumbnails' ) ); ?>> <?php esc_html_e( 'Skip thumbnail files; Tachyon resizes on the fly', 'tachyon-r2' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tachyon_r2_check">
				<?php wp_nonce_field( 'tachyon_r2_check' ); ?>
				<?php submit_button( __( 'Check connection', 'tachyon-r2' ), 'secondary' ); ?>
			</form>

			<?php if ( Config::is_configured() ) : ?>
				<?php if ( self::network() ) : ?>
					<h2><?php esc_html_e( 'Existing images', 'tachyon-r2' ); ?></h2>
					<p><?php esc_html_e( 'Each site copies its existing images from Media > Tachyon R2. To copy every site from the command line:', 'tachyon-r2' ); ?></p>
					<p><code>wp site list --field=url | xargs -I % wp tachyon-r2 sync --all --keep-local --url=%</code></p>
				<?php else : ?>
					<?php Backfill::render_panel(); ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function scope_label(): string {
		if ( self::network() ) {
			return __( 'All sites in the network', 'tachyon-r2' );
		}
		return Config::inherits_network()
			? __( 'This site. Using the network settings until you save.', 'tachyon-r2' )
			: __( 'This site', 'tachyon-r2' );
	}

	private static function text( string $field, string $label, string $value, string $type = 'text', string $help = '' ): void {
		$locked = Config::locked( $field );
		$id     = 'tachyon-r2-' . $field;
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input class="regular-text" type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>" name="tachyon_r2[<?php echo esc_attr( $field ); ?>]" value="<?php echo esc_attr( $locked && 'password' === $type ? '' : $value ); ?>" autocomplete="off" <?php disabled( $locked ); ?>>
				<?php if ( $locked ) : ?>
					<p class="description"><?php echo esc_html( sprintf( /* translators: %s: constant name */ __( 'Set by %s.', 'tachyon-r2' ), Config::CONSTANTS[ $field ] ) ); ?></p>
				<?php elseif ( '' !== $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
