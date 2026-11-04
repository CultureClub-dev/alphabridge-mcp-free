<?php
/**
 * How the live site asks its staging site (Z8, decisions 2, 6 and 34 of the
 * plan of 01.11.2026): only over https, only through AB_MCP_Outbound::get()
 * (every address of the host public, the connection tied to them, no proxy,
 * nothing without cURL), and no redirect followed, whatever a caller asks
 * for: an address the staging site redirects to is not the address a
 * person confirmed.
 *
 * The code that asks takes a fetcher as an object with this get() and uses
 * this class when it is handed none. The integration suite hands its own
 * version over the same way, from its bootstrap only; the shipped code knows
 * no other fetcher (no filter, no switch, ShippedCodeKnowsNoTestFetcherTest).
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-outbound.php';

/**
 * Class AB_MCP_Sync_Fetcher
 */
final class AB_MCP_Sync_Fetcher {

	/**
	 * GET an address of the staging site.
	 *
	 * @param string $url  Address; https only.
	 * @param array  $args Request arguments of WordPress; 'redirection' is
	 *                     always 0.
	 * @return array|WP_Error The answer, whatever its status (a 3xx is the
	 *                        answer, not followed), or why it was not asked.
	 */
	public function get( $url, array $args = array() ) {
		$url = (string) $url;
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return new WP_Error( 'ab_mcp_sync_not_https', 'The staging site is asked only over https, and this address is not: give the staging site an https:// address on the page AlphaBridge → Staging of both sites, or use the pairing code instead.', array( 'url' => $url ) );
		}
		$args['redirection'] = 0;
		return AB_MCP_Outbound::get( $url, $args );
	}
}
