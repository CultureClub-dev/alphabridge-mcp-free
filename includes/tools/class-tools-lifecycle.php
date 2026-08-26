<?php
/**
 * Update-status tool: report available core, plugin and theme updates from
 * WordPress's own cached update status. Read-only; performs no remote request
 * itself and loads no admin-only core files (reads the update transients that
 * WordPress refreshes on its own schedule).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';

/**
 * Class AB_MCP_Tools_Lifecycle
 */
class AB_MCP_Tools_Lifecycle extends AB_MCP_Tools_Base {

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {

		$r->register(
			'wp_get_update_status',
			array(
				'description' => 'Report available core, plugin and theme updates as of WordPress\'s own last scheduled check (read-only, cached data; triggers no remote request).',
				'capability'  => 'update_core',
				'callback'    => array( __CLASS__, 'check_updates' ),
			)
		);

	}

	/**
	 * Read the cached core update transients. Deliberately triggers no
	 * refresh of that data: the core refresh functions would transmit the
	 * site's plugin/theme list, versions and URL to api.wordpress.org on
	 * our initiative. Core refreshes these transients on its own schedule
	 * (twice daily); we only report that cached state.
	 *
	 * @return array
	 */
	public static function check_updates() {
		$plugin_tr = get_site_transient( 'update_plugins' );
		$theme_tr  = get_site_transient( 'update_themes' );
		$core_tr   = get_site_transient( 'update_core' );

		$plugin_list = array();
		if ( is_object( $plugin_tr ) && ! empty( $plugin_tr->response ) ) {
			foreach ( (array) $plugin_tr->response as $file => $data ) {
				$path          = WP_PLUGIN_DIR . '/' . $file;
				$headers       = file_exists( $path ) ? get_file_data( $path, array( 'Name' => 'Plugin Name' ) ) : array( 'Name' => '' );
				$plugin_list[] = array(
					'plugin' => $file,
					'name'   => '' !== $headers['Name'] ? $headers['Name'] : $file,
					'new'    => isset( $data->new_version ) ? $data->new_version : null,
				);
			}
		}

		$theme_list = array();
		if ( is_object( $theme_tr ) && ! empty( $theme_tr->response ) ) {
			foreach ( (array) $theme_tr->response as $stylesheet => $data ) {
				$theme_list[] = array(
					'stylesheet' => $stylesheet,
					'name'       => wp_get_theme( $stylesheet )->get( 'Name' ),
					'new'        => isset( $data['new_version'] ) ? $data['new_version'] : null,
				);
			}
		}

		$core_available = false;
		$core_version   = null;
		if ( is_object( $core_tr ) && ! empty( $core_tr->updates ) && isset( $core_tr->updates[0]->response ) && 'upgrade' === $core_tr->updates[0]->response ) {
			$core_available = true;
			$core_version   = isset( $core_tr->updates[0]->current ) ? $core_tr->updates[0]->current : null;
		}

		return array(
			'core'    => array(
				'update_available' => $core_available,
				'new_version'      => $core_version,
			),
			'plugins' => $plugin_list,
			'themes'  => $theme_list,
		);
	}
}
