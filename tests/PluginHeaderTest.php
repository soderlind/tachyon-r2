<?php

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2\Tests;

use PHPUnit\Framework\TestCase;

final class PluginHeaderTest extends TestCase {

	public function test_does_not_require_a_specific_tachyon_plugin(): void {
		// Either tachyon-plugin or Tachyon Edge Images provides tachyon_url(); Config::tachyon_ready() checks at runtime.
		$header = file_get_contents( dirname( __DIR__ ) . '/tachyon-r2.php', false, null, 0, 8192 );
		$this->assertIsString( $header );
		$this->assertSame( 0, preg_match( '/^[ \t\/*#@]*Requires Plugins:/mi', $header ) );
	}
}
