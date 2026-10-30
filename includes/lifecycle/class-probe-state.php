<?php
/**
 * What a test request saw, for the request that sent it: which plugins
 * WordPress loaded there and which theme it ran.
 *
 * The activation check reads the stored lists from the table, so that the
 * filters of a plugin just loaded in the activating request do not decide
 * whether it is on (Codex, 04.10.2026). Code that runs on every request — an
 * mu-plugin, a host's filter — can still keep a plugin from loading although
 * the table lists it, or keep the previous theme although the table names
 * the new one (Codex, 06.10.2026). Only a fresh request shows that. So a test
 * request that carries ab-mcp-state=1 together with WordPress's test key
 * prints, at shutdown, what it loaded: the plugins of
 * wp_get_active_and_valid_plugins() and, on a network, of
 * wp_get_active_network_plugins() — both lists after every filter — and
 * get_stylesheet() and get_template(). The key is checked against its
 * transient as core checks it (wp_start_scraping_edited_file_errors()); it
 * exists only while the request that stored it runs its test requests.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Probe_State
 */
class AB_MCP_Probe_State {

	/**
	 * The query argument that asks for the state. With hyphens: no option of
	 * that name exists (the uninstall test reads every 'ab_mcp_…' constant as
	 * one).
	 */
	const PARAM = 'ab-mcp-state';

	/**
	 * In a test request that asks for it, print the state at shutdown.
	 * Called while the plugin loads; does nothing in any other request.
	 */
	public static function boot() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the test key is the check, as in core.
		if ( ! isset( $_GET['wp_scrape_key'], $_GET['wp_scrape_nonce'], $_GET[ self::PARAM ] ) ) {
			return;
		}
		$key   = substr( sanitize_key( wp_unslash( (string) $_GET['wp_scrape_key'] ) ), 0, 32 );
		$nonce = (string) wp_unslash( (string) $_GET['wp_scrape_nonce'] );
		// phpcs:enable
		if ( '' === $key || '' === $nonce || get_transient( 'scrape_key_' . $key ) !== $nonce ) {
			return;
		}
		add_action(
			'shutdown',
			static function () use ( $key ) {
				echo self::block( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON between markers, for the test request only.
			},
			PHP_INT_MAX
		);
	}

	/**
	 * The state between its markers.
	 *
	 * @param string $key The test key.
	 * @return string
	 */
	public static function block( $key ) {
		try {
			$state = array(
				'plugins'    => array(),
				'network'    => array(),
				'stylesheet' => function_exists( 'get_stylesheet' ) ? (string) get_stylesheet() : '',
				'template'   => function_exists( 'get_template' ) ? (string) get_template() : '',
			);
			if ( function_exists( 'wp_get_active_and_valid_plugins' ) ) {
				foreach ( (array) wp_get_active_and_valid_plugins() as $path ) {
					$state['plugins'][] = plugin_basename( (string) $path );
				}
			}
			if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'wp_get_active_network_plugins' ) ) {
				foreach ( (array) wp_get_active_network_plugins() as $path ) {
					$state['network'][] = plugin_basename( (string) $path );
				}
			}
		} catch ( \Throwable $e ) {
			return '';
		}
		return "###### ab_mcp_state_start:$key ######" . wp_json_encode( $state ) . "###### ab_mcp_state_end:$key ######";
	}

	/**
	 * The state a test request printed, or null where it printed none.
	 *
	 * @param string $body Response body.
	 * @param string $key  The test key.
	 * @return array|null { plugins: string[], network: string[], stylesheet, template }
	 */
	public static function read( $body, $key ) {
		$start = "###### ab_mcp_state_start:$key ######";
		$end   = "###### ab_mcp_state_end:$key ######";
		$pos   = strrpos( (string) $body, $start );
		if ( false === $pos ) {
			return null;
		}
		$rest = substr( (string) $body, $pos + strlen( $start ) );
		$stop = strpos( $rest, $end );
		if ( false === $stop ) {
			return null;
		}
		$data = json_decode( substr( $rest, 0, $stop ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		return array(
			'plugins'    => array_values( array_map( 'strval', isset( $data['plugins'] ) && is_array( $data['plugins'] ) ? $data['plugins'] : array() ) ),
			'network'    => array_values( array_map( 'strval', isset( $data['network'] ) && is_array( $data['network'] ) ? $data['network'] : array() ) ),
			'stylesheet' => isset( $data['stylesheet'] ) ? (string) $data['stylesheet'] : '',
			'template'   => isset( $data['template'] ) ? (string) $data['template'] : '',
		);
	}

	/**
	 * The body without the state, so that what the target answered is judged
	 * as it would be without it: this MCP endpoint's JSON before WordPress's
	 * report, say.
	 *
	 * @param string $body Response body.
	 * @param string $key  The test key.
	 * @return string
	 */
	public static function strip( $body, $key ) {
		$q = preg_quote( (string) $key, '/' );
		return (string) preg_replace( '/###### ab_mcp_state_start:' . $q . ' ######.*?###### ab_mcp_state_end:' . $q . ' ######/s', '', (string) $body );
	}
}
