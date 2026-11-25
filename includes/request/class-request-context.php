<?php
/**
 * Normalised, immutable view of the current request.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Request;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable request snapshot, identical for `/wp-json/...` and `?rest_route=...` requests.
 *
 * This is the only class in the engine that reads the request superglobals (and only in
 * {@see Request_Context::from_globals()}, which reads only the keys it names, never a whole superglobal). It is
 * must-use-phase safe: it applies no filters of its own and reads only `home_url()`, the `permalink_structure`
 * option, `is_ssl()` and `get_current_blog_id()` (core's option and URL filters still apply to those). It never emits
 * notices, also not when `$_SERVER` is empty (CLI).
 *
 * For a REST request with a method in {@see Request_Context::BODY_METHODS} it also reads the request body
 * (through a reader, at most {@see Request_Context::MAX_BODY_BYTES} bytes), classifies it and keeps its decoded
 * top-level parameters; the raw body itself is never stored. For a REST request with any other method it reads at
 * most 1 byte of the body, only to learn whether the request carries one ({@see Request_Context::$has_body}).
 *
 * @since 1.0.0
 */
final class Request_Context {

	/**
	 * Methods whose body is read (for a REST route only).
	 */
	public const BODY_METHODS = array( 'POST', 'PUT', 'PATCH' );

	/**
	 * The `$_SERVER` keys {@see self::from_globals()} always reads, each only when it is a string (`CONTENT_LENGTH` also when
	 * it is an integer). It adds `HTTP_<NAME>` for each header name it is given, and `CONTENT_TYPE` last.
	 */
	private const SERVER_KEYS = array(
		'REQUEST_METHOD',
		'REQUEST_URI',
		'QUERY_STRING',
		'PATH_INFO',
		'CONTENT_LENGTH',
		'HTTP_HOST',
		'PHP_AUTH_USER',
		'REDIRECT_HTTP_AUTHORIZATION',
		'HTTP_AUTHORIZATION',
		'HTTP_X_WP_NONCE',
		'HTTP_ORIGIN',
		'HTTP_CONTENT_TYPE',
		'HTTP_TRANSFER_ENCODING',
		'HTTP_X_HTTP_METHOD_OVERRIDE',
	);

	/**
	 * Largest body read, in bytes; a larger one is a `too_large` body error.
	 */
	public const MAX_BODY_BYTES = 65536;

	/**
	 * Deepest JSON nesting decoded; a deeper body is an `invalid` body error.
	 */
	public const MAX_BODY_DEPTH = 32;

	/**
	 * Content class: a JSON object body (`application/json` or `application/*+json`).
	 */
	public const CLASS_JSON = 'json';

	/**
	 * Content class: a URL-encoded form body.
	 */
	public const CLASS_FORM = 'form';

	/**
	 * Content class: no body and no Content-Type.
	 */
	public const CLASS_NONE = 'none';

	/**
	 * Body error: a `multipart/*` body (never read).
	 */
	public const ERROR_MULTIPART = 'multipart';

	/**
	 * Body error: a Content-Type that is neither JSON nor form.
	 */
	public const ERROR_UNSUPPORTED = 'unsupported';

	/**
	 * Body error: the body does not parse (bad JSON, JSON that is not an object, too deep, or non-UTF-8 form data).
	 */
	public const ERROR_INVALID = 'invalid';

	/**
	 * Body error: the body is larger than {@see self::MAX_BODY_BYTES}.
	 */
	public const ERROR_TOO_LARGE = 'too_large';

	/**
	 * Signal: an `Authorization` header is present.
	 */
	public const SIGNAL_AUTHORIZATION = 'authorization';

	/**
	 * Signal: HTTP basic auth user (`PHP_AUTH_USER`) is present.
	 */
	public const SIGNAL_PHP_AUTH_USER = 'php_auth_user';

	/**
	 * Signal: an `X-WP-Nonce` header is present.
	 */
	public const SIGNAL_X_WP_NONCE = 'x_wp_nonce';

	/**
	 * Signal: a `_wpnonce` parameter is present in GET, POST or the decoded request body.
	 */
	public const SIGNAL_WPNONCE_PARAM = 'wpnonce_param';

	/**
	 * Signal: a non-empty `password` parameter is present in GET, POST or the decoded request body (it unlocks
	 * password-protected content).
	 */
	public const SIGNAL_PASSWORD_PARAM = 'password_param';

	/**
	 * Route source: the route came from the URL path.
	 */
	public const SOURCE_PATH = 'path';

	/**
	 * Route source: the route came from the `rest_route` query parameter.
	 */
	public const SOURCE_QUERY = 'query';

	/**
	 * Upper-case HTTP method (default `GET`).
	 *
	 * @var string
	 */
	public readonly string $method;

	/**
	 * Whether this is a REST request.
	 *
	 * @var bool
	 */
	public readonly bool $is_rest;

	/**
	 * The REST route (leading slash, no trailing slash), or null when not a REST request.
	 *
	 * @var string|null
	 */
	public readonly ?string $route;

	/**
	 * Where the route came from: `path`, `query` or null.
	 *
	 * @var string|null
	 */
	public readonly ?string $route_source;

	/**
	 * GET parameters without `rest_route`, recursively sorted by key (nested lists keep their order).
	 *
	 * @var array<int|string,string|array<int|string,mixed>>
	 */
	public readonly array $params;

	/**
	 * Request headers: lower-case, dash separated names.
	 *
	 * @var array<string,string>
	 */
	public readonly array $headers;

	/**
	 * Names of the headers the context could see: lower-case, dash separated, sorted. A header outside this list that
	 * is missing from {@see self::$headers} is unknown, not absent.
	 *
	 * Null when the context holds every header it was given ({@see self::from_parts()} called without the list).
	 *
	 * @var string[]|null
	 * @phpstan-var list<string>|null
	 */
	public readonly ?array $read_headers;

