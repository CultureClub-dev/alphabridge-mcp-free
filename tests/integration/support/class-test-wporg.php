<?php
/**
 * The stand-in for downloads.wordpress.org and api.wordpress.org (Z8b
 * branch 1, point 3; plan E21, after the pattern of the test fetcher, Z8
 * plan Entscheid 34). Loaded by the integration bootstrap and by the child
 * runners where a plan requires it; the shipped code knows nothing of it
 * (ShippedCodeKnowsNoTestFetcherTest scans for AB_MCP_Test_).
 *
 * It answers from the test plugins and themes (fixtures/code/katalog.php),
 * never from the network:
 * - https://downloads.wordpress.org/plugin/<slug>.<version>.zip and
 *   /theme/<slug>.<version>.zip: the ZIP of that version, packed as
 *   wordpress.org packs (one folder named after the slug,
 *   AB_MCP_IT_Code::zip()); /plugin/<slug>.zip: the newest version;
 * - https://api.wordpress.org/plugins/info/1.2/?action=plugin_information
 *   and /themes/info/1.2/?action=theme_information with the slug as
 *   request[slug] or slug: name, slug, version (the newest), download_link
 *   and versions (each version and its link), as JSON;
 * - anything unknown on these hosts: 404, as wordpress.org answers;
 * - any other host or scheme: a WP_Error before anything is asked, so a
 *   test cannot reach the network through it.
 *
 * Which versions it offers: by default every version of every folder plugin
 * and theme of the list, under the version number of its header; a version
 * number two versions share (ab-it-migration 1.0.0) goes to the first one
 * listed. A test may name its own offer.
 *
 * Two ways to hand it over, as the code under test takes its fetcher:
 * - an object with get( $url, $args ), the signature of
 *   AB_MCP_Outbound::get() and AB_MCP_Test_Sync_Fetcher::get(), for code
 *   that takes a fetcher (the seam of branch 4);
 * - hook(): answers WordPress's own requests to these hosts through the
 *   filter pre_http_request (download_url(), plugins_api(), the upgrader),
 *   in the test process or, called from a plan, in a child process.
 *   unhook() takes it off.
 * Every request is recorded in $calls.
 *
 * @package AlphaBridge_MCP
 */

require_once __DIR__ . '/class-it-code.php';

/**
 * Class AB_MCP_Test_Wporg
 */
final class AB_MCP_Test_Wporg {

	/** The hosts it stands in for. */
	const HOSTS = array( 'downloads.wordpress.org', 'api.wordpress.org' );

	/** @var array<string,array<string,array<string,string>>> type => slug => version => version of the fixtures. */
	private $offer;

	/** @var array<int,array{url:string,args:array}> Every request, in order. */
	public $calls = array();

	/** @var callable|null The filter of hook(). */
	private $hooked = null;

	/**
	 * @param array|null $offer type (plugin, theme) => slug => version number
	 *                          => version of the fixtures; null for all.
	 */
	public function __construct( $offer = null ) {
		$this->offer = null === $offer ? self::everything() : $offer;
		foreach ( $this->offer as $type => $units ) {
			foreach ( $units as $slug => $versions ) {
				foreach ( $versions as $fassung ) {
					AB_MCP_IT_Code::source( $type, $slug, $fassung );
				}
			}
		}
	}

	/**
	 * Every version of every folder plugin and theme, by its version number.
	 *
	 * @return array
	 */
	public static function everything() {
		$offer   = array();
		$katalog = AB_MCP_IT_Code::katalog();
		foreach ( array( 'plugin' => 'plugins', 'theme' => 'themes' ) as $type => $list ) {
			foreach ( $katalog[ $list ] as $slug => $unit ) {
				if ( ! empty( $unit['single'] ) ) {
					continue;
				}
				foreach ( array_keys( $unit['fassungen'] ) as $fassung ) {
					$version = self::version_of( $type, $slug, (string) $fassung );
					if ( ! isset( $offer[ $type ][ $slug ][ $version ] ) ) {
						$offer[ $type ][ $slug ][ $version ] = (string) $fassung;
					}
				}
			}
		}
		return $offer;
	}

	/**
	 * The version number in the header of a version of the fixtures.
	 *
	 * @param string $type    plugin or theme.
	 * @param string $slug    Slug.
	 * @param string $fassung Version of the fixtures.
	 * @return string
	 */
	public static function version_of( $type, $slug, $fassung ) {
		$source = AB_MCP_IT_Code::source( $type, $slug, $fassung );
		$file   = 'theme' === $type ? $source . '/style.css' : $source . '/' . basename( AB_MCP_IT_Code::unit( 'plugin', $slug )['main'][0] );
		if ( ! preg_match( '/^[ \t\/*#@]*Version:\s*(\S+)/mi', (string) file_get_contents( $file ), $m ) ) {
			throw new RuntimeException( "No version in the header of $file." );
		}
		return $m[1];
	}

