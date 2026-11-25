<?php
/**
 * Structured, unambiguous cache keys.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Request;

defined( 'ABSPATH' ) || exit;

/**
 * Builds cache keys from a JSON structure instead of string concatenation, so different
 * parameter sets can never produce the same key.
 *
 * The key does not depend on how the route was given (`/wp-json/...` or `?rest_route=...`).
 * Vary header values are hashed before they are encoded, and the body of a non-GET request is only
 * ever present as its hash; raw values never reach a key or a display array.
 *
 * @since 1.0.0
 */
final class Cache_Key {

	/**
	 * Key format version.
	 *
	 * @var int
	 */
	public const VERSION = 1;

	/**
	 * Number of hex characters of a vary hash shown in {@see self::display()}.
	 *
	 * @var int
	 */
	public const DISPLAY_HASH_LENGTH = 12;

	/**
	 * JSON flags for the key structure.
	 *
	 * @var int
	 */
	private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

	/**
	 * The cache key: a sha256 hex digest.
	 *
	 * A GET keeps the exact v1 structure, so no GET key changes. A non-GET request is keyed by its method,
	 * the Content-Type class of its body and the hash of its canonical body (never the body itself). A request
	 * host that differs from the site's home host comes last; a request on the home host has no such member.
	 *
	 * @param Request_Context $context  Request.
	 * @param Policy_Decision $decision Cacheable decision (its key parameters, vary values, request host and, for a non-GET, body fields are used).
	 *
	 * @return string
	 *
	 * @throws \LogicException When the request is not a GET and the decision has no body hash.
	 */
	public static function key( Request_Context $context, Policy_Decision $decision ): string {
		$components = self::components( $context, $decision );

		$structure = [
			'v'      => self::VERSION,
			'blog'   => $components['blog'],
			'scheme' => $components['scheme'],
			'method' => $components['method'],
			'route'  => (string) $context->route,
			'params' => $decision->key_params,
			'vary'   => $components['vary'],
		];
		if ( null !== $components['body'] ) {
			$structure['content_type'] = $components['content_type'];
			$structure['body']         = $components['body'];
		}
		if ( null !== $components['host'] ) {
			$structure['host'] = $components['host'];
		}

		return self::digest( $structure );
	}

	/**
	 * The cache key a replacement string of a legacy `cache_key` callback is stored under: a sha256 hex digest.
	 *
	 * The replacement stands in for the route and the parameters only. Everything else the engine key tells requests
	 * apart by stays in: the blog, the scheme and every vary entry (the vary cookies included, the value of each
	 * hashed like in {@see self::key()}), so a callback that answers one string for two visitors the engine keeps apart
	 * can not merge them. A non-GET request also takes in its method, the Content-Type class of its body and the
	 * hash of its canonical body (never the body itself); a callback knows nothing of a body. A request host that
	 * differs from the site's home host stays in as well, last, so a callback can not merge the hosts either.
	 *
	 * @param string          $replacement Non-empty string the callback returned.
	 * @param Request_Context $context     Request.
	 * @param Policy_Decision $decision    Cacheable decision (its vary values, request host and, for a non-GET, body fields are used).
	 *
	 * @return string
	 *
	 * @throws \LogicException When the request is not a GET and the decision has no body hash.
	 */
	public static function replaced( string $replacement, Request_Context $context, Policy_Decision $decision ): string {
		$components = self::components( $context, $decision );

		$structure = [
			'v'      => self::VERSION,
			'legacy' => hash( 'sha256', $replacement ),
			'blog'   => $components['blog'],
			'scheme' => $components['scheme'],
			'vary'   => $components['vary'],
		];
		if ( null !== $components['body'] ) {
			$structure['method']       = $components['method'];
			$structure['content_type'] = $components['content_type'];
			$structure['body']         = $components['body'];
		}
		if ( null !== $components['host'] ) {
			$structure['host'] = $components['host'];
		}

		return self::digest( $structure );
	}

	/**
	 * Hash of the URL (route and parameters) without scheme, host and vary values; groups the variants of a URL.
	 *
	 * The request host stays out, so a flush by URL reaches the entries of every host.
	 *
	 * @param Request_Context $context Request.
	 * @param array<mixed>    $params  Key parameters.
	 *
	 * @return string
	 */
	public static function url_hash( Request_Context $context, array $params ): string {
		return self::digest(
			[
				'v'      => self::VERSION,
				'blog'   => $context->blog_id,
				// The 'GET' literal is part of the v1 format and makes this hash method-free: every method of a URL shares one hash, so a flush by URL reaches them all.
				'method' => 'GET',
				'route'  => (string) $context->route,
				'params' => $params,
			]
		);
	}

