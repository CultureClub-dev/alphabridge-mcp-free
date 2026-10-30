<?php
/**
 * What the confirmation token covers for the five tools without a way back:
 * wp_update_core, wp_install_plugin_zip, wp_install_theme_zip,
 * wp_delete_plugin, wp_delete_theme.
 *
 * For each tool a state (a fingerprint of what the call would change, so
 * a token from an earlier preview fails once that changed) and a preview
 * (what would happen, in words and figures, for the person to see before
 * the second call). Neither changes anything; the tool itself still checks
 * everything it checks today and refuses as it does today.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * State and preview of the lifecycle tools that need confirmation.
 */
final class AB_MCP_Lifecycle_Confirm {

	/**
	 * The `confirm` entry for a tool definition.
	 *
	 * @param string $tool Tool name.
	 * @return array{state:callable,preview:callable}
	 */
	public static function definition( $tool ) {
		switch ( $tool ) {
			case 'wp_update_core':
				return array(
					'state'   => array( __CLASS__, 'core_state' ),
					'preview' => array( __CLASS__, 'core_preview' ),
				);
			case 'wp_install_plugin_zip':
				return array(
					'state'   => static fn( array $a ) => self::zip_state( 'plugin', $a ),
					'preview' => static fn( array $a ) => self::zip_preview( 'plugin', $a ),
				);
			case 'wp_install_theme_zip':
				return array(
					'state'   => static fn( array $a ) => self::zip_state( 'theme', $a ),
					'preview' => static fn( array $a ) => self::zip_preview( 'theme', $a ),
				);
			case 'wp_delete_plugin':
				return array(
					'state'   => array( __CLASS__, 'plugin_state' ),
					'preview' => array( __CLASS__, 'plugin_preview' ),
				);
			case 'wp_delete_theme':
				return array(
					'state'   => array( __CLASS__, 'theme_state' ),
					'preview' => array( __CLASS__, 'theme_preview' ),
				);
		}
		return array();
	}

	/* ------------------------------------------------------------ core */

	/**
	 * The running version and the update WordPress would install now. The
	 * tool asks wordpress.org again before it updates; asking here too
	 * means a release that appears between preview and confirmation makes
	 * the token fail instead of installing a version nobody saw.
	 *
	 * @return string
	 */
	public static function core_state() {
		$offer = self::core_offer( true );
		return 'core|' . self::wp_version() . '|' . ( $offer ? $offer['version'] . '|' . $offer['locale'] : 'none' );
	}

	/**
	 * @return array
	 */
	public static function core_preview() {
		$offer = self::core_offer( false );
		if ( ! $offer ) {
			return array(
				'action' => __( 'Update WordPress itself', 'alphabridge-mcp' ),
				'from'   => self::wp_version(),
				'to'     => null,
				'effect' => __( 'WordPress offers no update right now, so running it would change nothing.', 'alphabridge-mcp' ),
			);
		}
		return array(
			'action'     => __( 'Update WordPress itself', 'alphabridge-mcp' ),
			'from'       => self::wp_version(),
			'to'         => $offer['version'],
			'locale'     => $offer['locale'],
			'effect'     => __( 'Replaces the WordPress core files with the new version and updates the database if the new version needs it.', 'alphabridge-mcp' ),
			'no_way_back' => __( 'Core updates have no test requests and no automatic rollback here, and undo does not cover them. A backup made first is the way back.', 'alphabridge-mcp' ),
		);
	}

