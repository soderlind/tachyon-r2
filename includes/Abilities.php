<?php
/**
 * WordPress Abilities API registrations.
 *
 * @package TachyonR2
 */

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2;

/**
 * Registers the plugin's read-only abilities.
 */
final class Abilities {

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', [ self::class, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ self::class, 'register' ] );
	}

	public static function register_category(): void {
		wp_register_ability_category(
			'tachyon-r2',
			[
				'label' => __( 'Tachyon R2', 'tachyon-r2' ),
			]
		);
	}

	public static function register(): void {
		$meta = [
			'show_in_rest' => true,
			'mcp'          => [
				'public' => true,
			],
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
		];

		wp_register_ability(
			'tachyon-r2/get-status',
			[
				'label'               => __( 'Get Tachyon R2 status', 'tachyon-r2' ),
				'description'         => __( 'Get Tachyon R2 configuration and check the R2 bucket connection.', 'tachyon-r2' ),
				'category'            => 'tachyon-r2',
				'execute_callback'    => [ self::class, 'get_status' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'meta'                => $meta,
			]
		);

		wp_register_ability(
			'tachyon-r2/get-backfill-status',
			[
				'label'               => __( 'Get image backfill status', 'tachyon-r2' ),
				'description'         => __( 'Get the existing-image backfill progress, counts, and failed images.', 'tachyon-r2' ),
				'category'            => 'tachyon-r2',
				'execute_callback'    => [ self::class, 'get_backfill_status' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'meta'                => $meta,
			]
		);
	}

	/** @return array<string, bool|string|null> */
	public static function get_status( mixed $input = null ): array {
		return Status::get();
	}

	/** @return array<string, mixed> */
	public static function get_backfill_status( mixed $input = null ): array {
		return Backfill::payload();
	}

	public static function can_manage( mixed $input = null ): bool {
		return current_user_can( Settings::capability() );
	}
}
