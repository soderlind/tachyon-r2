<?php

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\TachyonR2\Abilities;
use Soderlind\Plugin\TachyonR2\Config;
use Soderlind\Plugin\TachyonR2\Settings;

final class AbilitiesTest extends TestCase {

	/** @var array<string, callable> */
	private array $hooks = [];

	/** @var array<string, array<string, mixed>> */
	private array $registered_abilities = [];

	/** @var array<string, array<string, mixed>> */
	private array $registered_categories = [];

	private bool $can_manage = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Config::reset();
		$this->hooks                  = [];
		$this->registered_abilities  = [];
		$this->registered_categories = [];
		$this->can_manage             = false;

		Functions\when( 'add_action' )->alias(
			function ( string $hook, callable $callback ): void {
				$this->hooks[ $hook ] = $callback;
			}
		);
		Functions\when( 'wp_register_ability_category' )->alias(
			function ( string $id, array $args ): void {
				$this->registered_categories[ $id ] = $args;
			}
		);
		Functions\when( 'wp_register_ability' )->alias(
			function ( string $id, array $args ): void {
				$this->registered_abilities[ $id ] = $args;
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'current_user_can' )->alias( fn ( string $capability ): bool => $this->can_manage );
	}

	protected function tearDown(): void {
		Config::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_registers_abilities_on_the_abilities_api_hooks(): void {
		Abilities::init();

		$this->assertSame( [ Abilities::class, 'register_category' ], $this->hooks['wp_abilities_api_categories_init'] );
		$this->assertSame( [ Abilities::class, 'register' ], $this->hooks['wp_abilities_api_init'] );
	}

	public function test_registers_the_category_and_read_only_abilities_for_rest_and_mcp(): void {
		Abilities::register_category();
		Abilities::register();

		$this->assertSame(
			[
				'label'       => 'Tachyon R2',
				'description' => 'Inspect Cloudflare R2 storage for WordPress images.',
			],
			$this->registered_categories['tachyon-r2']
		);
		$this->assertSame(
			[ 'tachyon-r2/get-status', 'tachyon-r2/get-backfill-status' ],
			array_keys( $this->registered_abilities )
		);

		foreach ( $this->registered_abilities as $ability ) {
			$this->assertSame( 'tachyon-r2', $ability['category'] );
			$this->assertSame( [ Abilities::class, 'can_manage' ], $ability['permission_callback'] );
			$this->assertTrue( $ability['meta']['show_in_rest'] );
			$this->assertTrue( $ability['meta']['mcp']['public'] );
			$this->assertSame(
				[
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
				$ability['meta']['annotations']
			);
		}
	}

	public function test_permission_matches_the_settings_capability(): void {
		$this->assertFalse( Abilities::can_manage() );

		$this->can_manage = true;
		$this->assertTrue( Abilities::can_manage() );
		$this->assertSame( 'manage_options', Settings::capability() );
	}

	public function test_status_reports_an_unconfigured_bucket_without_calling_r2(): void {
		$status = Abilities::get_status();

		$this->assertFalse( $status['configured'] );
		$this->assertSame( 'not_configured', $status['connection'] );
		$this->assertNull( $status['connection_error'] );
	}
}
