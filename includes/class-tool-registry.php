<?php
/**
 * Tool registry – stores tool definitions.
 *
 * A tool definition is an associative array:
 *   - title       (string)  Human title.
 *   - description (string)  What the tool does (shown to the AI client).
 *   - inputSchema (array)   JSON Schema object describing arguments.
 *   - capability  (string)  Required WordPress capability, or null.
 *   - dangerous   (bool)    Requires Safe-Mode allow-listing.
 *   - callback    (callable) function( array $args ): array|string|WP_Error.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Tool_Registry
 */
class AB_MCP_Tool_Registry {

	/**
	 * Registered tools keyed by name.
	 *
	 * @var array<string,array>
	 */
	private $tools = array();

	/**
	 * Current functional group stamped onto tools as they are registered.
	 *
	 * @var array{slug:string,label:string}
	 */
	private $current_group = array(
		'slug'  => 'other',
		'label' => 'Sonstiges',
	);

	/**
	 * Set the functional group for subsequently registered tools. The loader
	 * calls this before each tool class registers, so every tool is tagged with
	 * a human group (e.g. "Posts & Pages") without touching each definition.
	 *
	 * @param string $slug  Group slug.
	 * @param string $label Human label.
	 */
	public function set_current_group( $slug, $label ) {
		$this->current_group = array(
			'slug'  => (string) $slug,
			'label' => (string) $label,
		);
	}

	/**
	 * Register a tool.
	 *
	 * @param string $name Tool name (snake_case, stable API).
	 * @param array  $def  Definition (see class docblock).
	 */
	public function register( $name, array $def ) {
		$def = wp_parse_args(
			$def,
			array(
				'title'       => $name,
				'description' => '',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => (object) array(),
				),
				'capability'  => null,
				'dangerous'   => false,
				'readonly'    => null,
				'callback'    => null,
				'group'       => '',
				'group_label' => '',
			)
		);

		if ( '' === $def['group'] ) {
			$def['group']       = $this->current_group['slug'];
			$def['group_label'] = $this->current_group['label'];
		}