	/**
	 * GET an address of wordpress.org, answered from the fixtures.
	 *
	 * @param string $url  Address.
	 * @param array  $args Request arguments of WordPress; with 'stream' the
	 *                     body goes to 'filename', as WordPress does.
	 * @return array|WP_Error
	 */
	public function get( $url, array $args = array() ) {
		$this->calls[] = array(
			'url'  => (string) $url,
			'args' => $args,
		);
		$parts = parse_url( (string) $url );
		if ( ! is_array( $parts ) || 'https' !== ( isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '' ) || ! in_array( isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '', self::HOSTS, true ) || isset( $parts['port'] ) || isset( $parts['user'] ) ) {
			return new WP_Error( 'ab_mcp_test_wporg_foreign', 'The stand-in for wordpress.org answers only https://downloads.wordpress.org and https://api.wordpress.org.', array( 'url' => (string) $url ) );
		}
		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $query );
		if ( 'downloads.wordpress.org' === strtolower( $parts['host'] ) ) {
			if ( preg_match( '#^/(plugin|theme)/([a-z0-9-]+?)(?:\.([0-9][0-9a-z.-]*))?\.zip$#', $path, $m ) && isset( $this->offer[ $m[1] ][ $m[2] ] ) ) {
				$version = isset( $m[3] ) && '' !== $m[3] ? $m[3] : self::newest( array_keys( $this->offer[ $m[1] ][ $m[2] ] ) );
				if ( isset( $this->offer[ $m[1] ][ $m[2] ][ $version ] ) ) {
					return self::answer( 200, (string) file_get_contents( AB_MCP_IT_Code::zip( $m[1], $m[2], $this->offer[ $m[1] ][ $m[2] ][ $version ] ) ), 'application/zip', $args );
				}
			}
			return self::answer( 404, 'Not Found', 'text/plain', $args );
		}
		if ( preg_match( '#^/(plugins|themes)/info/1\.[0-2]/?$#', $path, $m ) ) {
			$type   = 'plugins' === $m[1] ? 'plugin' : 'theme';
			$action = 'plugin' === $type ? 'plugin_information' : 'theme_information';
			$slug   = isset( $query['request']['slug'] ) ? (string) $query['request']['slug'] : ( isset( $query['slug'] ) ? (string) $query['slug'] : '' );
			if ( isset( $query['action'] ) && $action === $query['action'] && isset( $this->offer[ $type ][ $slug ] ) ) {
				$versions = array();
				foreach ( array_keys( $this->offer[ $type ][ $slug ] ) as $version ) {
					$versions[ (string) $version ] = 'https://downloads.wordpress.org/' . $type . '/' . $slug . '.' . $version . '.zip';
				}
				$newest = self::newest( array_keys( $versions ) );
				return self::answer(
					200,
					(string) json_encode(
						array(
							'name'          => $slug,
							'slug'          => $slug,
							'version'       => $newest,
							'download_link' => $versions[ $newest ],
							'versions'      => $versions,
						),
						JSON_UNESCAPED_SLASHES
					),
					'application/json',
					$args
				);
			}
			return self::answer( 404, (string) json_encode( array( 'error' => 'plugin' === $type ? 'Plugin not found.' : 'Theme not found.' ) ), 'application/json', $args );
		}
		return self::answer( 404, 'Not Found', 'text/plain', $args );
	}

	/**
	 * Answer WordPress's own requests to wordpress.org (filter
	 * pre_http_request) until unhook(); other requests pass.
	 *
	 * @return self
	 */
	public function hook() {
		if ( null === $this->hooked ) {
			$this->hooked = function ( $pre, $args, $url ) {
				$host = strtolower( (string) parse_url( (string) $url, PHP_URL_HOST ) );
				return in_array( $host, self::HOSTS, true ) ? $this->get( (string) $url, (array) $args ) : $pre;
			};
			add_filter( 'pre_http_request', $this->hooked, 10, 3 );
		}
		return $this;
	}

	/**
	 * Take the filter of hook() off.
	 */
	public function unhook() {
		if ( null !== $this->hooked ) {
			remove_filter( 'pre_http_request', $this->hooked, 10 );
			$this->hooked = null;
		}
	}

	/**
	 * hook() in a child process, called from a plan of AB_MCP_IT_Child
	 * ({ "call": "AB_MCP_Test_Wporg::hook_all" }): every version offered.
	 *
	 * @return bool
	 */
	public static function hook_all() {
		$GLOBALS['ab_it_wporg'] = ( new self() )->hook();
		return true;
	}

	/**
	 * The newest of some version numbers.
	 *
	 * @param string[] $versions Version numbers.
	 * @return string
	 */
	private static function newest( array $versions ) {
		usort( $versions, 'version_compare' );
		return (string) end( $versions );
	}

	/**
	 * An answer of the shape WordPress gives (wp_remote_retrieve_*()).
	 *
	 * @param int    $code Status.
	 * @param string $body Body.
	 * @param string $type Content type.
	 * @param array  $args Request arguments ('stream', 'filename').
	 * @return array|WP_Error
	 */
	private static function answer( $code, $body, $type, array $args ) {
		$filename = null;
		if ( ! empty( $args['stream'] ) ) {
			$filename = isset( $args['filename'] ) && '' !== (string) $args['filename'] ? (string) $args['filename'] : null;
			if ( null === $filename || false === file_put_contents( $filename, $body ) ) {
				return new WP_Error( 'http_request_failed', 'The stand-in for wordpress.org could not write the stream to ' . var_export( $filename, true ) . '.' );
			}
			$body = '';
		}
		$headers = array(
			'content-type'   => $type,
			'content-length' => (string) ( null === $filename ? strlen( $body ) : filesize( $filename ) ),
		);
		return array(
			'headers'  => class_exists( 'WpOrg\Requests\Utility\CaseInsensitiveDictionary' ) ? new WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ) : $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => 200 === $code ? 'OK' : 'Not Found',
			),
			'cookies'  => array(),
			'filename' => $filename,
		);
	}
}
