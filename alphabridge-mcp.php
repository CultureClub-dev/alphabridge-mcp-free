<?php
/**
 * Plugin Name:       AlphaBridge MCP
 * Plugin URI:        https://www.alphabridge-mcp.com
 * Description:       Connect Claude and other MCP clients directly and securely to WordPress. Native Streamable-HTTP MCP server with tool-group switches.
 * Version:           4.10.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            AlphaBridge
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       alphabridge-mcp
 * Domain Path:       /languages
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

define( 'AB_MCP_VERSION', '4.10.0' );
define( 'AB_MCP_FILE', __FILE__ );
define( 'AB_MCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'AB_MCP_URL', plugin_dir_url( __FILE__ ) );
define( 'AB_MCP_BASENAME', plugin_basename( __FILE__ ) );

// REST endpoint: https://your-site.tld/wp-json/alphabridge/v1/mcp
define( 'AB_MCP_REST_NAMESPACE', 'alphabridge/v1' );
define( 'AB_MCP_REST_ROUTE', '/mcp' );

// Newest MCP revision with an initialize handshake. initialize() echoes the
// client's requested version when it is one we support, so older clients keep
// working, and answers anything else with this one. Revision 2026-07-28 has no
// handshake and is served per request (see AB_MCP_REST_Controller).
define( 'AB_MCP_PROTOCOL_VERSION', '2025-06-18' );

// Maximum number of JSON-RPC messages accepted in a single batch request.
if ( ! defined( 'AB_MCP_MAX_BATCH' ) ) {
	define( 'AB_MCP_MAX_BATCH', 25 );
}

// array_is_list() (PHP 8.1+) is polyfilled by WordPress core since 6.5 —
// covered by "Requires at least: 6.5".

require_once AB_MCP_DIR . 'includes/class-tool-registry.php';
require_once AB_MCP_DIR . 'includes/class-settings.php';
require_once AB_MCP_DIR . 'includes/class-site-mode.php';
require_once AB_MCP_DIR . 'includes/class-guidance.php';
require_once AB_MCP_DIR . 'includes/class-security.php';
require_once AB_MCP_DIR . 'includes/class-confirm-token.php';
require_once AB_MCP_DIR . 'includes/class-lifecycle-confirm.php';
require_once AB_MCP_DIR . 'includes/class-audit-log.php';
// Undo (free since 4.9.0, before in Pro only): the store, its restore, and
// the post rules and page caches both of them use.
require_once AB_MCP_DIR . 'includes/class-page-cache.php';
// The guard on option names, which the restore of option rows asks again (4.10.0).
require_once AB_MCP_DIR . 'includes/class-option-guard.php';
require_once AB_MCP_DIR . 'includes/class-post-status.php';
require_once AB_MCP_DIR . 'includes/undo/class-undo.php';
require_once AB_MCP_DIR . 'includes/undo/class-undo-restore.php';
require_once AB_MCP_DIR . 'includes/class-review-notice.php';
require_once AB_MCP_DIR . 'includes/class-auth.php';
require_once AB_MCP_DIR . 'includes/class-oauth.php';
require_once AB_MCP_DIR . 'includes/class-rest-controller.php';
// The connection check: the settings card and the tool wp_connection_doctor.
require_once AB_MCP_DIR . 'includes/class-connection-doctor.php';
// Page builders: recognition and reading; defines AB_MCP_BUILDER_API, which Pro checks.
require_once AB_MCP_DIR . 'includes/builders/class-builders.php';
require_once AB_MCP_DIR . 'includes/class-tool-labels.php';

// The update and activation tools (free since 4.11.0, before in Pro only):
// what runs on every request, not only with the tools, hooked as Pro hooks
// it. The option, cron and probe names are Pro's, so Free and Pro share them.
//
// The log of the update tools and the versions they took back: the hold has
// to answer WordPress's background updates too. Late, so that a filter
// switching every automatic update on does not install a version this site
// already took back.
require_once AB_MCP_DIR . 'includes/lifecycle/class-update-log.php';
add_filter( 'auto_update_plugin', array( 'AB_MCP_Update_Log', 'auto_update_plugin' ), 100, 2 );
add_filter( 'auto_update_theme', array( 'AB_MCP_Update_Log', 'auto_update_theme' ), 100, 2 );
// On a network whose main site runs without this plugin, a hold took the
// package off WordPress's list of automatic updates; a WP-Cron event of this
// site, planned while such a hold is open, puts it back once a newer version
// is installed.
add_action( AB_MCP_Update_Log::RESUME, array( 'AB_MCP_Update_Log', 'resume_auto_updates' ), 10, 0 );
add_action( 'init', array( 'AB_MCP_Update_Log', 'keep_resume_scheduled' ), 20, 0 );
// A test request of the activation check that asks what it loaded (the
// plugins and the theme after every filter) gets it printed at shutdown. It
// does nothing in a request without the test key and the question.
require_once AB_MCP_DIR . 'includes/lifecycle/class-probe-state.php';
AB_MCP_Probe_State::boot();
// A theme switch the test requests took back: a request that loaded its
// options while it was pending must not map menus and widgets from that
// out-of-date copy once it reaches init.
require_once AB_MCP_DIR . 'includes/lifecycle/class-theme-switch-state.php';
AB_MCP_Theme_Switch_State::hook();
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
