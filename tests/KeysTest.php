<?php

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2\Tests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\TachyonR2\Keys;

final class KeysTest extends TestCase {

	public function test_supported_matches_worker_extensions(): void {
		foreach ( [ 'a.gif', 'a.JPG', 'a.jpeg', 'a.png', 'a.webp', 'a.avif' ] as $file ) {
			$this->assertTrue( Keys::supported( $file ), $file );
		}
		foreach ( [ 'a.pdf', 'a.svg', 'a.heic', 'a', 'a.jpg.txt' ] as $file ) {
			$this->assertFalse( Keys::supported( $file ), $file );
		}
	}

	public function test_root_strips_sites_suffix(): void {
		$this->assertSame( '/w/uploads', Keys::root( '/w/uploads/sites/68' ) );
		$this->assertSame( '/w/uploads', Keys::root( '/w/uploads/' ) );
	}

	public function test_key_mirrors_tachyon_url_path(): void {
		$this->assertSame( 'uploads/2026/01/a.jpg', Keys::for_path( '/w/uploads/2026/01/a.jpg', '/w/uploads', 'uploads' ) );
		$this->assertSame( 'uploads/sites/68/2026/01/a.jpg', Keys::for_path( '/w/uploads/sites/68/2026/01/a.jpg', '/w/uploads/sites/68', 'uploads' ) );
		$this->assertSame( '2026/01/a.jpg', Keys::for_path( '/w/uploads/2026/01/a.jpg', '/w/uploads', '' ) );
		$this->assertNull( Keys::for_path( '/elsewhere/a.jpg', '/w/uploads', 'uploads' ) );
	}

	public function test_wrapper_base_matches_local_keys(): void {
		$base = Keys::wrapper_base( '/w/uploads/sites/68', 'uploads' );
		$this->assertSame( 'tachyon-r2://uploads/sites/68', $base );
		$this->assertSame(
			Keys::for_path( '/w/uploads/sites/68/2026/01/a.jpg', '/w/uploads/sites/68', 'uploads' ),
			Keys::for_path( $base . '/2026/01/a.jpg', '/w/uploads/sites/68', 'uploads' )
		);
		$this->assertSame( 'tachyon-r2://uploads', Keys::wrapper_base( '/w/uploads', 'uploads' ) );
	}
}
