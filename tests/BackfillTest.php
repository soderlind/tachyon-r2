<?php

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2\Tests;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\TachyonR2\Backfill;
use Soderlind\Plugin\TachyonR2\Config;
use Soderlind\Plugin\TachyonR2\Plugin;
use Soderlind\Plugin\TachyonR2\Uploader;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

final class BackfillTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = [];

	/** @var array<int, array<string, mixed>> */
	private array $meta = [];

	/** @var array<int, string> */
	private array $files = [];

	/** @var string[] */
	private array $scheduled = [];

	private string $dir;

	private MemoryStorage $storage;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Config::reset();

		$this->dir       = sys_get_temp_dir() . '/tachyon-r2-backfill-' . uniqid();
		$this->options   = [
			'tachyon_r2' => [
				'account_id'        => 'acct',
				'access_key_id'     => 'key',
				'secret_access_key' => 'secret',
				'bucket'            => 'bucket',
				'key_prefix'        => 'uploads',
				'mode'              => 'offload',
			],
		];
		$this->meta      = [];
		$this->files     = [];
		$this->scheduled = [];
		$this->storage   = new MemoryStorage();
		Plugin::set_storage( $this->storage );
		mkdir( $this->dir . '/uploads/2026/01', 0777, true );

		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->alias( fn ( $key, $default = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default );
		Functions\when( 'update_option' )->alias( function ( $key, $value ) { $this->options[ $key ] = $value; return true; } );
		Functions\when( 'add_option' )->alias(
			function ( $key, $value ) {
				if ( array_key_exists( $key, $this->options ) ) {
					return false;
				}
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias( function ( $key ) { unset( $this->options[ $key ] ); return true; } );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias( function ( $time, $hook ) { $this->scheduled[] = $hook; return true; } );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
		Functions\when( 'get_attached_file' )->alias( fn ( $id ) => $this->files[ (int) $id ] ?? '' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [] );
		Functions\when( 'get_post_meta' )->alias( fn ( $id, $key = '', $single = false ) => $this->meta[ (int) $id ][ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) { $this->meta[ (int) $id ][ $key ] = $value; return true; } );
		Functions\when( 'delete_post_meta' )->alias( function ( $id, $key ) { unset( $this->meta[ (int) $id ][ $key ] ); return true; } );
		Functions\when( 'wp_get_upload_dir' )->alias( fn () => [ 'basedir' => $this->dir . '/uploads' ] );
		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/jpeg', 'ext' => 'jpg' ] );
		Functions\when( 'wp_delete_file' )->alias( fn ( $file ) => @unlink( $file ) );

		$GLOBALS['wpdb'] = new FakeWpdb( $this );
	}

	protected function tearDown(): void {
		Plugin::set_storage( null );
		Config::reset();
		unset( $GLOBALS['wpdb'] );
		exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Attachment IDs, newest last, as the fake database sees them. */
	public function attachments(): array {
		return array_keys( $this->files );
	}

	public function meta( int $id, string $key ): bool {
		return isset( $this->meta[ $id ][ $key ] ) && '' !== $this->meta[ $id ][ $key ];
	}

	private function image( int $id, bool $local = true ): string {
		$path              = $this->dir . "/uploads/2026/01/image-$id.jpg";
		$this->files[ $id ] = $path;
		if ( $local ) {
			file_put_contents( $path, "jpeg-$id" );
		}
		return $path;
	}

	public function test_start_counts_pending_and_schedules(): void {
		$this->image( 1 );
		$this->image( 2 );
		$this->image( 3 );
		$this->meta[1][ Uploader::META ] = 1;

		$state = Backfill::start();

		$this->assertSame( 'running', $state['status'] );
		$this->assertSame( 2, $state['total'] );
		$this->assertSame( [ Backfill::HOOK ], $this->scheduled );
	}

	public function test_start_requires_configuration(): void {
		$this->options['tachyon_r2'] = [];
		$this->assertInstanceOf( \WP_Error::class, Backfill::start() );
	}

	public function test_step_uploads_newest_first_and_keeps_local_files(): void {
		$this->image( 1 );
		$two   = $this->image( 2 );
		$three = $this->image( 3 );
		$this->meta[1][ Uploader::META ] = 1;
		Backfill::start( true );

		$state = Backfill::step();

		$this->assertSame( [ 'PUT uploads/2026/01/image-3.jpg', 'PUT uploads/2026/01/image-2.jpg' ], $this->storage->calls );
		$this->assertSame( 'done', $state['status'] );
		$this->assertSame( 2, $state['processed'] );
		$this->assertSame( 2, $state['uploaded'] );
		$this->assertFalse( $state['delete_local'], 'Sync mode (no Tachyon) never deletes local files.' );
		$this->assertFileExists( $two );
		$this->assertFileExists( $three );
		$this->assertArrayNotHasKey( Backfill::LOCK, $this->options );
	}

	public function test_missing_files_are_flagged_and_counted(): void {
		$this->image( 1, false );
		$this->image( 2 );
		Backfill::start();

		$state = Backfill::step();

		$this->assertSame( 'done', $state['status'] );
		$this->assertSame( 1, $state['failed'] );
		$this->assertSame( 'No local files found.', $this->meta[1][ Uploader::ERROR ] );
		$this->assertSame( [ 'images' => 2, 'in_r2' => 1, 'pending' => 1, 'failed' => 1 ], Backfill::counts() );
	}

	public function test_pause_and_resume(): void {
		$this->image( 1 );
		$this->image( 2 );
		Filters\expectApplied( 'tachyon_r2_backfill_batch_size' )->andReturn( 1 );
		Backfill::start();

		$state = Backfill::step();
		$this->assertSame( 'running', $state['status'] );
		$this->assertSame( 2, $state['cursor'] );

		$this->assertSame( 'paused', Backfill::pause()['status'] );
		$this->assertSame( 1, Backfill::step()['processed'], 'Paused jobs do not advance.' );

		$this->assertSame( 'running', Backfill::resume()['status'] );
		$this->assertSame( 2, Backfill::step()['processed'] );
		$this->assertSame( 'done', Backfill::step()['status'] );
		$this->assertSame( [ 'PUT uploads/2026/01/image-2.jpg', 'PUT uploads/2026/01/image-1.jpg' ], $this->storage->calls );
	}

	public function test_cancel_stops_the_job(): void {
		$this->image( 1 );
		Backfill::start();

		$this->assertSame( 'cancelled', Backfill::cancel()['status'] );
		Backfill::step();
		$this->assertSame( [], $this->storage->calls );
	}

	public function test_lock_blocks_concurrent_batches(): void {
		$this->image( 1 );
		Backfill::start();
		$this->options[ Backfill::LOCK ] = time();

		$this->assertSame( 0, Backfill::step()['processed'] );

		$this->options[ Backfill::LOCK ] = time() - 120;
		$this->assertSame( 1, Backfill::step()['processed'], 'A stale lock is taken over.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_delete_local_after_upload_when_tachyon_serves_images(): void {
		define( 'TACHYON_URL', 'https://images.example.com/uploads' );
		Functions\when( 'tachyon_url' )->returnArg();
		$this->image( 1, false );
		$two = $this->image( 2 );
		Backfill::start( true );

		$state = Backfill::step();

		$this->assertTrue( $state['delete_local'] );
		$this->assertSame( 1, $state['failed'] );
		$this->assertFileDoesNotExist( $two );
		$this->assertSame( 'jpeg-2', $this->storage->objects['uploads/2026/01/image-2.jpg'] );
	}
}

/**
 * Just enough $wpdb for Backfill's queries.
 */
final class FakeWpdb {

	public string $posts    = 'wp_posts';
	public string $postmeta = 'wp_postmeta';

	/** @var array<int, mixed> */
	private array $args = [];

	public function __construct( private BackfillTest $test ) {}

	public function prepare( string $query, ...$args ): string {
		$this->args = $args;
		return $query;
	}

	/** @return string[] */
	public function get_col( string $query ): array {
		[ $cursor, $limit ] = array_slice( $this->args, -2 );
		$ids = array_filter( $this->test->attachments(), fn ( int $id ) => $id < $cursor && ! $this->test->meta( $id, Uploader::META ) );
		rsort( $ids );
		return array_map( 'strval', array_slice( $ids, 0, $limit ) );
	}

	/** @return array<string, int> */
	public function get_row( string $query, $output = null ): array {
		$ids = $this->test->attachments();
		return [
			'images' => count( $ids ),
			'in_r2'  => count( array_filter( $ids, fn ( $id ) => $this->test->meta( $id, Uploader::META ) ) ),
			'failed' => count( array_filter( $ids, fn ( $id ) => ! $this->test->meta( $id, Uploader::META ) && $this->test->meta( $id, Uploader::ERROR ) ) ),
		];
	}

	/** @return array<int, array<string, mixed>> */
	public function get_results( string $query, $output = null ): array {
		return [];
	}
}