	/**
	 * The first offer of get_core_updates() when it is an upgrade.
	 *
	 * @param bool $ask_again Ask wordpress.org first (as the tool does).
	 * @return array{version:string,locale:string}|null
	 */
	private static function core_offer( $ask_again ) {
		if ( ! function_exists( 'get_core_updates' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/update.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( $ask_again && function_exists( 'wp_version_check' ) ) {
			wp_version_check();
		}
		$updates = function_exists( 'get_core_updates' ) ? get_core_updates() : array();
		if ( empty( $updates ) || ! isset( $updates[0] ) || 'upgrade' !== ( $updates[0]->response ?? '' ) ) {
			return null;
		}
		return array(
			'version' => (string) ( $updates[0]->current ?? '' ),
			'locale'  => (string) ( $updates[0]->locale ?? '' ),
		);
	}

	/**
	 * @return string
	 */
	private static function wp_version() {
		return (string) get_bloginfo( 'version' );
	}

	/* -------------------------------------------------------------- zip */

	/**
	 * Where the package comes from (the content itself, hashed; the address;
	 * or the upload), whether it is activated, and what is installed now: a
	 * folder that appears or changes in between makes the token fail.
	 *
	 * @param string $kind plugin|theme.
	 * @param array  $a    Arguments.
	 * @return string
	 */
	public static function zip_state( $kind, array $a ) {
		return 'zip|' . $kind . '|' . self::source( $a )['key'] . '|' . ( empty( $a['activate'] ) ? '0' : '1' ) . '|' . self::inventory( $kind );
	}

	/**
	 * @param string $kind plugin|theme.
	 * @param array  $a    Arguments.
	 * @return array
	 */
	public static function zip_preview( $kind, array $a ) {
		$source = self::source( $a );
		return array(
			'action'      => 'plugin' === $kind ? __( 'Install a plugin from a ZIP package', 'alphabridge-mcp' ) : __( 'Install a theme from a ZIP package', 'alphabridge-mcp' ),
			'source'      => $source['shown'],
			'activate'    => ! empty( $a['activate'] ),
			'effect'      => 'plugin' === $kind
				? __( 'Unpacks the package into wp-content/plugins. Its code runs with this site’s rights as soon as it is active.', 'alphabridge-mcp' )
				: __( 'Unpacks the package into wp-content/themes. Its code runs with this site’s rights as soon as it is active.', 'alphabridge-mcp' ),
			'no_way_back' => 'plugin' === $kind
				? __( 'Undo does not cover installs. Deleting the plugin removes its folder (wp_delete_plugin); what its code wrote into the database stays.', 'alphabridge-mcp' )
				: __( 'Undo does not cover installs. Deleting the theme removes its folder (wp_delete_theme); what its code wrote into the database stays.', 'alphabridge-mcp' ),
		);
	}

	/**
	 * The package source, for the fingerprint and for the preview.
	 *
	 * @param array $a Arguments.
	 * @return array{key:string,shown:array}
	 */
	private static function source( array $a ) {
		if ( isset( $a['content'] ) && is_string( $a['content'] ) && '' !== $a['content'] ) {
			$hash = hash( 'sha256', $a['content'] );
			return array(
				'key'   => 'content:' . $hash,
				'shown' => array(
					'kind'          => 'content',
					'base64_length' => strlen( $a['content'] ),
					'sha256'        => $hash,
				),
			);
		}
		if ( isset( $a['url'] ) && is_string( $a['url'] ) && '' !== $a['url'] ) {
			return array(
				'key'   => 'url:' . $a['url'],
				'shown' => array(
					'kind' => 'url',
					'url'  => $a['url'],
					'note' => __( 'The package is downloaded when the call runs; the token covers the address, not what the address serves.', 'alphabridge-mcp' ),
				),
			);
		}
		if ( isset( $a['session'] ) && is_scalar( $a['session'] ) && '' !== (string) $a['session'] ) {
			return array(
				'key'   => 'session:' . (string) $a['session'],
				'shown' => array(
					'kind'    => 'session',
					'session' => (string) $a['session'],
				),
			);
		}
		return array(
			'key'   => 'none',
			'shown' => array( 'kind' => 'none' ),
		);
	}

	/**
	 * What is installed: folder or file name and version of every plugin or
	 * theme, sorted.
	 *
	 * @param string $kind plugin|theme.
	 * @return string sha256.
	 */
	private static function inventory( $kind ) {
		$list = array();
		if ( 'plugin' === $kind ) {
			foreach ( self::plugins() as $file => $data ) {
				$list[] = $file . '=' . ( $data['Version'] ?? '' );
			}
		} else {
			foreach ( self::themes() as $slug => $theme ) {
				$list[] = $slug . '=' . ( is_object( $theme ) && method_exists( $theme, 'get' ) ? (string) $theme->get( 'Version' ) : '' );
			}
		}
		sort( $list, SORT_STRING );
		return hash( 'sha256', implode( "\n", $list ) );
	}

	/* ----------------------------------------------------------- delete */

	/**
	 * Whether the plugin is there, its version, and whether it is active
	 * here or for the network.
	 *
	 * @param array $a Arguments.
	 * @return string
	 */
	public static function plugin_state( array $a ) {
		$plugin  = isset( $a['plugin'] ) ? (string) $a['plugin'] : '';
		$plugins = self::plugins();
		if ( ! isset( $plugins[ $plugin ] ) ) {
			return 'plugin|' . $plugin . '|absent';
		}
		return 'plugin|' . $plugin . '|' . ( $plugins[ $plugin ]['Version'] ?? '' ) . '|' . ( self::plugin_active( $plugin ) ? 'active' : 'inactive' );
	}

	/**
	 * @param array $a Arguments.
	 * @return array
	 */
	public static function plugin_preview( array $a ) {
		$plugin  = isset( $a['plugin'] ) ? (string) $a['plugin'] : '';
		$plugins = self::plugins();
		if ( ! isset( $plugins[ $plugin ] ) ) {
			return array(
				'action' => __( 'Delete a plugin', 'alphabridge-mcp' ),
				'plugin' => $plugin,
				'effect' => __( 'No installed plugin has this identifier, so the call would be refused; wp_list_plugins lists the identifiers.', 'alphabridge-mcp' ),
			);
		}
		return array(
			'action'      => __( 'Delete a plugin', 'alphabridge-mcp' ),
			'plugin'      => $plugin,
			'name'        => (string) ( $plugins[ $plugin ]['Name'] ?? '' ),
			'version'     => (string) ( $plugins[ $plugin ]['Version'] ?? '' ),
			'active'      => self::plugin_active( $plugin ),
			'effect'      => self::plugin_active( $plugin )
				? __( 'Deactivates the plugin, then deletes its folder with all its files.', 'alphabridge-mcp' )
				: __( 'Deletes the plugin’s folder with all its files.', 'alphabridge-mcp' ),
			'no_way_back' => __( 'Undo does not cover deletes, and the files are gone. What the plugin stored in the database stays. A plugin from wordpress.org comes back with wp_install_plugin, without changes made to its files.', 'alphabridge-mcp' ),
		);
	}

	/**
	 * Whether the theme is there, its version, and whether it is the active
	 * theme or its parent (the tool refuses those).
	 *
	 * @param array $a Arguments.
	 * @return string
	 */
	public static function theme_state( array $a ) {
		$slug   = isset( $a['stylesheet'] ) ? (string) $a['stylesheet'] : '';
		$themes = self::themes();
		if ( '' === $slug || ! isset( $themes[ $slug ] ) ) {
			return 'theme|' . $slug . '|absent';
		}
		return 'theme|' . $slug . '|' . (string) $themes[ $slug ]->get( 'Version' ) . '|' . self::theme_role( $slug );
	}

	/**
	 * @param array $a Arguments.
	 * @return array
	 */
	public static function theme_preview( array $a ) {
		$slug   = isset( $a['stylesheet'] ) ? (string) $a['stylesheet'] : '';
		$themes = self::themes();
		if ( '' === $slug || ! isset( $themes[ $slug ] ) ) {
			return array(
				'action'     => __( 'Delete a theme', 'alphabridge-mcp' ),
				'stylesheet' => $slug,
				'effect'     => __( 'No installed theme has this folder name, so the call would be refused; wp_list_themes lists the folder names.', 'alphabridge-mcp' ),
			);
		}
		$role = self::theme_role( $slug );
		return array(
			'action'      => __( 'Delete a theme', 'alphabridge-mcp' ),
			'stylesheet'  => $slug,
			'name'        => (string) $themes[ $slug ]->get( 'Name' ),
			'version'     => (string) $themes[ $slug ]->get( 'Version' ),
			'role'        => $role,
			'effect'      => 'other' === $role
				? __( 'Deletes the theme’s folder with all its files.', 'alphabridge-mcp' )
				: __( 'This is the active theme or its parent, so the call would be refused; activate another theme first (wp_activate_theme).', 'alphabridge-mcp' ),
			'no_way_back' => __( 'Undo does not cover deletes, and the files are gone. A theme from wordpress.org comes back with wp_install_theme, without changes made to its files.', 'alphabridge-mcp' ),
		);
	}

	/**
	 * active, parent or other.
	 *
	 * @param string $slug Theme folder.
	 * @return string
	 */
	private static function theme_role( $slug ) {
		if ( get_stylesheet() === $slug ) {
			return 'active';
		}
		return get_template() === $slug ? 'parent' : 'other';
	}

	/* ---------------------------------------------------------- readers */

	/**
	 * @return array<string,array>
	 */
	private static function plugins() {
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return function_exists( 'get_plugins' ) ? (array) get_plugins() : array();
	}

	/**
	 * Installed themes, broken ones included (the tool deletes those too).
	 *
	 * @return array<string,WP_Theme>
	 */
	private static function themes() {
		return (array) wp_get_themes( array( 'errors' => null ) );
	}

	/**
	 * @param string $plugin Plugin file.
	 * @return bool
	 */
	private static function plugin_active( $plugin ) {
		if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return ( function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin ) )
			|| ( function_exists( 'is_plugin_active_for_network' ) && is_multisite() && is_plugin_active_for_network( $plugin ) );
	}
}
