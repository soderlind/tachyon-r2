<?php
/**
 * R2 S3 API client over the WordPress HTTP API.
 *
 * @package TachyonR2
 */

declare(strict_types=1);

namespace Soderlind\Plugin\TachyonR2;

use WP_Error;

/**
 * Path-style S3 client for Cloudflare R2.
 */
final class Client implements Storage {

	private string $endpoint;
	private string $host;
	private string $bucket;
	private string $key_id;
	private string $secret;
	private string $region;

	/**
	 * @param string $endpoint Endpoint URL, e.g. https://<account>.r2.cloudflarestorage.com.
	 * @param string $bucket   Bucket.
	 * @param string $key_id   Access key ID.
	 * @param string $secret   Secret access key.
	 * @param string $region   Region.
	 */
	public function __construct( string $endpoint, string $bucket, string $key_id, string $secret, string $region = 'auto' ) {
		$this->endpoint = rtrim( $endpoint, '/' );
		$parts          = parse_url( $this->endpoint );
		$this->host     = ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$this->bucket   = $bucket;
		$this->key_id   = $key_id;
		$this->secret   = $secret;
		$this->region   = $region;
	}

	/** Client from the plugin configuration. */
	public static function from_config(): self {
		return new self(
			Config::endpoint(),
			(string) Config::get( 'bucket' ),
			(string) Config::get( 'access_key_id' ),
			(string) Config::get( 'secret_access_key' )
		);
	}

	public function put( string $key, string $body, string $content_type = '' ) {
		$headers = [ 'content-type' => '' !== $content_type ? $content_type : self::mime( $key ) ];
		$res     = $this->request( 'PUT', $key, [], $headers, $body );
		return is_wp_error( $res ) ? $res : true;
	}

	public function put_file( string $key, string $path, string $content_type = '' ) {
		$body = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( false === $body ) {
			return new WP_Error( 'tachyon_r2_read', sprintf( 'Cannot read %s', $path ) );
		}
		return $this->put( $key, $body, $content_type );
	}

	public function get( string $key ) {
		$res = $this->request( 'GET', $key );
		return is_wp_error( $res ) ? $res : (string) wp_remote_retrieve_body( $res );
	}

	public function get_to_file( string $key, string $path ) {
		$res = $this->request( 'GET', $key, [], [], '', [ 'stream' => true, 'filename' => $path ] );
		if ( is_wp_error( $res ) ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
			return $res;
		}
		return true;
	}

	public function head( string $key ) {
		$res = $this->request( 'HEAD', $key );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$modified = (string) wp_remote_retrieve_header( $res, 'last-modified' );
		return [
			'size'  => (int) wp_remote_retrieve_header( $res, 'content-length' ),
			'mtime' => '' !== $modified ? (int) strtotime( $modified ) : 0,
			'type'  => (string) wp_remote_retrieve_header( $res, 'content-type' ),
		];
	}

	public function delete( string $key ) {
		$res = $this->request( 'DELETE', $key );
		if ( is_wp_error( $res ) && 'not_found' !== $res->get_error_code() ) {
			return $res;
		}
		return true;
	}

	public function copy( string $from, string $to ) {
		$source = '/' . $this->bucket . '/' . Signer::encode_key( $from );
		$res    = $this->request( 'PUT', $to, [], [ 'x-amz-copy-source' => $source ] );
		return is_wp_error( $res ) ? $res : true;
	}

	public function list( string $prefix, string $delimiter = '', int $max = 1000, ?string $token = null ) {
		$query = [
			'list-type' => '2',
			'max-keys'  => (string) $max,
			'prefix'    => $prefix,
		];
		if ( '' !== $delimiter ) {
			$query['delimiter'] = $delimiter;
		}
		if ( null !== $token ) {
			$query['continuation-token'] = $token;
		}
		$res = $this->request( 'GET', null, $query );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$xml = self::xml( (string) wp_remote_retrieve_body( $res ) );
		if ( null === $xml ) {
			return new WP_Error( 'tachyon_r2_xml', 'Invalid ListObjectsV2 response' );
		}
		$keys = [];
		foreach ( $xml->Contents ?? [] as $item ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$keys[] = [
				'key'   => (string) $item->Key, // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				'size'  => (int) $item->Size, // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				'mtime' => (int) strtotime( (string) $item->LastModified ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			];
		}
		$prefixes = [];
		foreach ( $xml->CommonPrefixes ?? [] as $item ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$prefixes[] = (string) $item->Prefix; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}
		$truncated = 'true' === (string) $xml->IsTruncated; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		return [
			'keys'     => $keys,
			'prefixes' => $prefixes,
			'next'     => $truncated ? (string) $xml->NextContinuationToken : null, // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		];
	}

	/**
	 * Verify credentials and bucket access.
	 *
	 * @return true|\WP_Error
	 */
	public function check() {
		$res = $this->list( '', '', 1 );
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * Signed request.
	 *
	 * @param string                $method  Method.
	 * @param string|null           $key     Object key, null for the bucket.
	 * @param array<string, string> $query   Query.
	 * @param array<string, string> $headers Headers.
	 * @param string                $body    Body.
	 * @param array<string, mixed>  $extra   Extra wp_remote_request args.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request( string $method, ?string $key, array $query = [], array $headers = [], string $body = '', array $extra = [] ) {
		$uri = '/' . $this->bucket . ( null === $key ? '' : '/' . Signer::encode_key( $key ) );
		if ( null === $key ) {
			$uri .= '/';
		}
		$signed = Signer::sign(
			$method,
			$this->host,
			$uri,
			$query,
			$headers,
			hash( 'sha256', $body ),
			gmdate( 'Ymd\THis\Z' ),
			$this->region,
			$this->key_id,
			$this->secret
		);
		$qs  = Signer::canonical_query( $query );
		$url = $this->endpoint . $uri . ( '' !== $qs ? '?' . $qs : '' );

		$res = wp_remote_request(
			$url,
			array_merge(
				[
					'method'  => $method,
					'headers' => $signed,
					'body'    => 'GET' === $method || 'HEAD' === $method ? null : $body,
					'timeout' => 60,
				],
				$extra
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$status = (int) wp_remote_retrieve_response_code( $res );
		if ( $status >= 200 && $status < 300 ) {
			return $res;
		}

		$body = isset( $extra['filename'] ) && is_readable( $extra['filename'] )
			? (string) file_get_contents( $extra['filename'] ) // phpcs:ignore WordPress.WP.AlternativeFunctions
			: (string) wp_remote_retrieve_body( $res );
		$xml  = self::xml( $body );
		$code = 404 === $status ? 'not_found' : 'tachyon_r2_http_' . $status;
		$msg  = null !== $xml && isset( $xml->Message ) ? (string) $xml->Message : "HTTP $status"; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		if ( null !== $xml && isset( $xml->Code ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$msg = (string) $xml->Code . ': ' . $msg; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}
		return new WP_Error( $code, $msg, [ 'status' => $status ] );
	}

	/**
	 * Parse XML without external entities.
	 *
	 * @param string $body Body.
	 */
	private static function xml( string $body ): ?\SimpleXMLElement {
		if ( '' === trim( $body ) ) {
			return null;
		}
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $body, \SimpleXMLElement::class, LIBXML_NONET );
		libxml_use_internal_errors( $prev );
		return false === $xml ? null : $xml;
	}

	/**
	 * Content type from the key's extension.
	 *
	 * @param string $key Object key.
	 */
	public static function mime( string $key ): string {
		$type = wp_check_filetype( $key );
		return $type['type'] ? (string) $type['type'] : 'application/octet-stream';
	}
}
