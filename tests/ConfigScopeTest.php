<?php

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\TachyonR2\Config;
use Soderlind\Plugin\TachyonR2\Settings;

final class ConfigScopeTest extends TestCase {

	private const BASENAME = 'tachyon-r2/tachyon-r2.php';

	/** @var array<string, mixed> */
	private array $options = [];

	/** @var array<string, mixed> */
	private array $network = [];

	private bool $multisite = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Config::reset();
		$this->options   = [];
		$this->network   = [];
		$this->multisite = false;

		Functions\when( 'is_multisite' )->alias( fn () => $this->multisite );
		Functions\when( 'plugin_basename' )->justReturn( self::BASENAME );
		Functions\when( 'get_option' )->alias( fn ( $key, $default = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default );
		Functions\when( 'get_site_option' )->alias( fn ( $key, $default = false ) => array_key_exists( $key, $this->network ) ? $this->network[ $key ] : $default );
		Functions\when( 'update_option' )->alias( function ( $key, $value ) { $this->options[ $key ] = $value; return true; } );
		Functions\when( 'update_site_option' )->alias( function ( $key, $value ) { $this->network[ $key ] = $value; return true; } );
		Functions\when( 'add_option' )->alias(
			function ( $key, $value ) {
				if ( array_key_exists( $key, $this->options ) ) {
					return false;
				}
				$this->options[ $key ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Config::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function network_activate(): void {
		$this->network['active_sitewide_plugins'] = [ self::BASENAME => time() ];
	}

	public function test_single_site_uses_site_option(): void {
		$this->options[ Config::OPTION ] = [ 'bucket' => 'single' ];
		$this->network[ Config::OPTION ] = [ 'bucket' => 'network' ];

		$this->assertSame( Config::SCOPE_SINGLE, Config::scope() );
		$this->assertSame( 'single', Config::get( 'bucket' ) );
		$this->assertFalse( Config::inherits_network() );
	}

	public function test_network_activation_uses_network_option(): void {
		$this->multisite = true;
		$this->network_activate();
		$this->options[ Config::OPTION ] = [ 'bucket' => 'site' ];
		$this->network[ Config::OPTION ] = [ 'bucket' => 'network' ];

		$this->assertSame( Config::SCOPE_NETWORK, Config::scope() );
		$this->assertSame( 'network', Config::get( 'bucket' ) );

		Config::save( [ 'bucket' => 'saved' ] );
		$this->assertSame( [ 'bucket' => 'saved' ], $this->network[ Config::OPTION ] );
		$this->assertSame( [ 'bucket' => 'site' ], $this->options[ Config::OPTION ] );
	}

	public function test_site_activation_uses_site_option(): void {
		$this->multisite                 = true;
		$this->options[ Config::OPTION ] = [ 'bucket' => 'site' ];
		$this->network[ Config::OPTION ] = [ 'bucket' => 'network' ];

		$this->assertSame( Config::SCOPE_SITE, Config::scope() );
		$this->assertFalse( Config::inherits_network() );
		$this->assertSame( 'site', Config::get( 'bucket' ) );
	}

	public function test_existing_site_activation_inherits_network_until_saved(): void {
		$this->multisite                 = true;
		$this->network[ Config::OPTION ] = [ 'bucket' => 'network', 'secret_access_key' => 'secret' ];

		$this->assertTrue( Config::inherits_network() );
		$this->assertSame( 'network', Config::get( 'bucket' ) );

		$stored           = Config::stored();
		$stored['bucket'] = 'site';
		Config::save( $stored );

		$this->assertFalse( Config::inherits_network() );
		$this->assertSame( 'site', Config::get( 'bucket' ) );
		$this->assertSame( 'secret', Config::get( 'secret_access_key' ) );
		$this->assertSame( [ 'bucket' => 'network', 'secret_access_key' => 'secret' ], $this->network[ Config::OPTION ] );
	}

	public function test_new_site_activation_starts_unconfigured(): void {
		$this->multisite                 = true;
		$this->network[ Config::OPTION ] = [ 'bucket' => 'network' ];

		Config::on_activate( false );

		$this->assertSame( [], $this->options[ Config::OPTION ] );
		$this->assertFalse( Config::inherits_network() );
		$this->assertSame( '', Config::get( 'bucket' ) );
	}

	public function test_activation_keeps_existing_site_settings(): void {
		$this->multisite                 = true;
		$this->options[ Config::OPTION ] = [ 'bucket' => 'site' ];

		Config::on_activate( false );

		$this->assertSame( [ 'bucket' => 'site' ], $this->options[ Config::OPTION ] );
	}

	public function test_network_and_single_site_activation_add_nothing(): void {
		$this->multisite = true;
		Config::on_activate( true );
		$this->multisite = false;
		Config::on_activate( false );

		$this->assertArrayNotHasKey( Config::OPTION, $this->options );
	}

	/**
	 * @return array<string, array{bool, bool, string, string}>
	 */
	public static function menus(): array {
		return [
			'single site'       => [ false, false, 'options-general.php', 'manage_options' ],
			'network activated' => [ true, true, 'settings.php', 'manage_network_options' ],
			'site activated'    => [ true, false, 'options-general.php', 'manage_network_options' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'menus' )]
	public function test_menu_location_and_capability( bool $multisite, bool $network, string $parent, string $capability ): void {
		$this->multisite = $multisite;
		if ( $network ) {
			$this->network_activate();
		}
		Functions\stubTranslationFunctions();
		Functions\expect( 'add_submenu_page' )
			->once()
			->with( $parent, 'Tachyon R2', 'Tachyon R2', $capability, Settings::SLUG, \Mockery::type( 'array' ) );

		Settings::menu();
		$this->addToAssertionCount( 1 );
	}
}