	/**
	 * Names of the cookies sent with the request.
	 *
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	public readonly array $cookie_names;

	/**
	 * Values of the cookies the caller asked for, keyed by every requested name.
	 *
	 * Only the requested cookies are held: a string value, `''` when the cookie is absent or empty, and
	 * null when the cookie is present but not a string (for example `cur[]=a`).
	 *
	 * @var array<string,string|null>
	 */
	public readonly array $vary_cookies;

	/**
	 * Credential signals present on the request (see the SIGNAL_* constants).
	 *
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	public readonly array $signals;

	/**
	 * The blog ID the request was resolved for.
	 *
	 * @var int
	 */
	public readonly int $blog_id;

	/**
	 * `https` or `http`.
	 *
	 * @var string
	 */
	public readonly string $scheme;

	/**
	 * The `Origin` header, or null.
	 *
	 * @var string|null
	 */
	public readonly ?string $origin;

	/**
	 * Length in bytes of the raw query string.
	 *
	 * @var int
	 */
	public readonly int $query_length;

	/**
	 * The Content-Type class of the body (see the CLASS_* constants), set once the body was read and classified as
	 * JSON, form or none; null when no body was read (not a REST request, or a method outside
	 * {@see self::BODY_METHODS}) and for the body errors found before the Content-Type is classified
	 * (`multipart`, `too_large`) and for `unsupported`.
	 *
	 * @var string|null
	 */
	public readonly ?string $content_class;

	/**
	 * Why the body cannot be keyed (see the ERROR_* constants), or null.
	 *
	 * @var string|null
	 */
	public readonly ?string $body_error;

	/**
	 * Top-level body parameters, sorted by key (`SORT_STRING`); empty without a decodable body.
	 *
	 * A form body holds what {@see self::normalise_params()} makes of it (strings and nested arrays). A JSON body holds
	 * the decoded values with nested objects as `stdClass` (key-sorted), so `{}` and `[]` stay distinct.
	 *
	 * @var array<int|string,mixed>
	 */
	public readonly array $body_params;

	/**
	 * Whether an `X-HTTP-Method-Override` header is present (even empty), on any method.
	 *
	 * @var bool
	 */
	public readonly bool $method_override;

	/**
	 * Whether the request carries any body, whatever its method: a `Content-Length` that is not all zeros, a
	 * `Transfer-Encoding` header, or (for a REST request) at least one body byte.
	 *
	 * @var bool
	 */
	public readonly bool $has_body;

	/**
	 * The request host: the lower-cased `HTTP_HOST` without the scheme's default port, or null when it is missing.
	 *
	 * @var string|null
	 */
	public readonly ?string $host;

	/**
	 * Use the named constructors.
	 *
	 * @param string                                           $method          Upper-case method.
	 * @param bool                                             $is_rest         Whether this is a REST request.
	 * @param string|null                                      $route           Route.
	 * @param string|null                                      $route_source    Route source.
	 * @param array<int|string,string|array<int|string,mixed>> $params          Normalised GET params.
	 * @param array<string,string>                             $headers         Headers.
	 * @param string[]                                         $cookie_names    Cookie names.
	 * @param string[]                                         $signals         Credential signals.
	 * @param int                                              $blog_id         Blog ID.
	 * @param string                                           $scheme          Scheme.
	 * @param string|null                                      $origin          Origin header.
	 * @param int                                              $query_length    Raw query string length.
	 * @param array<string,string|null>                        $vary_cookies    Values of the requested cookies.
	 * @param string|null                                      $content_class   Content-Type class of the body.
	 * @param string|null                                      $body_error      Body error.
	 * @param array<int|string,mixed>                          $body_params     Top-level body parameters.
	 * @param bool                                             $method_override Whether a method override header is present.
	 * @param bool                                             $has_body        Whether the request carries any body.
	 * @param string|null                                      $host            Normalised request host.
	 * @param string[]|null                                    $read_headers    Names of the headers the context could see, or null for all.
	 *
	 * @phpstan-param list<string> $cookie_names
	 * @phpstan-param list<string> $signals
	 * @phpstan-param list<string>|null $read_headers
	 */
	private function __construct(
		string $method,
		bool $is_rest,
		?string $route,
		?string $route_source,
		array $params,
		array $headers,
		array $cookie_names,
		array $signals,
		int $blog_id,
		string $scheme,
		?string $origin,
		int $query_length,
		array $vary_cookies,
		?string $content_class,
		?string $body_error,
		array $body_params,
		bool $method_override,
		bool $has_body = false,
		?string $host = null,
		?array $read_headers = null
	) {
		$this->method          = $method;
		$this->is_rest         = $is_rest;
		$this->route           = $route;
		$this->route_source    = $route_source;
		$this->params          = $params;
		$this->headers         = $headers;
		$this->read_headers    = $read_headers;
		$this->cookie_names    = $cookie_names;
		$this->signals         = $signals;
		$this->blog_id         = $blog_id;
		$this->scheme          = $scheme;
		$this->origin          = $origin;
		$this->query_length    = $query_length;
		$this->vary_cookies    = $vary_cookies;
		$this->content_class   = $content_class;
		$this->body_error      = $body_error;
		$this->body_params     = $body_params;
		$this->method_override = $method_override;
		$this->has_body        = $has_body;
		$this->host            = $host;
	}

