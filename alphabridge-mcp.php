<?php
/**
 * Plugin Name:       AlphaBridge MCP
 * Plugin URI:        https://www.alphabridge-mcp.com
 * Description:       Connect Claude and other MCP clients directly and securely to WordPress. Native Streamable-HTTP MCP server — fast, stable, with tool-group switches.
 * Version:           4.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            AlphaBridge
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       alphabridge-mcp
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

// Idempotent load guard. The Pro add-on can bundle a verbatim copy of this core
// and load it when this free plugin is not active. If the admin then activates
// this plugin, WordPress includes this file while those classes already exist —
// which would redeclare AB_MCP_Plugin and fatal. If the core is already loaded
// (by us on an earlier request, or by the Pro bundle), this file is a no-op and
// the already-loaded copy keeps serving; a later request with this plugin
// loading first uses this copy normally.
if ( defined( 'AB_MCP_VERSION' ) || class_exists( 'AB_MCP_Plugin', false ) ) {
	return;
}

define( 'AB_MCP_VERSION', '4.2.0' );
define( 'AB_MCP_FILE', __FILE__ );
define( 'AB_MCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'AB_MCP_URL', plugin_dir_url( __FILE__ ) );
define( 'AB_MCP_BASENAME', plugin_basename( __FILE__ ) );

// REST endpoint: https://your-site.tld/wp-json/alphabridge/v1/mcp
define( 'AB_MCP_REST_NAMESPACE', 'alphabridge/v1' );
define( 'AB_MCP_REST_ROUTE', '/mcp' );

// Latest MCP protocol version we speak. initialize() echoes the client's
// requested version when it is one we support, so older clients keep working.
define( 'AB_MCP_PROTOCOL_VERSION', '2025-06-18' );

// Maximum number of JSON-RPC messages accepted in a single batch request.
if ( ! defined( 'AB_MCP_MAX_BATCH' ) ) {
	define( 'AB_MCP_MAX_BATCH', 25 );
}

// array_is_list() (PHP 8.1+) is polyfilled by WordPress core since 6.5 —
// covered by "Requires at least: 6.5".

require_once AB_MCP_DIR . 'includes/class-tool-registry.php';
require_once AB_MCP_DIR . 'includes/class-settings.php';
require_once AB_MCP_DIR . 'includes/class-security.php';
require_once AB_MCP_DIR . 'includes/class-audit-log.php';
require_once AB_MCP_DIR . 'includes/class-auth.php';
require_once AB_MCP_DIR . 'includes/class-oauth.php';
require_once AB_MCP_DIR . 'includes/class-rest-controller.php';
require_once AB_MCP_DIR . 'includes/class-admin.php';
require_once AB_MCP_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'AB_MCP_Plugin', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'AB_MCP_Plugin', 'on_deactivate' ) );

/**
 * Boot the plugin on init so translated tool-group labels are loaded no earlier
 * than WordPress is ready for them (avoids the _load_textdomain_just_in_time
 * notice on WP 6.7+). The REST route (rest_api_init) and the admin screen
 * (admin_menu) both hook actions that fire after init, so init is early enough.
 */
add_action(
	'init',
	static function () {
		AB_MCP_Plugin::instance();
	}
);
