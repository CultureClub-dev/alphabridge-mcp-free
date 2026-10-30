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
		// An update in place never runs the activation hook; bring the options
		// up to date here, before anything reads them.
		AB_MCP_Settings::maybe_upgrade();
		self::load_bundled_translations();
		// switch_to_locale() drops every loaded translation and reloads only
		// the language pack; add the bundled file again for the new locale.
		add_action( 'change_locale', array( __CLASS__, 'load_bundled_translations' ) );

		$this->registry = new AB_MCP_Tool_Registry();
		$this->load_tools();

		add_action( 'rest_api_init', array( $this, 'register_rest' ) );

		// OAuth sign-in: the OAuth surface (well-known metadata, consent
		// page, register/token endpoints) that lets Claude's native "Connect"
		// button work against this site.
		AB_MCP_OAuth::boot();

		if ( is_admin() ) {
			new AB_MCP_Admin( $this->registry );
		}
	}

	/**
	 * Load the translations this plugin ships in languages/, behind the
	 * language pack from translate.wordpress.org.
	 *
	 * WordPress loads a plugin's language pack by itself, when the plugin
	 * first translates a string. The files in languages/ carry every text of
	 * the plugin a person reads (the settings page, the consent screen, the
	 * refusals and errors an assistant passes on, the description in the
	 * plugin list), so that a German site reads them in German from the
	 * release that brings them. Asking WordPress for the
	 * domain first lets it load the language pack where there is one; the
	 * bundled file is added after it and so only answers what the pack does
	 * not have yet. Where there is no pack, the bundled file is all there is.
	 *
	 * Runs on init, when the plugin boots: translations must not load before.
	 * Bundled are de_DE, de_DE_formal, de_AT, de_CH, de_CH_informal, fr_FR,
	 * it_IT, es_ES and pt_PT. A locale without a file of its own reads the
	 * main file of its language (BUNDLED_FALLBACK): fr_CA and fr_BE read
	 * fr_FR, es_MX es_ES, pt_BR pt_PT. Without that a Canadian or Mexican
	 * site would read English although its language is there.
	 *
	 * @param string $locale Locale to load; the current one when empty (the
	 *                       change_locale action passes the new one).
	 * @return bool Whether a bundled file was loaded.
	 */
	public static function load_bundled_translations( $locale = '' ) {
		$locale = is_string( $locale ) && '' !== $locale ? $locale : determine_locale();
		$file   = self::bundled_file( $locale );
		if ( '' === $file ) {
			return false;
		}
		get_translations_for_domain( 'alphabridge-mcp' );
		return (bool) load_textdomain( 'alphabridge-mcp', $file, $locale );
	}

	/**
	 * The main locale of each language with bundled files: it answers for a
	 * locale of that language that has no file of its own.
	 */
	const BUNDLED_FALLBACK = array(
		'de' => 'de_DE',
		'fr' => 'fr_FR',
		'it' => 'it_IT',
		'es' => 'es_ES',
		'pt' => 'pt_PT',
	);

	/**
	 * The bundled .mo for a locale: its own file, else the main file of its
	 * language, else '' (nothing bundled). The locale goes into a path, so
	 * only the form WordPress uses for locales is accepted (de_CH_informal,
	 * pt_PT_ao90, fr).
	 *
	 * @param string $locale Locale.
	 * @return string Path of a readable file, or ''.
	 */
	private static function bundled_file( $locale ) {
		if ( ! preg_match( '/\A([a-z]{2,3})(?:_[A-Za-z0-9]+)*\z/', $locale, $parts ) ) {
			return '';
		}
		$own = AB_MCP_DIR . 'languages/alphabridge-mcp-' . $locale . '.mo';
		if ( is_readable( $own ) ) {
			return $own;
		}
		if ( ! isset( self::BUNDLED_FALLBACK[ $parts[1] ] ) ) {
			return '';
		}
		$main = AB_MCP_DIR . 'languages/alphabridge-mcp-' . self::BUNDLED_FALLBACK[ $parts[1] ] . '.mo';
		return is_readable( $main ) ? $main : '';
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
			'builders',
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
			'menus-users',
			'plugins-themes-list',
			'shop',
			'undo-core',
			'builder-write',
			'search-replace',
			'install-update',
			'plugins-themes-manage',
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
			// The page-builder outline is a way of reading posts and pages.
			'AB_MCP_Tools_Builders'          => array( 'content', __( 'Posts & Pages', 'alphabridge-mcp' ) ),
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
			// Free since 4.7.0, before in Pro only; same slugs and names as Pro's
			// groups, so Pro's further tools of these areas join them.
			'AB_MCP_Tools_Menus_Users'         => array( 'users', __( 'Users & Menus', 'alphabridge-mcp' ) ),
			'AB_MCP_Tools_Plugins_Themes_List' => array( 'plugins-themes', __( 'Plugins & Themes', 'alphabridge-mcp' ) ),
			'AB_MCP_Tools_Shop'                => array( 'woocommerce', __( 'WooCommerce', 'alphabridge-mcp' ) ),
			// Free since 4.9.0, the group as in Pro.
			'AB_MCP_Tools_Undo_Core'           => array( 'undo', __( 'Undo', 'alphabridge-mcp' ) ),
			// Free since 4.10.0, the groups as in Pro.
			'AB_MCP_Tools_Builder_Write'       => array( 'builders', __( 'Page builders', 'alphabridge-mcp' ) ),
			'AB_MCP_Tools_Search_Replace'      => array( 'search', __( 'Search & Bulk Actions', 'alphabridge-mcp' ) ),
			// Free since 4.11.0, the groups as in Pro: installing, updating
			// and deleting, and switching plugins and themes on and off.
			'AB_MCP_Tools_Install_Update'        => array( 'lifecycle', __( 'Install & Update', 'alphabridge-mcp' ) ),
			'AB_MCP_Tools_Plugins_Themes_Manage' => array( 'plugins-themes', __( 'Plugins & Themes', 'alphabridge-mcp' ) ),
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