	/**
	 * Human-readable key parts. Vary values and the body of a non-GET request are shown as the first 12 hex characters of their sha256; a request host that differs from the home host is shown as it is.
	 *
	 * @param Request_Context $context  Request.
	 * @param Policy_Decision $decision Decision.
	 *
	 * @return array{route:string,params:array<int|string,string|array<int|string,mixed>>,vary:array<string,string>,body?:string,host?:string}
	 *
	 * @throws \LogicException When the request is not a GET and the decision has no body hash.
	 */
	public static function display( Request_Context $context, Policy_Decision $decision ): array {
		$components = self::components( $context, $decision, self::DISPLAY_HASH_LENGTH );

		$display = [
			'route'  => (string) $context->route,
			'params' => $decision->key_params,
			'vary'   => $components['vary'],
		];
		if ( null !== $components['body'] ) {
			$display['body'] = $components['body'];
		}
		if ( null !== $components['host'] ) {
			$display['host'] = $components['host'];
		}

		return $display;
	}

	/**
	 * The components that tell cache entries apart, taken in one place for every key builder.
	 *
	 * {@see self::key()}, {@see self::replaced()} and {@see self::display()} each assemble their own structure from these
	 * (the JSON member order is part of a digest and differs per builder), but none of them reads the vary entries, the blog,
	 * the scheme, the method, the body or the host of a request from the context or the decision itself. A component added
	 * here reaches every builder, so a replaced legacy key can not miss what the engine key keeps apart.
	 *
	 * `vary` holds the sha256 of every raw vary value, sorted by entry name as strings. `content_type` and `body` are null for
	 * a GET, whatever fields its decision carries; `body` is therefore null exactly for a GET, as a non-GET without a body hash
	 * throws. `host` is null on the home host. A raw vary value or body never leaves this method.
	 *
	 * @param Request_Context $context     Request.
	 * @param Policy_Decision $decision    Cacheable decision.
	 * @param int|null        $hash_length Hex characters kept of each vary hash and of the body hash; null keeps all 64.
	 *
	 * @return array{vary:array<string,string>,blog:int,scheme:string,method:string,content_type:string|null,body:string|null,host:string|null}
	 *
	 * @throws \LogicException When the request is not a GET and the decision has no body hash.
	 */
	private static function components( Request_Context $context, Policy_Decision $decision, ?int $hash_length = null ): array {
		$shorten = static fn( string $hash ): string => null === $hash_length ? $hash : substr( $hash, 0, $hash_length );

		$content_type = null;
		$body         = null;
		if ( 'GET' !== $context->method ) {
			if ( null === $decision->body_hash ) {
				throw new \LogicException( 'A non-GET cache key needs the body hash of the decision.' );
			}
			$content_type = $decision->body_class;
			$body         = $shorten( $decision->body_hash );
		}

		$vary = [];
		foreach ( $decision->vary as $name => $value ) {
			$vary[ $name ] = $shorten( hash( 'sha256', $value ) );
		}
		ksort( $vary, SORT_STRING );

		return [
			'vary'         => $vary,
			'blog'         => $context->blog_id,
			'scheme'       => $context->scheme,
			'method'       => $context->method,
			'content_type' => $content_type,
			'body'         => $body,
			'host'         => $decision->host,
		];
	}

	/**
	 * Sha256 of the JSON encoding of a key structure.
	 *
	 * The policy rejects non-UTF-8 input before a key is computed. Should encoding still fail (for example
	 * for a context that did not pass the policy), the structure is hashed in its serialised form instead,
	 * which is equally unambiguous, so computing a key never fatals.
	 *
	 * @param array<string,mixed> $data Key structure.
	 *
	 * @return string
	 */
	private static function digest( array $data ): string {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Must throw instead of wp_json_encode()'s lossy UTF-8 fallback, which could make keys collide.
			return hash( 'sha256', json_encode( $data, self::JSON_FLAGS ) );
		} catch ( \JsonException $e ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Only hashed, never stored or unserialised.
			return hash( 'sha256', 'serialize:' . serialize( $data ) );
		}
	}
}
