<?php
/**
 * The test version of the fetcher that gets a package from the other site of
 * a pair (Z8, Entscheid 34). Loaded by the integration bootstrap and by
 * nothing else.
 *
 * In production the live site fetches from its partner only through
 * AB_MCP_Outbound::get(), which refuses every host that resolves to a local
 * or private address (class-outbound.php, send()). The two integration
 * sites both live on 127.0.0.1, so the production way cannot reach site B
 * from site A — TwoSitesSmokeTest shows the refusal. This class is the way
 * the integration cases take instead: the same signature as
 * AB_MCP_Outbound::get(), an answer of the same shape (array or WP_Error),
 * no redirect followed, and only the origins of the integration sites asked
 * — any other address is refused before a request is made, so a test cannot
 * reach the network through it.
 *
 * The product code knows nothing of it: no filter, no switch, no
 * class_exists() in the shipped tree (Entscheid 34). A test hands an
 * instance to the code under test the way that code takes its fetcher
 * (the branch heikel/z8-paket builds AB_MCP_Sync_Fetcher and says how);
 * TestFetcherIsolationTest proves the plugin loads without this file, and
 * ShippedCodeKnowsNoTestFetcherTest that no shipped file names it.
 *
 * @package AlphaBridge_MCP
 */

/**
 * Class AB_MCP_Test_Sync_Fetcher
 */
final class AB_MCP_Test_Sync_Fetcher {

	/** @var string[] The origins (scheme://host:port) this fetcher asks. */
	private $origins;

	/** @var array<int,array{url:string,args:array}> Every call, in order, for the assertions of a test. */
	public $calls = array();

	/**
	 * @param string[] $origins Addresses of the sites it may ask; only scheme,
	 *                          host and port count.
	 */
	public function __construct( array $origins ) {
		$this->origins = array_values( array_filter( array_map( array( __CLASS__, 'origin' ), $origins ) ) );
		if ( array() === $this->origins ) {
			throw new InvalidArgumentException( 'The test fetcher needs the address of at least one integration site.' );
		}
	}

	/**
	 * A fetcher for the integration sites of the state.
	 *
	 * @return self
	 */
	public static function for_sites() {
		return new self( array( AB_MCP_IT_Sites::url( 'a' ), AB_MCP_IT_Sites::url( 'b' ) ) );
	}

	/**
	 * GET an address of an integration site, as AB_MCP_Outbound::get() would
	 * where the partner is public.
	 *
	 * @param string $url  Address.
	 * @param array  $args Request arguments of WordPress; 'redirection' is
	 *                     ignored, nothing is followed.
	 * @return array|WP_Error
	 */
	public function get( $url, array $args = array() ) {
		$this->calls[] = array(
			'url'  => (string) $url,
			'args' => $args,
		);
		if ( ! in_array( self::origin( (string) $url ), $this->origins, true ) ) {
			return new WP_Error( 'ab_mcp_test_fetcher_foreign', 'The test fetcher asks only the integration sites.', array( 'url' => (string) $url ) );
		}
		$args['redirection']        = 0;
		$args['reject_unsafe_urls'] = false;
		return wp_remote_get( (string) $url, $args );
	}

	/**
	 * Scheme, host and port of an address, or '' when it has no host.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function origin( $url ) {
		$parts = parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . strtolower( $parts['host'] ) . ':' . $port;
	}
}