		$this->tools[ $name ] = $def;
	}

	/**
	 * Central read-only classification. A definition may force the flag with
	 * 'readonly' => true|false; otherwise the (stable, snake_case) tool name
	 * decides. Drives the MCP readOnlyHint annotation, the global read-only mode
	 * AND the "read" token scope, so keep this list in sync when new tools are
	 * added — a new writing tool that is missed here would be wrongly allowed for
	 * read-scoped tokens. Unknown names default to "not read-only" (fail-closed).
	 *
	 * @param string $name Tool name.
	 * @param array  $def  Tool definition.
	 * @return bool
	 */
	public static function is_read_only( $name, array $def ) {
		if ( isset( $def['readonly'] ) && null !== $def['readonly'] ) {
			return (bool) $def['readonly'];
		}
		foreach ( array( 'wp_list_', 'wp_get_', 'wp_read_', 'wp_wc_list_', 'wp_wc_get_' ) as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}
		$reads = array(
			'wp_search',
			'wp_db_query',
			'wp_db_tables',
			'wp_db_schema',
			'wp_site_info',
			'wp_site_health',
			'wp_dashboard_counts',
			'wp_deploy_list',
			'wp_deploy_read',
			'wp_deploy_test',
			'wp_network_list_sites',
			'wp_seo_detect',
			'wp_seo_get',
		);
		return in_array( $name, $reads, true );
	}

	/**
	 * Capabilities that count as "content work" — the everyday editorial powers.
	 * Deliberately an allow-list: a tool whose capability is not in here (an
	 * administrative one such as manage_options or install_plugins, or none at
	 * all) is NOT content-level, so a content-scoped token cannot run it. New
	 * tools therefore start out excluded rather than silently included.
	 *
	 * @return string[]
	 */
	public static function content_capabilities() {
		return array(
			'read',
			'edit_posts',
			'edit_others_posts',
			'edit_published_posts',
			'edit_private_posts',
			'publish_posts',
			'delete_posts',
			'delete_others_posts',
			'delete_published_posts',
			'edit_pages',
			'edit_others_pages',
			'publish_pages',
			'delete_pages',
			'upload_files',
			'manage_categories',
			'moderate_comments',
		);
	}

	/**
	 * The scopes a token can carry, narrowest first.
	 *
	 * @return array Slug => human label.
	 */
	public static function scopes() {
		return array(
			'read'    => __( 'Read only — can look at everything the user may see, changes nothing.', 'alphabridge-mcp' ),
			'content' => __( 'Content — read, plus writing posts, pages, media, terms and comments.', 'alphabridge-mcp' ),
			'full'    => __( 'Full — everything the mapped user is allowed to do, including administrative tools.', 'alphabridge-mcp' ),
		);
	}

	/**
	 * May a token with this scope run this tool?
	 *
	 * The scope narrows what a token can do WITHIN what its user may do — it
	 * never widens it. An empty/unknown scope means "full", so tokens created
	 * before scopes existed keep working exactly as before.
	 *
	 * Both narrower scopes are fail-closed: "read" reuses the same read
	 * classification the global read-only mode uses (an unrecognised tool counts
	 * as writing), and "content" additionally needs the tool's capability to be
	 * an explicitly content-level one.
	 *
	 * @param string $scope Token scope.
	 * @param string $name  Tool name.
	 * @param array  $def   Tool definition.
	 * @return bool
	 */
	public static function scope_allows( $scope, $name, array $def ) {
		$scope = (string) $scope;
		if ( '' === $scope || 'full' === $scope ) {
			return true;
		}
		if ( self::is_read_only( $name, $def ) ) {
			return true; // Reading is included in every scope.
		}
		if ( 'read' === $scope ) {
			return false;
		}
		if ( 'content' === $scope ) {
			$cap = isset( $def['capability'] ) ? (string) $def['capability'] : '';
			return '' !== $cap && in_array( $cap, self::content_capabilities(), true );
		}
		return false; // Unknown scope: deny.
	}

	/**
	 * Does a tool interact with systems beyond this WordPress installation
	 * (remote downloads, FTP/SFTP)? Drives the MCP openWorldHint annotation.
	 *
	 * @param string $name Tool name.
	 * @param array  $def  Tool definition.
	 * @return bool
	 */
	public static function is_open_world( $name, array $def ) {
		unset( $def );
		if ( 0 === strpos( $name, 'wp_deploy_' ) ) {
			return true;
		}
		$networked = array(
			'wp_install_plugin',
			'wp_install_theme',
			'wp_update_plugin',
			'wp_update_theme',
			'wp_update_core',
			'wp_upload_media_from_url',
		);
		return in_array( $name, $networked, true );
	}

	/**
	 * Get one tool definition.
	 *
	 * @param string $name Tool name.
	 * @return array|null
	 */
	public function get( $name ) {
		return isset( $this->tools[ $name ] ) ? $this->tools[ $name ] : null;
	}

	/**
	 * All tools.
	 *
	 * @return array<string,array>
	 */
	public function all() {
		return $this->tools;
	}

	/**
	 * Count registered tools.
	 *
	 * @return int
	 */
	public function count() {
		return count( $this->tools );
	}

	/**
	 * Aggregate tools into their functional groups, in first-seen order.
	 * A group is flagged "mighty" when it holds at least one dangerous tool.
	 *
	 * @return array<string,array{label:string,mighty:bool,tools:string[],count:int}>
	 */
	public function groups() {
		$groups = array();
		foreach ( $this->tools as $name => $def ) {
			$slug = isset( $def['group'] ) && '' !== $def['group'] ? $def['group'] : 'other';
			if ( ! isset( $groups[ $slug ] ) ) {
				$groups[ $slug ] = array(
					'label'  => isset( $def['group_label'] ) && '' !== $def['group_label'] ? $def['group_label'] : $slug,
					'mighty' => false,
					'tools'  => array(),
					'count'  => 0,
				);
			}
			$groups[ $slug ]['tools'][] = $name;
			$groups[ $slug ]['count']++;
			if ( ! empty( $def['dangerous'] ) ) {
				$groups[ $slug ]['mighty'] = true;
			}
		}
		return $groups;
	}
}
