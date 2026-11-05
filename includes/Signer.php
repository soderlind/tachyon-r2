<?php
/**
 * AWS Signature Version 4 for the R2 S3 API.
 *
 * @package TachyonR2
 */

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2;

/**
 * Signs S3 requests with an Authorization header.
 */
final class Signer {

	/** SHA-256 of an empty payload. */
	public const EMPTY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	/**
	 * Return the request headers with x-amz-date, x-amz-content-sha256 and Authorization added.
	 *
	 * Every header passed in is signed, plus Host. Header names are returned lower-case.
	 *
	 * @param string                $method       HTTP method.
	 * @param string                $host         Host (and port, if not default).
	 * @param string                $uri          Canonical, already URI-encoded path.
	 * @param array<string, string> $query        Query parameters, not encoded.
	 * @param array<string, string> $headers      Headers to sign.
	 * @param string                $payload_hash Hex SHA-256 of the body.
	 * @param string                $amz_date     Timestamp, YYYYMMDD'T'HHMMSS'Z'.
	 * @param string                $region       Region (`auto` for R2).
	 * @param string                $key_id       Access key ID.
	 * @param string                $secret       Secret access key.
	 * @param string                $service      Service name.
	 * @return array<string, string>
	 */
	public static function sign(
		string $method,
		string $host,
		string $uri,
		array $query,
		array $headers,
		string $payload_hash,
		string $amz_date,
		string $region,
		string $key_id,
		string $secret,
		string $service = 's3'
	): array {
		$headers = array_change_key_case( $headers, CASE_LOWER );
		$headers['x-amz-date'] = $amz_date;
		$headers['x-amz-content-sha256'] = $payload_hash;

		$signed = $headers + [ 'host' => $host ];
		ksort( $signed );
		$canonical_headers = '';
		foreach ( $signed as $name => $value ) {
			$canonical_headers .= $name . ':' . trim( preg_replace( '/\s+/', ' ', (string) $value ) ) . "\n";
		}
		$signed_headers = implode( ';', array_keys( $signed ) );

		$canonical_request = implode( "\n", [
			strtoupper( $method ),
			$uri,
			self::canonical_query( $query ),
			$canonical_headers,
			$signed_headers,
			$payload_hash,
		] );

		$date  = substr( $amz_date, 0, 8 );
		$scope = "$date/$region/$service/aws4_request";
		$to_sign = implode( "\n", [ 'AWS4-HMAC-SHA256', $amz_date, $scope, hash( 'sha256', $canonical_request ) ] );

		$key = hash_hmac( 'sha256', $date, 'AWS4' . $secret, true );
		$key = hash_hmac( 'sha256', $region, $key, true );
		$key = hash_hmac( 'sha256', $service, $key, true );
		$key = hash_hmac( 'sha256', 'aws4_request', $key, true );

		$headers['authorization'] = sprintf(
			'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$key_id,
			$scope,
			$signed_headers,
			hash_hmac( 'sha256', $to_sign, $key )
		);
		return $headers;
	}

	/**
	 * Sorted, RFC 3986-encoded query string.
	 *
	 * @param array<string, string> $query Query parameters.
	 */
	public static function canonical_query( array $query ): string {
		ksort( $query, SORT_STRING );
		$pairs = [];
		foreach ( $query as $name => $value ) {
			$pairs[] = rawurlencode( (string) $name ) . '=' . rawurlencode( (string) $value );
		}
		return implode( '&', $pairs );
	}

	/**
	 * Encode an object key for the request path, keeping `/` separators.
	 *
	 * @param string $key Object key.
	 */
	public static function encode_key( string $key ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
	}
}
