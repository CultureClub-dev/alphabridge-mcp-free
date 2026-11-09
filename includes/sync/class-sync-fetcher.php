<?php
/**
 * How the live site asks its staging site (Z8, decisions 2, 6 and 34 of the
 * plan of 01.11.2026): only over https, only at the address of the staging
 * site stored on this production site (AB_MCP_Sync_Anchor::partner(), in the
 * form AB_MCP_Sync_Key::site_form() compares, with no segment . or .. in
 * the path, also not as %2e: refused, not resolved), only through
 * AB_MCP_Outbound::get() (every address of the host public, the connection
 * tied to them, no proxy, nothing without cURL), and no redirect followed,
 * whatever a caller asks for: an address the staging site redirects to is
 * not the address a person confirmed.
 *
 * WordPress asks an address of another host only on the ports 80, 443 and
 * 8080 (wp_http_validate_url()). A staging site at
 * https://staging.example.com:8443 is reached all the same: for this one
 * request, and only for the very address asked, its port is allowed once
 * (AB_MCP_Outbound::get_partner()).
 *
 * The code that asks takes a fetcher as an object with this get() and uses
 * this class when it is handed none. The integration suite hands its own
 * version over the same way, from its bootstrap only; the shipped code knows
 * no other fetcher (no filter, no switch, ShippedCodeKnowsNoTestFetcherTest).
 *
 * Refusals and errors are gettext texts in the language of the site, each
 * naming the way; codes, states, kinds and parameters stay as the tools
 * name them.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-outbound.php';
require_once __DIR__ . '/class-sync-key.php';
require_once __DIR__ . '/class-sync-anchor.php';

/**
 * Class AB_MCP_Sync_Fetcher
 */
final class AB_MCP_Sync_Fetcher {

	/**
	 * GET an address of the staging site.
	 *
	 * @param string $url  Address; https only, under the stored address of
	 *                     the staging site, no segment . or .. in its path.
	 * @param array  $args Request arguments of WordPress; 'redirection' is
	 *                     always 0.
	 * @return array|WP_Error The answer, whatever its status (a 3xx is the
	 *                        answer, not followed), or why it was not asked.
	 */
	public function get( $url, array $args = array() ) {
		$url = (string) $url;
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return new WP_Error( 'ab_mcp_sync_not_https', __( 'The staging site is asked only over https, and this address is not: give the staging site an https:// address on the page AlphaBridge → Staging of both sites, or use the pairing code instead.', 'alphabridge-mcp' ), array( 'url' => $url ) );
		}
		// No stored staging site (a refusal of partner()) is no address, and
		// '' . '/' begins no https:// address.
		$base = AB_MCP_Sync_Key::site_form( AB_MCP_Sync_Anchor::partner() );
		if ( 0 !== strpos( $url, $base . '/' ) ) {
			return new WP_Error( 'ab_mcp_sync_not_partner', __( 'This site asks only the staging site stored on its page AlphaBridge → Staging, at that address, and this address is another one. Check the address of the staging site there.', 'alphabridge-mcp' ), array( 'url' => $url ) );
		}
		// No segment . or .. in the path, also not written as %2e in either
		// case or mixed: Requests resolves them, and /blog/../outside leaves
		// the stored address. Refused, not resolved. Read is the address up
		// to ? (the query is no path; a # stays in, a request sends no
		// fragment), host and port included, which have no such segment
		// unless the stored host is one, and then it is refused alike.
		$path = substr( $url, 0, strcspn( $url, '?' ) );
		if ( preg_match( '~/(?:\.|%2e){1,2}(?:/|\z)~i', $path ) ) {
			return new WP_Error( 'ab_mcp_sync_dot_segment', __( 'This site asks the staging site only at an address whose path has no segment . or .. (nor one written as %2e), because such a segment leads out of the stored address, and this address has one. Check the address of the staging site on the page AlphaBridge → Staging and store it without such a segment.', 'alphabridge-mcp' ), array( 'url' => $url ) );
		}
		$args['redirection'] = 0;
		return AB_MCP_Outbound::get_partner( $url, $args );
	}
}
