<?php

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2\Tests;

use PHPUnit\Framework\TestCase;

final class PluginHeaderTest extends TestCase {

	public function test_tachyon_plugin_is_a_required_dependency(): void {
		$header = file_get_contents( dirname( __DIR__ ) . '/tachyon-r2.php', false, null, 0, 8192 );
		$this->assertIsString( $header );
		$this->assertSame(
			1,
			preg_match( '/^[ \t\/*#@]*Requires Plugins:[ \t]*([^\r\n]+)/mi', $header, $matches )
		);
		$this->assertContains( 'tachyon-plugin', array_map( 'trim', explode( ',', $matches[1] ) ) );
	}
}
