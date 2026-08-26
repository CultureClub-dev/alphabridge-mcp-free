<?php
/**
 * Main plugin bootstrap / service container.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Plugin
 */
final class AB_MCP_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var AB_MCP_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Tool registry.
	 *
	 * @var AB_MCP_Tool_Registry
	 */
	public $registry;

	/**
	 * Get the singleton.
	 *
	 * @return AB_MCP_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		// Translations load automatically since WP 4.6 (wordpress.org language
		// packs) and, for the bundled .mo files, via the standard languages
		// path — no load_plugin_textdomain() call needed.
		$this->registry = new AB_MCP_Tool_Registry();
		$this->load_tools();

		add_action( 'rest_api_init', array( $this, 'register_rest' ) );

		// AlphaBridge Connect: the OAuth surface (well-known metadata, consent
		// page, register/token endpoints) that lets Claude's native "Connect"
		// button work against this site.
		AB_MCP_OAuth::boot();

		if ( is_admin() ) {
			new AB_MCP_Admin();
		}
	}

	/**
	 * Register the REST route that carries the MCP protocol.
	 */
	public function register_rest() {
		$controller = new AB_MCP_REST_Controller( $this->registry );
		$controller->register_routes();
	}

	/**
	 * Load all tool category files and register their tools.
	 */
	private function load_tools() {
		$files = array(
			'content',
			'media',
			'taxonomy-comments',
			'widgets',
			'options-settings',
			'system',
			'search-bulk',
			'lifecycle',
			'seo',
			'meta-auth',
			'ops',
		);

		foreach ( $files as $file ) {
			$path = AB_MCP_DIR . 'includes/tools/class-tools-' . $file . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}

		// Class => functional group [ slug, human label ]. The registry stamps the
		// group onto every tool the class registers. Add-ons register additional
		// tools into these and further groups via the ab_mcp_register_tools hook.
		$classes = array(
			'AB_MCP_Tools_Content'           => array( 'content', __( 'Posts & Pages', 'alphabridge-mcp' ) ),
			'AB_MCP_Tools_Media'             => array( 'media', __( 'Media', 'alphabridge-mcp' ) ),
			'AB_MCP_Tools_Taxonomy_Comments' => array( 'taxonomy', __( 'Taxonomies & Comments', 'alphabridge-mcp' ) ),
			'AB_MCP_Tools_Widgets'           => array( 'widgets', __( 'Widgets', 'alphabridge-mcp' ) ),
			'AB_MCP_Tools_Options_Settings'  => array( 'options', __( 'Options & Settings', 'alphabridge-mcp' ) ),
			'AB_MCP_Tools_System'            => array( 'system', __( 'System & Maintenance', 'alphabridge-mcp' ) ),
			'AB_MCP_Tools_Search_Bulk'       => array( 'search', __( 'Search & Bulk Actions', 'alphabridge-mcp' ) ),
			'AB_MCP_Tools_Lifecycle'         => array( 'lifecycle', __( 'Install & Update', 'alphabridge-mcp' ) ),
			'AB_MCP_Tools_Seo'               => array( 'seo', __( 'SEO', 'alphabridge-mcp' ) ),
			'AB_MCP_Tools_Meta_Auth'         => array( 'meta-auth', __( 'Meta & App Passwords', 'alphabridge-mcp' ) ),
			'AB_MCP_Tools_Ops'               => array( 'ops', __( 'Operations (Cron, Transients, Health)', 'alphabridge-mcp' ) ),
		);

		foreach ( $classes as $class => $group ) {
			if ( class_exists( $class ) && method_exists( $class, 'register' ) ) {
				$this->registry->set_current_group( $group[0], $group[1] );
				call_user_func( array( $class, 'register' ), $this->registry );
			}
		}
		$this->registry->set_current_group( 'other', __( 'Other', 'alphabridge-mcp' ) );

		/**
		 * Allow add-ons to register additional tools.
		 *
		 * @param AB_MCP_Tool_Registry $registry Registry instance.
		 */
		do_action( 'ab_mcp_register_tools', $this->registry );
	}

	/**
	 * Activation: create defaults + audit table.
	 */
	public static function on_activate() {
		require_once AB_MCP_DIR . 'includes/class-settings.php';
		require_once AB_MCP_DIR . 'includes/class-audit-log.php';
		AB_MCP_Settings::install_defaults();
		AB_MCP_Audit_Log::install_table();
	}

	/**
	 * Deactivation hook (kept minimal – no data loss).
	 */
	public static function on_deactivate() {
		// Intentionally empty: keep tokens/settings on deactivate.
	}
}