	/**
	 * Build the context for the current request. The only place the superglobals, the `HTTP_RAW_POST_DATA` global and
	 * the request body stream are read.
	 *
	 * Only the named keys are read: the fixed {@see self::SERVER_KEYS} and `CONTENT_TYPE`, plus `HTTP_<NAME>` for
	 * each header name passed; every `$_GET` parameter; the names of all cookies and the values of the vary cookies; the
	 * `_wpnonce` and `password` keys of `$_POST`. Each value is read only when it has the type the context expects, and is
	 * unslashed once WordPress has added the slashes, never otherwise changed. A form-encoded body is parsed from the raw
	 * body, never from `$_POST`.
	 *
	 * The context records the headers it read ({@see self::$read_headers}, answered by {@see self::has_read_header()}), so
	 * the policy can tell a header the request lacks from one this context never looked at, for example when the
	 * snapshot was recompiled after the names were passed.
	 *
	 * @param string       $rest_prefix       The REST URL prefix (e.g. `wp-json`).
	 * @param array<mixed> $vary_cookie_names Names of the cookies whose values to keep (see {@see self::$vary_cookies}).
	 * @param array<mixed> $header_names      Names of the request headers to read besides the fixed ones, e.g.
	 *                                        `Compiled_Config::request_header_names()` (non-string names are ignored).
	 *
	 * @return self
	 */
	public static function from_globals( string $rest_prefix, array $vary_cookie_names = array(), array $header_names = array() ): self {
		// WordPress adds slashes to the superglobals in wp_magic_quotes(); undo that once it has run, value by value.
		$unslash = did_action( 'sanitize_comment_cookies' ) > 0;

		$server_keys  = self::SERVER_KEYS;
		$read_headers = array( 'content-type' );
		foreach ( $server_keys as $key ) {
			if ( str_starts_with( $key, 'HTTP_' ) ) {
				$read_headers[] = substr( $key, 5 );
			}
		}
		foreach ( $header_names as $name ) {
			if ( is_string( $name ) && '' !== $name ) {
				$server_keys[]  = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
				$read_headers[] = $name;
			}
		}
		// After `HTTP_CONTENT_TYPE`, so that it is the one `headers_from_server()` ends on.
		$server_keys[] = 'CONTENT_TYPE';

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Only the named keys are read; values feed the cache key and policy comparisons, are unslashed under the sanitize_comment_cookies rule, and are never output or trusted.
		$server = array();
		foreach ( $server_keys as $key ) {
			if ( ! isset( $_SERVER[ $key ] ) ) {
				continue;
			}
			$value = $_SERVER[ $key ];
			if ( is_string( $value ) ) {
				$server[ $key ] = $unslash ? wp_unslash( $value ) : $value;
			} elseif ( 'CONTENT_LENGTH' === $key && is_int( $value ) ) {
				$server[ $key ] = $value;
			}
		}

		$get = array();
		foreach ( $_GET as $name => $value ) {
			$read = self::typed_input( $value, $unslash );
			if ( null !== $read ) {
				$get[ $name ] = $read;
			}
		}

		$post = array();
		foreach ( array( '_wpnonce', 'password' ) as $name ) {
			if ( isset( $_POST[ $name ] ) ) {
				$read = self::typed_input( $_POST[ $name ], $unslash );
				if ( null !== $read ) {
					$post[ $name ] = $read;
				}
			}
		}

		// Every cookie name counts (the login and session cookies are found by name); only a vary cookie keeps its value.
		$cookie = array_fill_keys( array_keys( $_COOKIE ), '' );
		foreach ( $vary_cookie_names as $name ) {
			if ( is_string( $name ) && array_key_exists( $name, $_COOKIE ) ) {
				$value = $_COOKIE[ $name ];
				if ( ! is_string( $value ) ) {
					$cookie[ $name ] = null;
				} else {
					$cookie[ $name ] = $unslash ? wp_unslash( $value ) : $value;
				}
			}
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

		// The body reader, called only for a REST request: for a POST/PUT/PATCH up to the body cap, for any other method
		// for 1 byte at most. Core's own source order, the `HTTP_RAW_POST_DATA` global when it is a string (the PHPUnit
		// seam), else the request stream, never more than $max bytes.
		$body = static function ( int $max ): ?string {
			// The raw body is decoded and canonicalised by the caller, never output or trusted.
			$max = max( 0, $max );
			$raw = $GLOBALS['HTTP_RAW_POST_DATA'] ?? null;
			if ( is_string( $raw ) ) {
				return substr( $raw, 0, $max );
			}
			$stream = file_get_contents( 'php://input', false, null, 0, $max ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local stream, as core's get_raw_data().
			return false === $stream ? null : $stream;
		};

		return self::from_parts(
			$server,
			$get,
			$post,
			$cookie,
			$rest_prefix,
			self::home_path(),
			'' !== (string) get_option( 'permalink_structure' ),
			(int) get_current_blog_id(),
			(bool) is_ssl(),
			$vary_cookie_names,
			$body,
			$read_headers
		);
	}

	/**
	 * A request input as the context keeps it: a string, or an array of such values nested to any depth. Every other
	 * type is dropped (null), also inside an array. A string is unslashed on request, never otherwise changed.
	 *
	 * @param mixed $value   The input value.
	 * @param bool  $unslash Whether to undo the slashes WordPress added.
	 *
	 * @return string|array<int|string,mixed>|null
	 */
	private static function typed_input( mixed $value, bool $unslash ): string|array|null {
		if ( is_string( $value ) ) {
			return $unslash ? wp_unslash( $value ) : $value;
		}
		if ( ! is_array( $value ) ) {
			return null;
		}
		$out = array();
		foreach ( $value as $key => $item ) {
			$read = self::typed_input( $item, $unslash );
			if ( null !== $read ) {
				$out[ $key ] = $read;
			}
		}
		return $out;
	}

	/**
	 * Build a context from explicit request data (no global state).
	 *
	 * @param array<mixed>         $server            Server variables.
	 * @param array<mixed>         $get               Query parameters.
	 * @param array<mixed>         $post              Body parameters.
	 * @param array<mixed>         $cookie            Cookies.
	 * @param string               $rest_prefix       REST URL prefix (e.g. `wp-json`).
	 * @param string               $home_path         Path of the site's home URL (e.g. `/sub` or empty).
	 * @param bool                 $pretty_permalinks Whether a permalink structure is configured.
	 * @param int                  $blog_id           Blog ID.
	 * @param bool                 $ssl               Whether the request uses HTTPS.
	 * @param array<mixed>         $vary_cookie_names Names of the cookies whose values to keep (non-string names are ignored).
	 * @param string|\Closure|null $body              The raw request body, or a reader `fn( int $max ): ?string` returning at
	 *                                                most `$max` bytes of it; only used for a REST request: read up to the body cap
	 *                                                for a method in {@see self::BODY_METHODS}, 1 byte at most for any other
	 *                                                method (null is an empty body).
	 * @param array<mixed>|null    $read_headers      Names of the headers `$server` was limited to, or null when it holds every
	 *                                                header of the request (see {@see self::$read_headers}; non-string names are
	 *                                                ignored).
	 *
	 * @return self
	 *
	 * @phpstan-param string|(\Closure(int):(string|null))|null $body
	 */
	public static function from_parts(
		array $server,
		array $get,
		array $post,
		array $cookie,
		string $rest_prefix,
		string $home_path,
		bool $pretty_permalinks,
		int $blog_id,
		bool $ssl,
		array $vary_cookie_names = array(),
		string|\Closure|null $body = null,
		?array $read_headers = null
	): self {
		$method = isset( $server['REQUEST_METHOD'] ) && is_string( $server['REQUEST_METHOD'] ) && '' !== $server['REQUEST_METHOD']
			? strtoupper( $server['REQUEST_METHOD'] )
			: 'GET';

		$uri   = isset( $server['REQUEST_URI'] ) && is_string( $server['REQUEST_URI'] ) ? $server['REQUEST_URI'] : '';
		$parts = self::split_uri( $uri );

		$query_string = $parts['query'];
		if ( '' === $uri && isset( $server['QUERY_STRING'] ) && is_string( $server['QUERY_STRING'] ) ) {
			$query_string = $server['QUERY_STRING'];
		}

		$route        = null;
		$route_source = null;
		if ( isset( $get['rest_route'] ) ) {
			// An empty, `0`, array or relative `rest_route` is not a route core serves: not cacheable.
			$route        = is_string( $get['rest_route'] ) ? self::normalise_route( $get['rest_route'] ) : null;
			$route_source = null === $route ? null : self::SOURCE_QUERY;
		} elseif ( $pretty_permalinks ) {
			$path_info = isset( $server['PATH_INFO'] ) && is_string( $server['PATH_INFO'] ) ? $server['PATH_INFO'] : '';
			$route     = self::route_from_path( $parts['path'], $rest_prefix, $home_path, $path_info );
			if ( null !== $route ) {
				$route_source = self::SOURCE_PATH;
			}
		}

		unset( $get['rest_route'] );

		$headers = self::headers_from_server( $server );

		$class    = null;
		$error    = null;
		$params   = array();
		$has_body = self::declares_body( $server, $headers );
		if ( null !== $route && in_array( $method, self::BODY_METHODS, true ) ) {
			$read = false;

			list( $class, $error, $params ) = self::read_body( $headers, $server, $body, $read );

			$has_body = $has_body || $read;
		} elseif ( null !== $route && ! $has_body ) {
			// Not a body method: one byte tells whether there is a body, and it is never classified or decoded.
			$has_body = self::has_first_byte( $body );
		}

		return new self(
			$method,
			null !== $route,
			$route,
			$route_source,
			self::normalise_params( $get ),
			$headers,
			self::cookie_names( $cookie ),
			self::signals( $server, $headers, $get, $post, $params ),
			$blog_id,
			$ssl ? 'https' : 'http',
			$headers['origin'] ?? null,
			strlen( $query_string ),
			self::vary_cookies( $cookie, $vary_cookie_names ),
			$class,
			$error,
			$params,
			array_key_exists( 'x-http-method-override', $headers ),
			$has_body,
			self::host( $server, $ssl ),
			self::header_keys( $read_headers )
		);
	}

	/**
	 * The canonical form of the request body: a JSON object with its keys sorted (`SORT_STRING`, recursively), lists
	 * kept in order, `{}` and `[]` kept apart, minus the given top-level keys.
	 *
	 * Equal for bodies that differ only in key order, whitespace or escaping. Empty for a request without a keyable
	 * body (no body was read, there is a body error, or the body class is `none`).
	 *
	 * @param array<mixed> $drop_top_level_keys Top-level keys left out (for example `_wpnonce`).
	 *
	 * @return string
	 */
	public function canonical_body( array $drop_top_level_keys ): string {
		if ( null === $this->content_class || null !== $this->body_error || self::CLASS_NONE === $this->content_class ) {
			return '';
		}

		$drop = array();
		foreach ( $drop_top_level_keys as $key ) {
			if ( is_string( $key ) || is_int( $key ) ) {
				$drop[ (string) $key ] = true;
			}
		}

		$body = new \stdClass();
		foreach ( $this->body_params as $key => $value ) {
			$name = (string) $key;
			if ( ! isset( $drop[ $name ] ) ) {
				$body->$name = $value;
			}
		}
		// A body that does not encode is rejected as invalid when it is read, so no body reaches this point unencodable.
		$json = self::encode_body( $body );
		return false === $json ? '' : $json;
	}

	/**
	 * Encode body parameters to their canonical JSON form.
	 *
	 * @param \stdClass $body Top-level body parameters as an object.
	 *
	 * @return string|false The canonical form, or false when a value cannot be encoded (for example a float that overflowed to INF).
	 */
	private static function encode_body( \stdClass $body ): string|false {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fixed flags and no lossy UTF-8 or partial-output fallback: a value that cannot be encoded gives false, never a string shared with another body.
		return json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
	}

	/**
	 * Build a context for a URL exactly as given (GET, no headers or cookies).
	 *
	 * @param string $url         Absolute or root-relative URL.
	 * @param string $rest_prefix REST URL prefix (e.g. `wp-json`).
	 *
	 * @return self
	 */
	public static function from_url( string $url, string $rest_prefix ): self {
		return self::url_variants( $url, $rest_prefix )[0];
	}

	/**
	 * The contexts a request for a URL may have been stored under; used when flushing a URL.
	 *
	 * Entries are keyed by the path as the client sent it, so a flush tries: the path as given, its
	 * {@see self::canonical_path()} (`/x/café` and `/x/caf%c3%a9` reach `/x/caf%C3%A9`), and for an
	 * `index.php/` URL the `PATH_INFO` request the web server decodes it to. Over-flushing is harmless.
	 *
	 * @param string       $url               Absolute or root-relative URL.
	 * @param string       $rest_prefix       REST URL prefix (e.g. `wp-json`).
	 * @param array<mixed> $vary_cookie_names Names of the cookies whose values to keep; a URL carries no cookies, so
	 *                                        every variant holds `''` for each of them.
	 *
	 * @return self[] At least one context; the first one is the URL as given.
	 * @phpstan-return non-empty-list<self>
	 */
	public static function url_variants( string $url, string $rest_prefix, array $vary_cookie_names = array() ): array {
		$parsed = wp_parse_url( $url );
		$parsed = is_array( $parsed ) ? $parsed : array();
		$path   = isset( $parsed['path'] ) && '' !== $parsed['path'] ? $parsed['path'] : '/';
		$query  = isset( $parsed['query'] ) ? $parsed['query'] : '';
		$suffix = '' !== $query ? '?' . $query : '';

		$get = array();
		parse_str( $query, $get );

		$servers = array( array( 'REQUEST_URI' => $path . $suffix ) );
		if ( self::canonical_path( $path ) !== $path ) {
			$servers[] = array( 'REQUEST_URI' => self::canonical_path( $path ) . $suffix );
		}
		$index = strpos( $path, '/index.php/' );
		if ( false !== $index ) {
			$servers[] = array(
				'REQUEST_URI' => $path . $suffix,
				'PATH_INFO'   => rawurldecode( substr( $path, $index + strlen( '/index.php' ) ) ),
			);
		}

		$out = array();
		foreach ( $servers as $server ) {
			$out[] = self::from_parts(
				array( 'REQUEST_METHOD' => 'GET' ) + $server,
				$get,
				array(),
				array(),
				$rest_prefix,
				self::home_path(),
				'' !== (string) get_option( 'permalink_structure' ),
				(int) get_current_blog_id(),
				isset( $parsed['scheme'] ) && 'https' === $parsed['scheme'],
				$vary_cookie_names
			);
		}
		return $out;
	}

	/**
	 * Canonical form of a URL path: every byte outside the RFC 3986 path characters (unreserved,
	 * sub-delims, `:`, `@`, `/`) is percent-encoded, and existing escapes are upper-cased.
	 *
	 * @param string $path URL path.
	 *
	 * @return string
	 */
	public static function canonical_path( string $path ): string {
		return (string) preg_replace_callback(
			'/%[0-9a-fA-F]{2}|[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/%]/',
			static function ( array $m ): string {
				return '%' === $m[0][0] ? strtoupper( $m[0] ) : sprintf( '%%%02X', ord( $m[0] ) );
			},
			$path
		);
	}

	/**
	 * Get a request header by (case-insensitive) name.
	 *
	 * @param string $name Header name, e.g. `Accept-Language`.
	 *
	 * @return string|null The value, or null when absent.
	 */
	public function header( string $name ): ?string {
		return $this->headers[ self::header_key( $name ) ] ?? null;
	}

	/**
	 * Whether the context could see a request header: it was read from the request, or the context holds every header it
	 * was given. When false, a null {@see self::header()} means unknown, not absent, and must never be taken for absent.
	 *
	 * @param string $name Header name, e.g. `X-Api-Key`.
	 *
	 * @return bool
	 */
	public function has_read_header( string $name ): bool {
		return null === $this->read_headers || in_array( self::header_key( $name ), $this->read_headers, true );
	}

	/**
	 * The key a header goes by in {@see self::$headers}: lower-case, dash separated.
	 *
	 * @param string $name Header name.
	 *
	 * @return string
	 */
	private static function header_key( string $name ): string {
		return strtolower( str_replace( '_', '-', $name ) );
	}

	/**
	 * The header keys of a list of header names: strings only, normalised, unique and sorted.
	 *
	 * @param array<mixed>|null $names Header names, or null.
	 *
	 * @return string[]|null Null for null.
	 *
	 * @phpstan-return list<string>|null
	 */
	private static function header_keys( ?array $names ): ?array {
		if ( null === $names ) {
			return null;
		}
		$keys = array();
		foreach ( $names as $name ) {
			if ( is_string( $name ) ) {
				$keys[ self::header_key( $name ) ] = true;
			}
		}
		$keys = array_map( 'strval', array_keys( $keys ) );
		sort( $keys, SORT_STRING );
		return $keys;
	}

	/**
	 * Whether any cookie name starts with one of the prefixes.
	 *
	 * @param string ...$prefixes Cookie name prefixes (empty prefixes are ignored).
	 *
	 * @return bool
	 */
	public function has_cookie_with_prefix( string ...$prefixes ): bool {
		foreach ( $prefixes as $prefix ) {
			if ( '' === $prefix ) {
				continue;
			}
			foreach ( $this->cookie_names as $name ) {
				if ( str_starts_with( $name, $prefix ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Path component of the home URL.
	 *
	 * @return string
	 */
	private static function home_path(): string {
		$path = wp_parse_url( home_url(), PHP_URL_PATH );
		return is_string( $path ) ? $path : '';
	}

	/**
	 * Split a request URI into path and query string (dropping scheme/host and fragment).
	 *
	 * @param string $uri The request URI.
	 *
	 * @return array{path:string,query:string}
	 */
	private static function split_uri( string $uri ): array {
		$hash = strpos( $uri, '#' );
		if ( false !== $hash ) {
			$uri = substr( $uri, 0, $hash );
		}
		$uri = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://[^/?]*#i', '', $uri );

		$query = '';
		$mark  = strpos( $uri, '?' );
		if ( false !== $mark ) {
			$query = substr( $uri, $mark + 1 );
			$uri   = substr( $uri, 0, $mark );
		}
		return array(
			'path'  => $uri,
			'query' => $query,
		);
	}

	/**
	 * Normalise a route like `rest_api_loaded()`: no trailing slash, `/` for the index.
	 *
	 * A route core would not serve as given (empty, without a leading slash, or starting with `//`) is
	 * rejected instead of being rewritten into another route.
	 *
	 * @param string $route Raw route.
	 *
	 * @return string|null The route, or null when it is not a route core serves.
	 */
	private static function normalise_route( string $route ): ?string {
		if ( ! str_starts_with( $route, '/' ) || str_starts_with( $route, '//' ) ) {
			return null;
		}
		$route = untrailingslashit( $route );
		return '' === $route ? '/' : $route;
	}

	/**
	 * Resolve the route from the URL path, mirroring `WP::parse_request()`.
	 *
	 * Core dispatches on the raw (still percent-encoded) request path, or on `PATH_INFO` (which the web
	 * server already decoded, with `%` re-escaped to `%25`) when that is set, e.g. for
	 * `/index.php/wp-json/...` requests.
	 *
	 * @param string $path        Request path.
	 * @param string $rest_prefix REST URL prefix.
	 * @param string $home_path   Home URL path.
	 * @param string $path_info   The `PATH_INFO` server variable, or empty.
	 *
	 * @return string|null The route, or null when the path is not a REST path.
	 */
	private static function route_from_path( string $path, string $rest_prefix, string $home_path, string $path_info = '' ): ?string {
		$prefix = trim( $rest_prefix, '/' );
		if ( '' === $prefix ) {
			return null;
		}

		$path = trim( $path, '/' );
		$home = trim( $home_path, '/' );
		if ( '' !== $home ) {
			if ( 0 === strcasecmp( $path, $home ) ) {
				$path = '';
			} elseif ( 0 === strncasecmp( $path, $home . '/', strlen( $home ) + 1 ) ) {
				$path = substr( $path, strlen( $home ) + 1 );
			} else {
				return null;
			}
		}

		$info = self::path_info_path( $path_info, $home );
		if ( false === $info ) {
			return null; // PATH_INFO that core does not use for routing: fail safe, not cacheable.
		}
		if ( null !== $info ) {
			$path = $info;
		} elseif ( 'index.php' === $path ) {
			$path = '';
		} elseif ( str_starts_with( $path, 'index.php/' ) ) {
			$path = substr( $path, strlen( 'index.php/' ) );
		}

		if ( $path === $prefix ) {
			return '/';
		}
		if ( str_starts_with( $path, $prefix . '/' ) ) {
			// WP::parse_request() urlencodes the matched path into the `rest_route` query var and parse_str()
			// decodes it once again, so the route is the path as matched: `a+b`, `a%2Bb` and `a%20b` are
			// three different routes, exactly as core sees them.
			// A `//` right after the prefix stays `//` and is rejected, never rewritten into another route.
			return self::normalise_route( '/' . substr( $path, strlen( $prefix ) + 1 ) );
		}
		return null;
	}

	/**
	 * The path core matches for a `PATH_INFO` request, mirroring `WP::parse_request()`.
	 *
	 * @param string $path_info The `PATH_INFO` server variable.
	 * @param string $home      Home path without slashes.
	 *
	 * @return string|false|null The path without slashes around it; null when there is no `PATH_INFO`
	 *                           (core uses the request URI); false when core ignores a non-empty one.
	 */
	private static function path_info_path( string $path_info, string $home ): string|false|null {
		$info = str_replace( '%', '%25', explode( '?', $path_info )[0] );
		$info = trim( $info, '/' );
		if ( '' !== $home ) {
			$info = trim( (string) preg_replace( '|^' . preg_quote( $home, '|' ) . '|i', '', $info ), '/' );
		}
		if ( '' === $info ) {
			return null;
		}
		// Core's own check, with the unescaped `index.php` of WP_Rewrite::$index.
		return 1 === preg_match( '|^.*index.php$|', $info ) ? false : $info;
	}

	/**
	 * Extract lower-case, dash separated headers from server variables.
	 *
	 * @param array<mixed> $server Server variables.
	 *
	 * @return array<string,string>
	 */
	private static function headers_from_server( array $server ): array {
		$headers = array();
		foreach ( $server as $key => $value ) {
			if ( ! is_string( $key ) || ! is_string( $value ) ) {
				continue;
			}
			if ( str_starts_with( $key, 'HTTP_' ) ) {
				$headers[ strtolower( str_replace( '_', '-', substr( $key, 5 ) ) ) ] = $value;
			} elseif ( 'CONTENT_TYPE' === $key ) {
				$headers['content-type'] = $value;
			}
		}
		if ( ! isset( $headers['authorization'] ) && isset( $server['REDIRECT_HTTP_AUTHORIZATION'] ) && is_string( $server['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$headers['authorization'] = $server['REDIRECT_HTTP_AUTHORIZATION'];
		}
		ksort( $headers, SORT_STRING );
		return $headers;
	}

	/**
	 * Names of the cookies.
	 *
	 * @param array<mixed> $cookie Cookies.
	 *
	 * @return string[]
	 *
	 * @phpstan-return list<string>
	 */
	private static function cookie_names( array $cookie ): array {
		return array_map( 'strval', array_keys( $cookie ) );
	}

	/**
	 * Values of the requested cookies, keyed by every requested name.
	 *
	 * @param array<mixed> $cookie            Cookies.
	 * @param array<mixed> $vary_cookie_names Requested cookie names; non-string entries are ignored, duplicates collapse.
	 *
	 * @return array<string,string|null> The value; `''` when the cookie is absent; null when it is not a string.
	 */
	private static function vary_cookies( array $cookie, array $vary_cookie_names ): array {
		$out = array();
		foreach ( $vary_cookie_names as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			if ( ! array_key_exists( $name, $cookie ) ) {
				$out[ $name ] = '';
			} else {
				$out[ $name ] = is_string( $cookie[ $name ] ) ? $cookie[ $name ] : null;
			}
		}
		return $out;
	}

	/**
	 * Read, classify and decode the request body.
	 *
	 * The order is: `multipart/*` (never read); a numeric `CONTENT_LENGTH` over the cap (never read); a read of at
	 * most cap + 1 bytes (more than the cap is too large); JSON; form; no body and no Content-Type; anything else is
	 * unsupported.
	 *
	 * @param array<string,string> $headers Normalised headers.
	 * @param array<mixed>         $server  Server variables.
	 * @param string|\Closure|null $body    The body or its reader.
	 * @param bool                 $read    Set to whether the body read was not empty (false when it was not read).
	 *
	 * @return array{0:string|null,1:string|null,2:array<int|string,mixed>} Content class, body error, top-level parameters.
	 *
	 * @phpstan-param string|(\Closure(int):(string|null))|null $body
	 */
	private static function read_body( array $headers, array $server, string|\Closure|null $body, bool &$read ): array {
		$media = strtolower( trim( explode( ';', $headers['content-type'] ?? '', 2 )[0] ) );

		if ( str_starts_with( $media, 'multipart/' ) ) {
			return array( null, self::ERROR_MULTIPART, array() );
		}
		if ( self::content_length( $server ) > self::MAX_BODY_BYTES ) {
			return array( null, self::ERROR_TOO_LARGE, array() );
		}

		$raw = $body instanceof \Closure ? $body( self::MAX_BODY_BYTES + 1 ) : $body;
		$raw = is_string( $raw ) ? substr( $raw, 0, self::MAX_BODY_BYTES + 1 ) : '';

		$read = '' !== $raw;
		if ( strlen( $raw ) > self::MAX_BODY_BYTES ) {
			return array( null, self::ERROR_TOO_LARGE, array() );
		}

		if ( 'application/json' === $media || 1 === preg_match( '#^application/[^/\s]+\+json$#', $media ) ) {
			return self::json_body( $raw );
		}
		if ( 'application/x-www-form-urlencoded' === $media ) {
			return self::form_body( $raw );
		}
		if ( '' === $media && '' === $raw ) {
			return array( self::CLASS_NONE, null, array() );
		}
		return array( null, self::ERROR_UNSUPPORTED, array() );
	}

	/**
	 * Whether the request announces a body: a `Content-Length` that is set, non-empty and not all zeros (a value that is
	 * not a number counts as a body), or a `Transfer-Encoding` header.
	 *
	 * @param array<mixed>         $server  Server variables.
	 * @param array<string,string> $headers Normalised headers.
	 *
	 * @return bool
	 */
	private static function declares_body( array $server, array $headers ): bool {
		$length = $server['CONTENT_LENGTH'] ?? null;
		if ( is_int( $length ) ) {
			$length = (string) $length;
		}
		if ( is_string( $length ) && '' !== ltrim( $length, '0' ) ) {
			return true;
		}
		return isset( $headers['transfer-encoding'] );
	}

	/**
	 * Whether the body has at least one byte; reads 1 byte at most.
	 *
	 * @param string|\Closure|null $body The body or its reader.
	 *
	 * @return bool
	 *
	 * @phpstan-param string|(\Closure(int):(string|null))|null $body
	 */
	private static function has_first_byte( string|\Closure|null $body ): bool {
		$raw = $body instanceof \Closure ? $body( 1 ) : $body;
		return is_string( $raw ) && '' !== substr( $raw, 0, 1 );
	}

	/**
	 * The request host: `HTTP_HOST` trimmed and lower-cased, without the default port of the scheme (`:80` for http,
	 * `:443` for https); any other port stays.
	 *
	 * @param array<mixed> $server Server variables.
	 * @param bool         $ssl    Whether the request uses HTTPS.
	 *
	 * @return string|null Null when `HTTP_HOST` is missing, not a string or empty.
	 */
	private static function host( array $server, bool $ssl ): ?string {
		$host = $server['HTTP_HOST'] ?? null;
		if ( ! is_string( $host ) ) {
			return null;
		}
		$host = strtolower( trim( $host ) );
		$port = $ssl ? ':443' : ':80';
		if ( str_ends_with( $host, $port ) ) {
			$host = substr( $host, 0, -strlen( $port ) );
		}
		return '' === $host ? null : $host;
	}

	/**
	 * The declared body length when it is a plain number, else 0.
	 *
	 * @param array<mixed> $server Server variables.
	 *
	 * @return int
	 */
	private static function content_length( array $server ): int {
		$length = $server['CONTENT_LENGTH'] ?? null;
		if ( is_int( $length ) ) {
			return $length;
		}
		return is_string( $length ) && ctype_digit( $length ) ? (int) $length : 0;
	}

	/**
	 * Decode a JSON body: an object (or nothing) at the depth cap.
	 *
	 * Numbers decode as core's `WP_REST_Request::parse_json_params()` hands them to the handler: an integer too big for
	 * PHP becomes a float, never a string, so `{"id":12345678901234567890}` and `{"id":"12345678901234567890"}` keep
	 * apart. A body with a value that has no canonical encoding (a number that overflows to INF) is invalid, so it
	 * never shares a key with another body.
	 *
	 * @param string $raw The body.
	 *
	 * @return array{0:string|null,1:string|null,2:array<int|string,mixed>}
	 */
	private static function json_body( string $raw ): array {
		if ( '' === $raw ) {
			return array( self::CLASS_JSON, null, array() );
		}
		$decoded = json_decode( $raw, false, self::MAX_BODY_DEPTH );
		if ( ! $decoded instanceof \stdClass ) {
			return array( self::CLASS_JSON, self::ERROR_INVALID, array() );
		}
		$sorted = self::sort_json_object( $decoded );
		if ( false === self::encode_body( $sorted ) ) {
			return array( self::CLASS_JSON, self::ERROR_INVALID, array() );
		}
		return array( self::CLASS_JSON, null, get_object_vars( $sorted ) );
	}

	/**
	 * Build the parameters of a URL-encoded form body.
	 *
	 * Every method, POST included, is parsed from the raw body, never from `$_POST`.
	 *
	 * @param string $raw The body.
	 *
	 * @return array{0:string|null,1:string|null,2:array<int|string,mixed>}
	 */
	private static function form_body( string $raw ): array {
		$source = array();
		parse_str( $raw, $source );
		$params = self::normalise_params( $source );
		if ( ! self::is_clean( $params ) ) {
			return array( self::CLASS_FORM, self::ERROR_INVALID, array() );
		}
		return array( self::CLASS_FORM, null, $params );
	}

	/**
	 * Rebuild a decoded JSON value with every object's keys sorted (`SORT_STRING`); lists keep their order.
	 *
	 * @param mixed $value Decoded value (objects as `stdClass`).
	 *
	 * @return mixed
	 */
	private static function sort_json_value( mixed $value ): mixed {
		if ( $value instanceof \stdClass ) {
			return self::sort_json_object( $value );
		}
		if ( is_array( $value ) ) {
			return array_map( static fn( mixed $item ): mixed => self::sort_json_value( $item ), $value );
		}
		return $value;
	}

	/**
	 * A copy of a decoded JSON object with its keys sorted (`SORT_STRING`), recursively.
	 *
	 * @param \stdClass $node Decoded object.
	 *
	 * @return \stdClass
	 */
	private static function sort_json_object( \stdClass $node ): \stdClass {
		$vars = get_object_vars( $node );
		ksort( $vars, SORT_STRING );
		$out = new \stdClass();
		foreach ( $vars as $key => $value ) {
			$name       = (string) $key;
			$out->$name = self::sort_json_value( $value );
		}
		return $out;
	}

	/**
	 * Whether every key and value is valid UTF-8 and no key starts with a NUL byte (which has no property name).
	 *
	 * @param array<mixed> $values Parameters.
	 *
	 * @return bool
	 */
	private static function is_clean( array $values ): bool {
		foreach ( $values as $key => $value ) {
			if ( is_string( $key ) && ( str_starts_with( $key, "\0" ) || 1 !== preg_match( '//u', $key ) ) ) {
				return false;
			}
			if ( is_string( $value ) && 1 !== preg_match( '//u', $value ) ) {
				return false;
			}
			if ( is_array( $value ) && ! self::is_clean( $value ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a decoded body value counts as present: not null, `false`, `''`, `[]` or `{}`.
	 *
	 * @param mixed $value Decoded value.
	 *
	 * @return bool
	 */
	private static function has_value( mixed $value ): bool {
		if ( null === $value || false === $value || '' === $value || array() === $value ) {
			return false;
		}
		return ! ( $value instanceof \stdClass && array() === get_object_vars( $value ) );
	}

	/**
	 * Credential signals present on the request.
	 *
	 * @param array<mixed>            $server      Server variables.
	 * @param array<string,string>    $headers     Normalised headers.
	 * @param array<mixed>            $get         Query parameters.
	 * @param array<mixed>            $post        Body parameters.
	 * @param array<int|string,mixed> $body_params Top-level parameters of the decoded request body.
	 *
	 * @return string[]
	 *
	 * @phpstan-return list<string>
	 */
	private static function signals( array $server, array $headers, array $get, array $post, array $body_params ): array {
		$signals = array();
		if ( isset( $headers['authorization'] ) && '' !== $headers['authorization'] ) {
			$signals[] = self::SIGNAL_AUTHORIZATION;
		}
		if ( isset( $server['PHP_AUTH_USER'] ) && is_string( $server['PHP_AUTH_USER'] ) && '' !== $server['PHP_AUTH_USER'] ) {
			$signals[] = self::SIGNAL_PHP_AUTH_USER;
		}
		if ( isset( $headers['x-wp-nonce'] ) && '' !== $headers['x-wp-nonce'] ) {
			$signals[] = self::SIGNAL_X_WP_NONCE;
		}
		$params = array(
			'_wpnonce' => self::SIGNAL_WPNONCE_PARAM,
			'password' => self::SIGNAL_PASSWORD_PARAM,
		);
		foreach ( $params as $param => $signal ) {
			$found = isset( $body_params[ $param ] ) && self::has_value( $body_params[ $param ] );
			foreach ( array( $get, $post ) as $source ) {
				if ( isset( $source[ $param ] ) && '' !== $source[ $param ] && array() !== $source[ $param ] ) {
					$found = true;
					break;
				}
			}
			if ( $found ) {
				$signals[] = $signal;
			}
		}
		return $signals;
	}

	/**
	 * Recursively sort parameters by name and coerce scalar values to strings.
	 *
	 * The top level and nested arrays with only string keys (`a[x]=1&a[y]=2`) are sorted by key. A nested
	 * array with an integer key is a list (`include[]=3&include[]=5`, `include[1]=5&include[0]=3`) whose
	 * order WordPress keeps (for example `orderby=include`), so its order and keys are kept as sent.
	 *
	 * @param array<mixed> $params Raw parameters.
	 * @param bool         $nested Whether this is a nested array.
	 *
	 * @return array<int|string,string|array<int|string,mixed>>
	 */
	private static function normalise_params( array $params, bool $nested = false ): array {
		$out  = array();
		$list = false;
		foreach ( $params as $key => $value ) {
			$list = $list || is_int( $key );
			if ( is_array( $value ) ) {
				$out[ $key ] = self::normalise_params( $value, true );
			} elseif ( is_scalar( $value ) ) {
				$out[ $key ] = (string) $value;
			}
		}
		if ( ! $nested || ! $list ) {
			ksort( $out, SORT_STRING );
		}
		return $out;
	}
}
