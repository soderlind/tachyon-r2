<?php

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2\Tests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\TachyonR2\Signer;

final class SignerTest extends TestCase {

	public function test_aws_get_object_example(): void {
		// https://docs.aws.amazon.com/AmazonS3/latest/API/sig-v4-header-based-auth.html
		$headers = Signer::sign(
			'GET',
			'examplebucket.s3.amazonaws.com',
			'/test.txt',
			[],
			[ 'Range' => 'bytes=0-9' ],
			Signer::EMPTY_HASH,
			'20130524T000000Z',
			'us-east-1',
			'AKIAIOSFODNN7EXAMPLE',
			'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'
		);

		$this->assertSame(
			'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
			$headers['authorization']
		);
		$this->assertArrayNotHasKey( 'host', $headers );
	}

	public function test_aws_list_objects_example(): void {
		$headers = Signer::sign(
			'GET',
			'examplebucket.s3.amazonaws.com',
			'/',
			[ 'max-keys' => '2', 'prefix' => 'J' ],
			[],
			Signer::EMPTY_HASH,
			'20130524T000000Z',
			'us-east-1',
			'AKIAIOSFODNN7EXAMPLE',
			'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'
		);
		$this->assertStringEndsWith( 'Signature=34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7', $headers['authorization'] );
	}

	public function test_canonical_query_is_sorted_and_encoded(): void {
		$this->assertSame( 'a=1&list-type=2&prefix=up%20loads%2Fx', Signer::canonical_query( [ 'prefix' => 'up loads/x', 'list-type' => '2', 'a' => '1' ] ) );
	}

	public function test_encode_key_keeps_slashes(): void {
		$this->assertSame( 'uploads/2026/01/my%20photo%2B1.jpg', Signer::encode_key( 'uploads/2026/01/my photo+1.jpg' ) );
	}
}
