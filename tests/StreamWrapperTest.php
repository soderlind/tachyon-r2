<?php

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2\Tests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\TachyonR2\StreamWrapper;

final class StreamWrapperTest extends TestCase {

	private MemoryStorage $storage;

	protected function setUp(): void {
		$this->storage = new MemoryStorage();
		StreamWrapper::register( $this->storage );
		StreamWrapper::reset();
	}

	public function test_write_and_read(): void {
		$this->assertSame( 5, file_put_contents( 'tachyon-r2://uploads/2026/a.jpg', 'hello' ) );
		$this->assertSame( 'hello', $this->storage->objects['uploads/2026/a.jpg'] );
		$this->assertTrue( StreamWrapper::wrote( 'uploads/2026/a.jpg' ) );
		$this->assertSame( 'hello', file_get_contents( 'tachyon-r2://uploads/2026/a.jpg' ) );
	}

	public function test_copy_from_local_file(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'r2' );
		file_put_contents( $tmp, 'bytes' );
		$this->assertTrue( copy( $tmp, 'tachyon-r2://uploads/b.png' ) );
		unlink( $tmp );
		$this->assertSame( 'bytes', $this->storage->objects['uploads/b.png'] );
	}

	public function test_stat_files_and_directories(): void {
		$this->storage->objects['uploads/2026/01/a.jpg'] = 'abc';
		$this->assertTrue( is_file( 'tachyon-r2://uploads/2026/01/a.jpg' ) );
		$this->assertSame( 3, filesize( 'tachyon-r2://uploads/2026/01/a.jpg' ) );
		$this->assertTrue( is_dir( 'tachyon-r2://uploads/2026/01' ) );
		$this->assertTrue( file_exists( 'tachyon-r2://uploads/2026' ) );
		$this->assertFalse( file_exists( 'tachyon-r2://uploads/2025' ) );
		$this->assertFalse( @file_get_contents( 'tachyon-r2://uploads/missing.jpg' ) );
	}

	public function test_scandir_lists_one_level(): void {
		$this->storage->objects['uploads/2026/01/a.jpg']   = '1';
		$this->storage->objects['uploads/2026/01/b.jpg']   = '2';
		$this->storage->objects['uploads/2026/01/sub/c.jpg'] = '3';
		$this->assertSame( [ 'a.jpg', 'b.jpg', 'sub' ], scandir( 'tachyon-r2://uploads/2026/01' ) );
	}

	public function test_unlink_rename_mkdir_chmod(): void {
		$this->storage->objects['uploads/a.jpg'] = '1';
		$this->assertTrue( rename( 'tachyon-r2://uploads/a.jpg', 'tachyon-r2://uploads/b.jpg' ) );
		$this->assertSame( [ 'uploads/b.jpg' ], array_keys( $this->storage->objects ) );
		$this->assertTrue( unlink( 'tachyon-r2://uploads/b.jpg' ) );
		$this->assertSame( [], $this->storage->objects );
		$this->assertTrue( mkdir( 'tachyon-r2://uploads/2030/01', 0755, true ) );
		$this->assertTrue( is_dir( 'tachyon-r2://uploads/2030/01' ) );
		file_put_contents( 'tachyon-r2://uploads/c.jpg', 'x' );
		$this->assertTrue( chmod( 'tachyon-r2://uploads/c.jpg', 0644 ) );
	}

	public function test_seek_and_partial_reads(): void {
		$this->storage->objects['uploads/a.jpg'] = '0123456789';
		$fp = fopen( 'tachyon-r2://uploads/a.jpg', 'rb' );
		fseek( $fp, 4 );
		$this->assertSame( '456', fread( $fp, 3 ) );
		$this->assertSame( 7, ftell( $fp ) );
		fclose( $fp );
		$this->assertNotContains( 'PUT uploads/a.jpg', $this->storage->calls, 'read-only handles must not upload' );
	}
}
